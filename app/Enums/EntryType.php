<?php

namespace App\Enums;

/**
 * 장부 전표(거래) 구분.
 */
enum EntryType: string
{
    case Income        = 'income';         // 수입
    case Expense       = 'expense';        // 비용
    case AssetPurchase = 'asset_purchase'; // 자산_구입
    case AssetDisposal = 'asset_disposal'; // 자산_매각 (공급가액 음수 기록)

    public function label(): string
    {
        return match ($this) {
            self::Income        => '수입',
            self::Expense       => '비용',
            self::AssetPurchase => '자산_구입',
            self::AssetDisposal => '자산_매각',
        };
    }
}
