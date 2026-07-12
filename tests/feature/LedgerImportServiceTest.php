<?php

use App\Database\Seeds\AccountSeeder;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Models\PartnerModel;
use App\Services\LedgerImportService;
use App\Services\LedgerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장부 CSV 일괄 업로드 — 파싱·이름 해석·행별 검증·집계.
 *
 * @internal
 */
final class LedgerImportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private LedgerImportService $service;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LedgerImportService();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();

        model(PartnerModel::class)->insert([
            'business_id' => $this->businessId, 'name' => '가나상사', 'biz_reg_no' => '1112233333',
        ]);
    }

    public function testParseCsvSkipsHeader(): void
    {
        $csv  = "날짜,구분,계정과목,거래내용,거래처,금액,비고\n2024-03-01,수입,매출,3월매출,,1000000,세금계산서";
        $rows = $this->service->parseCsv($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('수입', $rows[0]['type']);
        $this->assertSame('세금계산서', $rows[0]['evidence']);
    }

    public function testParseWithMappingReordersColumns(): void
    {
        // 소스 열 순서: 금액, 날짜, 거래내용, 구분 (표준과 다름). 계정·거래처·비고 미매핑.
        $dataRows = [
            ['1,000,000', '2024-03-01', '3월매출', '수입'],
            ['', '', '', ''], // 빈 행 → 건너뜀
            ['500000', '2024-03-05', '임차료', '비용'],
        ];
        $mapping = ['amount' => 0, 'date' => 1, 'description' => 2, 'type' => 3];

        $rows = $this->service->parseWithMapping($dataRows, $mapping);

        $this->assertCount(2, $rows);
        $this->assertSame('2024-03-01', $rows[0]['date']);
        $this->assertSame('수입', $rows[0]['type']);
        $this->assertSame('3월매출', $rows[0]['description']);
        $this->assertSame('1,000,000', $rows[0]['amount']);
        // 미매핑 필드는 빈 문자열
        $this->assertSame('', $rows[0]['account']);
        $this->assertSame('', $rows[0]['partner']);
        $this->assertSame('', $rows[0]['evidence']);
    }

    public function testParseWithMappingThenImport(): void
    {
        $dataRows = [
            ['3월매출', '수입', '2024-03-01', '1000000', '세금계산서'],
        ];
        $mapping = ['description' => 0, 'type' => 1, 'date' => 2, 'amount' => 3, 'evidence' => 4];

        $rows   = $this->service->parseWithMapping($dataRows, $mapping);
        $result = $this->service->import($this->userId, $this->businessId, $rows);

        $this->assertSame(1, $result->imported);
        $this->assertSame(0, $result->skipped);
    }

    public function testImportCountsAndComputesVat(): void
    {
        $rows = [
            ['date' => '2024-03-01', 'type' => '수입', 'account' => '매출', 'description' => '3월매출', 'partner' => '', 'amount' => '1,000,000', 'evidence' => '세금계산서'],       // OK
            ['date' => '2024.03.05', 'type' => '비용', 'account' => '임차료', 'description' => '임차료', 'partner' => '가나상사', 'amount' => '500000', 'evidence' => '세금계산서'],  // OK (점 구분자)
            ['date' => '2024-03-06', 'type' => '지출', 'account' => '임차료', 'description' => 'x', 'partner' => '', 'amount' => '100', 'evidence' => '기타'],                        // 구분 오류
            ['date' => '2024-03-07', 'type' => '수입', 'account' => '없는계정', 'description' => 'x', 'partner' => '', 'amount' => '100', 'evidence' => '기타'],                      // 계정 없음
            ['date' => '2024-03-08', 'type' => '비용', 'account' => '임차료', 'description' => 'x', 'partner' => '미등록', 'amount' => '100', 'evidence' => '기타'],                  // 거래처 미등록
            ['date' => '2024-03-09', 'type' => '수입', 'account' => '매출', 'description' => 'x', 'partner' => '', 'amount' => 'abc', 'evidence' => '기타'],                          // 금액 오류
            ['date' => 'notadate', 'type' => '수입', 'account' => '매출', 'description' => 'x', 'partner' => '', 'amount' => '100', 'evidence' => '기타'],                            // 날짜 오류
        ];

        $result = $this->service->import($this->userId, $this->businessId, $rows);

        $this->assertSame(2, $result->imported);
        $this->assertSame(5, $result->skipped);
        $this->assertCount(5, $result->errors);

        $entries = (new LedgerService())->listForBusiness($this->userId, $this->businessId);
        $this->assertCount(2, $entries);

        // 세금계산서 100만원 → 부가세 10만원
        $income = array_values(array_filter($entries, static fn ($e) => $e['entry_type'] === 'income'))[0];
        $this->assertSame(100000, (int) $income['vat']);
        $this->assertSame(2024, (int) $income['fiscal_year']);
    }

    public function testUnownedBusinessThrows(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->import((int) $other->getInsertID(), $this->businessId, []);
    }
}
