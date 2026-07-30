<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;
use Throwable;

/**
 * Anthropic Messages API 호출 공통 라이브러리(텍스트·Vision).
 *
 * 외부 라이브러리 없이 HMAC 없는 단순 REST 호출만 담당한다. 엔드포인트·버전·헤더·상태판정·
 * JSON 파싱·에러 로깅을 한 곳(sendMessages)으로 모아 텍스트/Vision 호출이 공유한다.
 * 호출·응답 파싱 실패는 RuntimeException 으로 전환하며, 호출측이 폴백을 결정한다.
 */
final class AnthropicClient
{
    private const API_ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION     = '2023-06-01';
    private const DEFAULT_MODEL   = 'claude-sonnet-5';
    private const DEFAULT_TIMEOUT = 15; // 초
    private const VISION_TIMEOUT  = 25; // 초. Vision 은 텍스트보다 느릴 수 있어 기본 타임아웃을 늘린다.

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
        return $this->sendMessages(
            [['type' => 'text', 'text' => $prompt]],
            $maxTokens,
            $this->timeout,
        );
    }

    /**
     * 이미지(base64) + 프롬프트를 보내는 Vision 호출. 모델의 텍스트 출력을 반환한다.
     *
     * Vision 은 텍스트보다 느릴 수 있어 타임아웃 기본값을 25초로 둔다(호출측에서 조정 가능).
     *
     * @param string $imageBase64 base64 인코딩된 이미지 바이트
     * @param string $mediaType   image/jpeg·image/png·image/webp 등
     *
     * @throws RuntimeException 호출·응답 파싱 실패
     */
    public function completeVision(
        string $imageBase64,
        string $mediaType,
        string $prompt,
        int $maxTokens = 1024,
        int $timeout = self::VISION_TIMEOUT,
    ): string {
        return $this->sendMessages(
            [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mediaType,
                        'data'       => $imageBase64,
                    ],
                ],
                ['type' => 'text', 'text' => $prompt],
            ],
            $maxTokens,
            $timeout,
        );
    }

    /**
     * Messages API 를 호출하고 첫 텍스트 블록을 반환하는 공통 로직.
     * 엔드포인트·버전·헤더·상태판정·JSON 파싱·에러 로깅을 이 한 곳에 모은다.
     *
     * @param list<array<string, mixed>> $content user 메시지의 content 블록 배열
     *
     * @throws RuntimeException 호출·응답 파싱 실패
     */
    private function sendMessages(array $content, int $maxTokens, int $timeout): string
    {
        try {
            $response = $this->http->request('POST', self::API_ENDPOINT, [
                'timeout'     => $timeout,
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
                        'content' => $content,
                    ]],
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Anthropic 호출 실패: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('AI 서버에 연결하지 못했습니다.', 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            log_message('error', 'Anthropic 응답 오류 {code}: {body}', [
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

        $this->recordUsage(
            (int) ($body['usage']['input_tokens'] ?? 0),
            (int) ($body['usage']['output_tokens'] ?? 0),
        );

        return $text;
    }

    /**
     * 실제 외부 호출 1건분 사용량을 월간 예산 집계 서비스에 넘긴다.
     *
     * 로그인 사용자가 없으면(비정상 흐름·순수 단위 테스트 등) 기록할 대상이 없으므로 건너뛴다.
     * 폴백(이력·규칙 등)은 이 메서드에 도달하지 않으므로 실제 호출만 카운트된다.
     * 집계는 부가 기능이므로 실패해도 이미 받은 AI 응답(텍스트)을 막지 않는다(로깅 후 계속 진행).
     */
    private function recordUsage(int $inputTokens, int $outputTokens): void
    {
        try {
            $userId = auth()->id();
            if ($userId === null) {
                return;
            }

            service('aiUsageService')->record((int) $userId, $inputTokens, $outputTokens);
        } catch (Throwable $e) {
            log_message('error', 'AI 사용량 기록 실패: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
