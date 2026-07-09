<?php

use App\Services\TaxRuleResolver;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TaxRules;

/**
 * 시행연도 기준 세법 룰셋 as-of 해석 검증.
 *
 * @internal
 */
final class TaxRuleResolverTest extends CIUnitTestCase
{
    private function resolver(): TaxRuleResolver
    {
        $config       = new TaxRules();
        $config->sets = [
            2023 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
            2027 => ['vat_divisor' => 5, 'memorandum_value' => 2000, 'declining_residual_divisor' => 10],
        ];

        return new TaxRuleResolver($config);
    }

    /**
     * 요청 연도 이하 중 가장 최근 시행연도의 룰셋을 선택한다.
     */
    public function testResolvesMostRecentEffectiveYear(): void
    {
        $this->assertSame(2023, $this->resolver()->forYear(2023)->effectiveYear);
        $this->assertSame(2023, $this->resolver()->forYear(2026)->effectiveYear);
        $this->assertSame(2027, $this->resolver()->forYear(2027)->effectiveYear);
        $this->assertSame(2027, $this->resolver()->forYear(2030)->effectiveYear);
    }

    /**
     * 선택된 시행연도의 스칼라 값이 그대로 반영된다.
     */
    public function testCarriesScalarValues(): void
    {
        $rule = $this->resolver()->forYear(2027);
        $this->assertSame(5, $rule->vatDivisor);
        $this->assertSame(2000, $rule->memorandumValue);
        $this->assertSame(10, $rule->decliningResidualDivisor);
    }

    /**
     * 최초 시행연도 이전 연도는 가장 이른 룰셋으로 폴백한다.
     */
    public function testFallsBackToEarliestForOlderYears(): void
    {
        $this->assertSame(2023, $this->resolver()->forYear(2019)->effectiveYear);
    }

    /**
     * 기본 Config(기준선 2023)는 어느 연도든 2023 룰셋을 반환한다.
     */
    public function testDefaultConfigUsesBaseline(): void
    {
        $resolver = new TaxRuleResolver(new TaxRules());
        $this->assertSame(2023, $resolver->forYear(2026)->effectiveYear);
        $this->assertSame(10, $resolver->forYear(2026)->vatDivisor);
    }
}
