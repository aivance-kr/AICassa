<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * AI 엔드포인트 레이트 리밋 설정.
 *
 * 유료 외부 LLM(Anthropic) 호출을 유발하는 Admin 엔드포인트에 사용자 단위 스로틀을 건다.
 * 다중 테넌트(SaaS)에서 한 계정이 외부 API 비용·지연을 무제한 유발하는 것을 막는다.
 *
 * 기본값은 코드에 두고, 운영에서는 `.env` 의 `ai.rateLimit.*` 키로 무배포 조정한다.
 */
class AiThrottle extends BaseConfig
{
    /**
     * 레이트 리밋 강제 여부(킬 스위치). false 면 전 구간 미적용.
     */
    public bool $enabled = true;

    /**
     * 창(seconds) 당 허용 요청 수(사용자 단위).
     */
    public int $capacity = 20;

    /**
     * 레이트 리밋 창 길이(초).
     */
    public int $seconds = 60;

    public function __construct()
    {
        parent::__construct();

        // .env 오버라이드 — 값이 있으면 코드 기본값보다 우선한다(키: ai.rateLimit.*).
        $enabled = env('ai.rateLimit.enabled');
        if ($enabled !== null && $enabled !== '') {
            $this->enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        $capacity = (int) env('ai.rateLimit.capacity', 0);
        if ($capacity > 0) {
            $this->capacity = $capacity;
        }

        $seconds = (int) env('ai.rateLimit.seconds', 0);
        if ($seconds > 0) {
            $this->seconds = $seconds;
        }
    }
}
