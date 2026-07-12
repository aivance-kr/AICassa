<?php

namespace App\Enums;

/**
 * 신고 전 이상탐지 경고 심각도.
 */
enum AnomalySeverity: string
{
    case High    = 'high';    // 위험 — 신고 전 반드시 확인
    case Warning = 'warning'; // 주의 — 소명·검토 권고
    case Info    = 'info';    // 참고 — 참고용 신호

    public function label(): string
    {
        return match ($this) {
            self::High    => '위험',
            self::Warning => '주의',
            self::Info    => '참고',
        };
    }

    /**
     * 정렬 가중치(높을수록 먼저).
     */
    public function weight(): int
    {
        return match ($this) {
            self::High    => 3,
            self::Warning => 2,
            self::Info    => 1,
        };
    }
}
