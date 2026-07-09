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
 *
 * 부가세 제수(세율)는 귀속연도별 세법 룰셋(TaxRuleSet)에서 가져온다.
 * 세율이 개정되면 Config\TaxRules 에 시행연도 항목만 추가하면 된다.
 */
final class VatCalculatorService
{
    private TaxRuleResolver $rules;

    public function __construct(?TaxRuleResolver $rules = null)
    {
        $this->rules = $rules ?? service('taxRuleResolver');
    }

    /**
     * 공급가액과 증빙유형으로 부가세를 계산한다.
     *
     * @param int          $supplyAmount 공급가액(원). 음수(자산 매각 등)면 크기 기준으로 계산.
     * @param EvidenceType $evidence     증빙유형
     * @param int          $fiscalYear   귀속연도(적용할 세율 룰셋 결정)
     *
     * @return int 부가세(원, 절사)
     */
    public function calculate(int $supplyAmount, EvidenceType $evidence, int $fiscalYear): int
    {
        if (! $evidence->isVatable()) {
            return 0;
        }

        $divisor = $this->rules->forYear($fiscalYear)->vatDivisor;

        // 절사 방향을 크기(절댓값) 기준으로 맞춰 VBA Int() 와 일치시킨다.
        $vat = intdiv(abs($supplyAmount), $divisor);

        return $supplyAmount < 0 ? -$vat : $vat;
    }
}
