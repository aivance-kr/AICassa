<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Home extends BaseController
{
    /**
     * 랜딩 페이지 — 서비스 소개 + 로그인/회원가입.
     * 이미 로그인한 사용자는 관리자 홈으로 보낸다.
     */
    public function index(): RedirectResponse|string
    {
        if (auth()->loggedIn()) {
            return redirect()->to('/admin/businesses');
        }

        return view('home');
    }
}
