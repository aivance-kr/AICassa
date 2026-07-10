<?php

namespace App\Controllers\Operator;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * 운영자 로그인/로그아웃.
 *
 * 자격증명은 .env(operator.id / operator.password)에서 읽는다.
 * 비밀번호는 bcrypt 해시($2y$…) 이면 password_verify, 아니면 평문 상수시간 비교.
 */
final class AuthController extends BaseOperatorController
{
    /**
     * 로그인 폼. 이미 인증됐으면 목록으로.
     */
    public function showLogin(): RedirectResponse|string
    {
        if (session()->get('operator_authed') === true) {
            return redirect()->to('/operator/tax-parameters');
        }

        return view('operator/login');
    }

    /**
     * 로그인 처리 — .env 자격증명 검증.
     */
    public function login(): RedirectResponse
    {
        $inputId = trim((string) $this->request->getPost('operator_id'));
        $inputPw = (string) $this->request->getPost('operator_password');

        $envId = (string) env('operator.id', '');
        $envPw = (string) env('operator.password', '');

        if ($envId === '' || $envPw === '') {
            return redirect()->back()
                ->with('error', '운영자 자격증명이 서버에 설정되어 있지 않습니다(.env).');
        }

        if (! $this->credentialsValid($inputId, $inputPw, $envId, $envPw)) {
            return redirect()->back()->withInput()
                ->with('error', '아이디 또는 비밀번호가 올바르지 않습니다.');
        }

        // 세션 고정(fixation) 방어 — 인증 상태 변경 시 세션 ID 재발급
        session()->regenerate();
        session()->set([
            'operator_authed' => true,
            'operator_id'     => $inputId,
        ]);

        return redirect()->to('/operator/tax-parameters')
            ->with('message', '운영자로 로그인했습니다.');
    }

    /**
     * 로그아웃 — 운영자 세션만 정리.
     */
    public function logout(): RedirectResponse
    {
        session()->remove(['operator_authed', 'operator_id']);

        return redirect()->to('/operator/login')
            ->with('message', '로그아웃되었습니다.');
    }

    /**
     * 아이디·비밀번호 검증(상수시간 비교로 타이밍 공격 완화).
     */
    private function credentialsValid(string $inputId, string $inputPw, string $envId, string $envPw): bool
    {
        $idOk = hash_equals($envId, $inputId);

        // bcrypt/argon 해시 형식이면 password_verify, 그 외에는 평문 상수시간 비교
        $pwOk = str_starts_with($envPw, '$2y$') || str_starts_with($envPw, '$argon')
            ? password_verify($inputPw, $envPw)
            : hash_equals($envPw, $inputPw);

        return $idOk && $pwOk;
    }
}
