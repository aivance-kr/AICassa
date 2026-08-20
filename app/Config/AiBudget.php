<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * AI 엔드포인트 월간 예산 상한 설정.
 *
 * 레이트 리밋(분 단위·캐시)은 짧은 창의 폭주만 막을 뿐, 임계치 이하로 꾸준히 호출하는
 * 장기간 누적 비용은 막지 못한다. 이 설정은 사용자 단위 월간 호출 수/토큰 사용량에 영속 상한을 건다.
 *
 * 기본값은 코드에 두고, 운영에서는 `.env` 의 `ai.monthlyCap.*` 키로 무배포 조정한다.
 */
class AiBudget extends BaseConfig
{
    /**
     * 월간 상한 강제 여부(킬 스위치). false 면 전 구간 미적용.
     */
    public bool $enabled = true;

    /**
     * 월간 허용 호출 수(사용자 단위). 0 이면 무제한.
     */
    public int $monthlyCallCap = 1000;

    /**
     * 월간 허용 토큰 수(입력+출력 합, 사용자 단위). 0 이면 무제한.
     */
    public int $monthlyTokenCap = 1_000_000;

    public function __construct()
    {
        parent::__construct();

        // .env 오버라이드 — 값이 있으면 코드 기본값보다 우선한다(키: ai.monthlyCap.*).
        $enabled = env('ai.monthlyCap.enabled');
        if ($enabled !== null && $enabled !== '') {
            $this->enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        $callCap = env('ai.monthlyCap.calls');
        if ($callCap !== null && $callCap !== '') {
            $this->monthlyCallCap = (int) $callCap;
        }

        $tokenCap = env('ai.monthlyCap.tokens');
        if ($tokenCap !== null && $tokenCap !== '') {
            $this->monthlyTokenCap = (int) $tokenCap;
        }
    }
}
