<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\AiThrottle;

/**
 * AI(유료 외부 LLM 호출) 엔드포인트 레이트 리밋 필터.
 *
 * 로그인 사용자(테넌시 스코프) 단위로 CodeIgniter Throttler 토큰버킷을 적용해
 * 한 계정이 외부 Anthropic API 비용·지연을 무제한 유발하는 것을 막는다.
 *
 * 폴백 비차단 정책(요구사항):
 *   모든 대상 엔드포인트는 `ANTHROPIC_API_KEY` 가 있을 때만 외부 호출을 한다(없으면 이력·규칙·검색 폴백).
 *   따라서 키가 없으면 외부 비용이 발생하지 않으므로 레이트 리밋을 적용하지 않는다.
 *   (키가 있을 때는 히스토리 캐시로 외부 호출을 피하는 요청도 카운트되나, 이는 안전측 보수 동작이며
 *    임계치를 넉넉히 두어 정상 사용을 방해하지 않는다.)
 *
 * 응답 형식:
 *   - POST(AJAX 어시스트): 기존 에러 JSON 포맷 + 재생성 CSRF 토큰 동봉, HTTP 429.
 *   - GET(자연어 검색 등 페이지 내비게이션): 이전 페이지로 플래시 에러와 함께 리다이렉트.
 */
class AiRateLimitFilter implements FilterInterface
{
    private const ERROR_MESSAGE = 'AI 요청이 너무 잦습니다. 잠시 후 다시 시도하세요.';

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config(AiThrottle::class);

        // 킬 스위치 OFF 또는 AI 미설정(외부 호출 불가) → 레이트 리밋 미적용(폴백 비차단).
        if (! $config->enabled || (string) env('ANTHROPIC_API_KEY', '') === '') {
            return;
        }

        $throttler = service('throttler');

        // 정상 흐름은 session 필터가 선행하므로 로그인 사용자 ID 가 있다. 예외적 미인증은 IP 로 폴백.
        $userId = auth()->id();
        $scope  = $userId !== null ? 'u' . $userId : 'ip' . $request->getIPAddress();
        // 캐시 키 예약문자({}()/\@:) 회피 — 콜론(IPv6 IP 포함) 등은 '-' 로 치환한다.
        $key = 'ai-rate-' . (string) preg_replace('/[^A-Za-z0-9_]+/', '-', $scope);

        if ($throttler->check($key, $config->capacity, $config->seconds) === false) {
            return $this->rejected($request, $throttler->getTokenTime());
        }
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        // 사후 처리 없음.
        return null;
    }

    /**
     * 레이트 리밋 초과 응답을 요청 형식에 맞춰 만든다.
     */
    private function rejected(RequestInterface $request, int $retryAfter): ResponseInterface
    {
        // GET(페이지 내비게이션)은 JSON 대신 이전 페이지로 플래시 에러 리다이렉트가 자연스럽다.
        if (strtoupper($request->getMethod()) === 'GET') {
            return redirect()->back()->with('error', self::ERROR_MESSAGE);
        }

        // AJAX 어시스트(POST): 기존 에러 JSON 포맷 유지 + CSRF 재생성 토큰 동봉(폼 hidden 갱신용).
        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, $retryAfter))
            ->setJSON([
                'csrf_name' => csrf_token(),
                'csrf_hash' => csrf_hash(),
                'error'     => ['code' => 'RATE_LIMITED', 'message' => self::ERROR_MESSAGE],
            ]);
    }
}
