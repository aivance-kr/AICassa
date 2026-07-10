<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\Models\TaxParameterModel;
use App\Services\TaxParameterService;
use App\Services\TaxRuleResolver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * TaxRuleResolver 가 운영자 관리 DB(tax_parameters)를 소스로 룰셋을 해석하는지 검증한다.
 * (Config\TaxRules 폴백이 아닌 DB 값 사용 + as-of + 운영자 변경 반영)
 *
 * @internal
 */
final class TaxRuleResolverDbTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ReferenceDataSeeder::class;
    protected $namespace;

    private function resolver(): TaxRuleResolver
    {
        // Config 는 기본값, params 는 DB — DB 우선 경로 검증
        return new TaxRuleResolver(null, model(TaxParameterModel::class));
    }

    /**
     * DB 시드값(2023 기준선)을 그대로 룰셋으로 해석한다.
     */
    public function testResolvesBaselineFromDb(): void
    {
        $rule = $this->resolver()->forYear(2023);

        $this->assertSame(2023, $rule->effectiveYear);
        $this->assertSame(10, $rule->vatDivisor);
        $this->assertSame(1000, $rule->memorandumValue);
        $this->assertSame(20, $rule->decliningResidualDivisor);
    }

    /**
     * as-of — 미등록 이후 연도는 직전 시행연도(2023) 룰셋을 상속한다.
     */
    public function testAppliesMostRecentEffectiveYear(): void
    {
        $this->assertSame(2023, $this->resolver()->forYear(2030)->effectiveYear);
    }

    /**
     * 운영자가 새 시행연도를 추가·수정하면 그 연도부터 새 값이 적용된다.
     */
    public function testOperatorChangeFlowsIntoResolution(): void
    {
        $params = new TaxParameterService();
        $params->createYear(2025);                          // 2023 값 복제
        $params->bulkUpsert(2025, ['vat_divisor' => '11']); // 2025부터 부가세 제수 변경

        // 새 리졸버 인스턴스(요청 단위 캐시 회피)로 확인
        $this->assertSame(11, $this->resolver()->forYear(2025)->vatDivisor);
        $this->assertSame(2025, $this->resolver()->forYear(2025)->effectiveYear);
        // 2024 는 여전히 2023 기준선
        $this->assertSame(10, $this->resolver()->forYear(2024)->vatDivisor);
    }
}
