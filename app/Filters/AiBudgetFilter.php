<?php

namespace App\Filters;

use App\Services\AiUsageService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\AiBudget;

/**
 * AI(유료 외부 LLM 호출) 엔드포인트 월간 예산 상한 필터.
 *
 * {@see AiRateLimitFilter} 가 분 단위 폭주를 막는다면, 이 필터는 월 경계를 넘겨 누적되는
 * 장기 비용을 막는다. 이번 호출 "이전"까지의 영속 누적치(호출 수·토큰 수)로 판정하므로
 * 두 방어선은 독립적으로 함께 적용된다.
 *
 * 폴백 비차단 정책은 레이트 리밋과 동일하다: `ANTHROPIC_API_KEY` 가 없으면 외부 호출 자체가
 * 없으므로 상한을 적용하지 않는다.
 *
 * 응답 형식은 레이트 리밋과 동일 포맷을 재사용한다:
 *   - POST(AJAX 어시스트): 에러 JSON + 재생성 CSRF 토큰 동봉, HTTP 429.
 *   - GET(자연어 검색 등 페이지 내비게이션): 이전 페이지로 플래시 에러와 함께 리다이렉트.
 */
class AiBudgetFilter implements FilterInterface
{
    private const ERROR_MESSAGE = '이번 달 AI 사용 한도를 초과했습니다. 다음 달에 다시 시도하거나 관리자에게 문의하세요.';

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config(AiBudget::class);

        // 킬 스위치 OFF 또는 AI 미설정(외부 호출 불가) → 상한 미적용(폴백 비차단).
        if (! $config->enabled || (string) env('ANTHROPIC_API_KEY', '') === '') {
            return;
        }

        $userId = auth()->id();
        // 미인증 요청은 상한 판정 대상이 아니다(정상 흐름은 session 필터가 선행해 항상 인증됨).
        if ($userId === null) {
            return;
        }

        /** @var AiUsageService $usage */
        $usage = service('aiUsageService');

        if ($usage->exceeds((int) $userId, $config)) {
            return $this->rejected($request);
        }
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        // 사후 처리 없음(실제 사용량 기록은 AnthropicClient 호출 직후 이뤄진다).
        return null;
    }

    /**
     * 월간 상한 초과 응답을 요청 형식에 맞춰 만든다.
     */
    private function rejected(RequestInterface $request): ResponseInterface
    {
        // GET(페이지 내비게이션)은 JSON 대신 이전 페이지로 플래시 에러 리다이렉트가 자연스럽다.
        if (strtoupper($request->getMethod()) === 'GET') {
            return redirect()->back()->with('error', self::ERROR_MESSAGE);
        }

        // AJAX 어시스트(POST): 레이트 리밋과 동일 에러 JSON 포맷 유지 + CSRF 재생성 토큰 동봉.
        return service('response')
            ->setStatusCode(429)
            ->setJSON([
                'csrf_name' => csrf_token(),
                'csrf_hash' => csrf_hash(),
                'error'     => ['code' => 'MONTHLY_CAP_EXCEEDED', 'message' => self::ERROR_MESSAGE],
            ]);
    }
}
