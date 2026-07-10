<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;
use Throwable;

/**
 * Anthropic Messages API 텍스트 호출 공통 라이브러리.
 *
 * 외부 라이브러리 없이 HMAC 없는 단순 REST 호출만 담당한다(Vision 은 ReceiptOcrService 가 직접 구성).
 * 호출·응답 파싱 실패는 RuntimeException 으로 전환하며, 호출측이 폴백을 결정한다.
 */
final class AnthropicClient
{
    private const API_ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION     = '2023-06-01';
    private const DEFAULT_MODEL   = 'claude-sonnet-5';
    private const DEFAULT_TIMEOUT = 15; // 초

    public function __construct(
        private readonly CURLRequest $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

    /**
     * env 기반 생성. API 키가 없으면 null 을 반환한다(= AI 기능 비활성).
     */
    public static function fromEnv(?CURLRequest $http = null): ?self
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            return null;
        }

        return new self(
            $http ?? service('curlrequest'),
            $apiKey,
            (string) (env('ANTHROPIC_MODEL') ?: self::DEFAULT_MODEL),
            self::DEFAULT_TIMEOUT,
        );
    }

    /**
     * 텍스트 프롬프트를 보내고 모델의 텍스트 출력을 반환한다.
     *
     * @throws RuntimeException 호출·응답 파싱 실패
     */
    public function completeText(string $prompt, int $maxTokens = 256): string
    {
        try {
            $response = $this->http->request('POST', self::API_ENDPOINT, [
                'timeout'     => $this->timeout,
                'http_errors' => false, // 상태코드를 직접 판정한다.
                'headers'     => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => $this->model,
                    'max_tokens' => $maxTokens,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [['type' => 'text', 'text' => $prompt]],
                    ]],
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Anthropic 텍스트 호출 실패: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('AI 서버에 연결하지 못했습니다.', 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            log_message('error', 'Anthropic 텍스트 응답 오류 {code}: {body}', [
                'code' => $response->getStatusCode(),
                'body' => substr((string) $response->getBody(), 0, 500),
            ]);

            throw new RuntimeException('AI 응답 오류(외부 서비스).');
        }

        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $response->getBody(), true);
        $text = $body['content'][0]['text'] ?? null;
        if (! is_string($text)) {
            throw new RuntimeException('AI 응답 형식이 올바르지 않습니다.');
        }

        return $text;
    }
}
