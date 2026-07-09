<?php

namespace App\Services;

use App\Enums\EvidenceType;

/**
 * 부가세 자동계산.
 *
 * 원본 프로그램(장부입력.frm) 규칙:
 *  - 계산서·간이영수증·기타(면세 증빙) → 부가세 0
 *  - 세금계산서·신용카드·현금영수증(과세 증빙) → 공급가액 × 10% (원 단위 절사)
 *
 * VBA는 `Int(txt금액 * 0.1)` 로 소수점을 버린다. 부동소수 오차를 피하기 위해
 * 정수 연산(intdiv)으로 동일 결과를 낸다.
 */
final class VatCalculatorService
{
    private const VAT_DIVISOR = 10; // 공급가액의 1/10 = 10%

    /**
     * 공급가액과 증빙유형으로 부가세를 계산한다.
     *
     * @param int          $supplyAmount 공급가액(원). 음수(자산 매각 등)면 크기 기준으로 계산.
     * @param EvidenceType $evidence     증빙유형
     *
     * @return int 부가세(원, 절사)
     */
    public function calculate(int $supplyAmount, EvidenceType $evidence): int
    {
        if (! $evidence->isVatable()) {
            return 0;
        }

        // 절사 방향을 크기(절댓값) 기준으로 맞춰 VBA Int() 와 일치시킨다.
        $vat = intdiv(abs($supplyAmount), self::VAT_DIVISOR);

        return $supplyAmount < 0 ? -$vat : $vat;
    }
}
