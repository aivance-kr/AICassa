<?php

use App\DTOs\TaxRuleSet;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TaxRuleSet 소액자산 한도 — 선택 키 파싱과 기본값 폴백.
 *
 * @internal
 */
final class TaxRuleSetThresholdTest extends CIUnitTestCase
{
    public function testUsesProvidedThreshold(): void
    {
        $set = TaxRuleSet::fromArray(2023, [
            'vat_divisor'                => 10,
            'memorandum_value'           => 1000,
            'declining_residual_divisor' => 20,
            'low_value_asset_threshold'  => 2000000,
        ]);

        $this->assertSame(2000000, $set->lowValueAssetThreshold);
    }

    public function testFallsBackToDefaultWhenKeyMissing(): void
    {
        // 기존 시드 DB(3개 필수 키만)와의 호환 — 키가 없으면 기본 한도.
        $set = TaxRuleSet::fromArray(2023, [
            'vat_divisor'                => 10,
            'memorandum_value'           => 1000,
            'declining_residual_divisor' => 20,
        ]);

        $this->assertSame(TaxRuleSet::DEFAULT_LOW_VALUE_THRESHOLD, $set->lowValueAssetThreshold);
        $this->assertSame(1_000_000, $set->lowValueAssetThreshold);
    }
}
