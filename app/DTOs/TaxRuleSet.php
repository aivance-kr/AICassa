<?php

namespace App\DTOs;

/**
 * 특정 귀속연도에 적용되는 스칼라 세법 파라미터 묶음.
 *
 * 세법 개정 시 연도별로 달라질 수 있는 상수(부가세 제수·비망가액·정률법 잔존가액 제수)를
 * 하드코딩 대신 시행연도별 룰셋으로 관리하기 위한 읽기 전용 DTO.
 *
 * @see \App\Services\TaxRuleResolver 시행연도 as-of 해석기
 * @see \Config\TaxRules             연도별 룰셋 정의
 */
final readonly class TaxRuleSet
{
    /**
     * 소액자산 즉시비용(즉시상각) 기본 한도(원) — 룰셋에 값이 없을 때 폴백.
     */
    public const DEFAULT_LOW_VALUE_THRESHOLD = 1_000_000;

    /**
     * @param int $effectiveYear            이 룰셋의 시행연도(요청 귀속연도가 아니라 실제 적용된 기준연도)
     * @param int $vatDivisor               부가세 제수(10 => 공급가액의 1/10 = 10%)
     * @param int $memorandumValue          비망가액(장부상 남기는 최소 가액, 원)
     * @param int $decliningResidualDivisor 정률법 잔존가액 제수(20 => 취득금액의 1/20 = 5%)
     * @param int $lowValueAssetThreshold   소액자산 즉시비용 한도(원, 거래단위 기준). 이하이면 즉시상각 대상 후보
     */
    public function __construct(
        public int $effectiveYear,
        public int $vatDivisor,
        public int $memorandumValue,
        public int $decliningResidualDivisor,
        public int $lowValueAssetThreshold = self::DEFAULT_LOW_VALUE_THRESHOLD,
    ) {
    }

    /**
     * Config\TaxRules 의 연도별 배열 항목으로부터 DTO를 생성한다.
     * low_value_asset_threshold 는 선택 키로, 없으면 기본 한도로 폴백한다(기존 시드 DB 호환).
     *
     * @param array{vat_divisor:int, memorandum_value:int, declining_residual_divisor:int, low_value_asset_threshold?:int} $params
     */
    public static function fromArray(int $effectiveYear, array $params): self
    {
        return new self(
            effectiveYear: $effectiveYear,
            vatDivisor: (int) $params['vat_divisor'],
            memorandumValue: (int) $params['memorandum_value'],
            decliningResidualDivisor: (int) $params['declining_residual_divisor'],
            lowValueAssetThreshold: (int) ($params['low_value_asset_threshold'] ?? self::DEFAULT_LOW_VALUE_THRESHOLD),
        );
    }
}
