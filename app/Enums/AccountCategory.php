<?php

namespace App\Enums;

/**
 * 계정과목 대분류.
 */
enum AccountCategory: string
{
    case Income  = 'income';  // 수입
    case Expense = 'expense'; // 비용
    case Asset   = 'asset';   // 사업용 유·무형자산

    public function label(): string
    {
        return match ($this) {
            self::Income  => '수입',
            self::Expense => '비용',
            self::Asset   => '사업용 자산',
        };
    }
}
