<?php

namespace App\Enums;

/**
 * 세법 파라미터 분류. 운영자 상세/수정 화면의 그룹 헤더로 사용한다.
 */
enum TaxParameterCategory: string
{
    case Vat          = 'vat';          // 부가가치세
    case Depreciation = 'depreciation'; // 감가상각
    case IncomeTax    = 'income_tax';   // 소득세

    public function label(): string
    {
        return match ($this) {
            self::Vat          => '부가가치세',
            self::Depreciation => '감가상각',
            self::IncomeTax    => '소득세',
        };
    }
}
