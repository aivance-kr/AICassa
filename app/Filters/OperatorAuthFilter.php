<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 운영자(세법 파라미터 관리) 인증 필터.
 *
 * 기존 사업장 Admin(Shield 세션)과 완전 분리된 별도 인증 영역이다.
 * .env 의 운영자 자격증명으로 로그인하면 세션 플래그가 설정되며,
 * 미인증 상태로 /operator 하위에 접근하면 로그인 페이지로 돌려보낸다.
 */
class OperatorAuthFilter implements FilterInterface
{
    /**
     * 세션에 운영자 인증 플래그가 없으면 로그인으로 리다이렉트.
     *
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session()->get('operator_authed') !== true) {
            return redirect()->to('/operator/login')
                ->with('error', '운영자 로그인이 필요합니다.');
        }
    }

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|null
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // 사후 처리 없음
        return null;
    }
}
