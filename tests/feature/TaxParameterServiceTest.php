<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\TaxParameterService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 연도별 세법 파라미터 운영자 서비스 — 목록/상세, 일괄 수정, 새 연도 복제.
 *
 * @internal
 */
final class TaxParameterServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ReferenceDataSeeder::class;
    protected $namespace;
    private TaxParameterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaxParameterService();
    }

    /**
     * 특정 연도 파라미터에서 키로 값 조회(테스트 헬퍼).
     */
    private function valueOf(int $year, string $key): ?string
    {
        foreach ($this->service->paramsForYear($year) as $row) {
            if ((string) $row['param_key'] === $key) {
                return (string) $row['param_value'];
            }
        }

        return null;
    }

    /**
     * 시드된 기준연도(2023)가 목록에 나온다.
     */
    public function testRegisteredYearsListsBaseline(): void
    {
        $years = array_column($this->service->registeredYears(), 'fiscal_year');
        $this->assertContains(2023, $years);
    }

    /**
     * 상세는 해당 연도 전체 파라미터를 반환한다.
     */
    public function testParamsForYearReturnsRuleAndTables(): void
    {
        $this->assertSame('10', $this->valueOf(2023, 'vat_divisor'));
        $this->assertSame('1000', $this->valueOf(2023, 'memorandum_value'));
        $this->assertSame('20', $this->valueOf(2023, 'declining_residual_divisor'));
    }

    /**
     * 미등록 연도 상세 조회는 NotFound.
     */
    public function testParamsForYearUnknownThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->paramsForYear(1990);
    }

    /**
     * 일괄 수정 — 값 갱신 후 상세에 반영된다.
     */
    public function testBulkUpsertUpdatesValues(): void
    {
        $this->service->bulkUpsert(2023, [
            'vat_divisor'      => '11',
            'memorandum_value' => '2000',
        ]);

        $this->assertSame('11', $this->valueOf(2023, 'vat_divisor'));
        $this->assertSame('2000', $this->valueOf(2023, 'memorandum_value'));
    }

    /**
     * 정수 파라미터에 비수치 입력 시 검증 예외.
     */
    public function testBulkUpsertRejectsNonNumericInt(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->bulkUpsert(2023, ['memorandum_value' => 'abc']);
    }

    /**
     * JSON 파라미터에 잘못된 JSON 입력 시 검증 예외.
     */
    public function testBulkUpsertRejectsInvalidJson(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->bulkUpsert(2023, ['income_tax_brackets' => '{invalid json']);
    }

    /**
     * 미등록 연도 일괄 수정은 NotFound.
     */
    public function testBulkUpsertUnknownYearThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->bulkUpsert(1999, ['vat_divisor' => '10']);
    }

    /**
     * 새 연도 추가 — 최신 등록 연도값을 복제한다.
     */
    public function testCreateYearClonesLatest(): void
    {
        $this->service->createYear(2025);

        $this->assertTrue($this->service->hasYear(2025));
        $this->assertSame('10', $this->valueOf(2025, 'vat_divisor'));

        $source = $this->service->paramsForYear(2023);
        $cloned = $this->service->paramsForYear(2025);
        $this->assertCount(count($source), $cloned);
    }

    /**
     * 이미 존재하는 연도 추가는 중복 예외.
     */
    public function testCreateYearDuplicateThrows(): void
    {
        $this->expectException(AlreadyExistsException::class);
        $this->service->createYear(2023);
    }
}
