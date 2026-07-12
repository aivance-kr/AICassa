<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 시행연도 기준 스칼라 세법 파라미터 정책.
 *
 * 세법은 매년 개정되며, 개정 내용은 원칙적으로 개정 이후 개시하는 과세연도(귀속분)부터
 * 적용된다. 부가세율·비망가액·정률법 잔존율 같은 스칼라 상수를 연도별 룰셋으로 관리해
 * 과거 귀속연도 계산을 그대로 재현하고, 개정 시 항목만 추가하면 되도록 한다.
 *
 * 조회 규칙(as-of): 귀속연도 Y에 대해 `시행연도 <= Y` 중 가장 최근 룰셋을 적용한다.
 * 따라서 값이 바뀌지 않은 연도는 별도 항목 없이 직전 룰셋을 그대로 상속한다.
 *
 * 개정 세법이 확정되면: 해당 시행연도 키를 추가한다(기존 값 덮어쓰기 금지).
 *   예) 2027 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20]
 *
 * @see \App\Services\TaxRuleResolver
 */
class TaxRules extends BaseConfig
{
    /**
     * 시행연도 => 스칼라 세법 파라미터.
     *
     * - vat_divisor:                부가세 제수(10 => 10%)
     * - memorandum_value:           비망가액(원)
     * - declining_residual_divisor: 정률법 잔존가액 제수(20 => 취득금액의 5%)
     * - low_value_asset_threshold:  소액자산 즉시비용 한도(원, 거래단위) — 개인사업자 100만원
     *
     * 기준선(2023)은 국세청 간편장부 프로그램 v3.4 원본 규칙과 동일하다.
     *
     * @var array<int, array{vat_divisor:int, memorandum_value:int, declining_residual_divisor:int, low_value_asset_threshold:int}>
     */
    public array $sets = [
        2023 => [
            'vat_divisor'                => 10,
            'memorandum_value'           => 1000,
            'declining_residual_divisor' => 20,
            'low_value_asset_threshold'  => 1_000_000,
        ],
    ];
}
