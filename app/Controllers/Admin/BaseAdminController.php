<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Admin(세션 인증) 컨트롤러 공통 기반.
 * render() 로 공통 데이터(로그인 사용자)를 자동 병합한다.
 */
abstract class BaseAdminController extends BaseController
{
    /** @var list<string> */
    protected $helpers = ['form', 'url'];

    /**
     * 현재 로그인 사용자 ID(테넌시 스코프 기준).
     */
    protected function authUserId(): int
    {
        return (int) auth()->id();
    }

    /**
     * 공통 데이터를 병합해 Admin 뷰를 렌더링한다.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $shared = [
            'authUser' => auth()->user(),
        ];

        return view($view, array_merge($shared, $data));
    }
}
