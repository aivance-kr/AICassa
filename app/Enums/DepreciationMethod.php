<?php

namespace App\Enums;

/**
 * 감가상각 방법.
 */
enum DepreciationMethod: string
{
    case StraightLine     = 'straight_line';     // 정액법 — 취득금액 기준
    case DecliningBalance = 'declining_balance';  // 정률법 — 직전장부가액 기준

    public function label(): string
    {
        return match ($this) {
            self::StraightLine     => '정액법',
            self::DecliningBalance => '정률법',
        };
    }
}
