<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 공개 운영자 로그인 엔드포인트의 온라인 비밀번호 추측을 제한한다.
 */
final class OperatorLoginRateLimitFilter implements FilterInterface
{
    private const IP_CAPACITY      = 10;
    private const ACCOUNT_CAPACITY = 5;
    private const WINDOW_SECONDS   = 900;

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $throttler  = service('throttler');
        $ipKey      = 'operator-login-ip-' . hash('sha256', $request->getIPAddress());
        $operatorId = $request instanceof IncomingRequest || $request instanceof CLIRequest
            ? mb_strtolower(trim((string) $request->getPost('operator_id')))
            : '';
        $accountKey = 'operator-login-account-' . hash('sha256', $operatorId);

        if ($throttler->check($ipKey, self::IP_CAPACITY, self::WINDOW_SECONDS)
            && $throttler->check($accountKey, self::ACCOUNT_CAPACITY, self::WINDOW_SECONDS)) {
            return;
        }

        return redirect()->back()
            ->with('error', '로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요.');
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
