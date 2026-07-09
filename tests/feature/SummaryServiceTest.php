<?php

use App\Database\Seeds\AccountSeeder;
use App\DTOs\LedgerData;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Services\LedgerService;
use App\Services\SummaryService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 영업현황표 — 월/분기/합계 집계 검증.
 *
 * @internal
 */
final class SummaryServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private SummaryService $service;
    private LedgerService $ledger;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SummaryService();
        $this->ledger  = new LedgerService();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    private function entry(string $date, EntryType $type, int $amount): void
    {
        $this->ledger->create($this->userId, $this->businessId, new LedgerData(
            entryDate: $date,
            entryType: $type,
            description: 'x',
            supplyAmount: $amount,
            evidenceType: EvidenceType::TaxInvoice,
        ));
    }

    public function testMonthlyQuarterAndTotalAggregation(): void
    {
        // 2024: 3월 수입 100만, 5월 수입 200만, 5월 비용 50만, 8월 비용 30만
        $this->entry('2024-03-10', EntryType::Income, 1_000_000);
        $this->entry('2024-05-01', EntryType::Income, 2_000_000);
        $this->entry('2024-05-20', EntryType::Expense, 500_000);
        $this->entry('2024-08-15', EntryType::Expense, 300_000);
        // 다른 연도(집계 제외 확인)
        $this->entry('2023-05-01', EntryType::Income, 9_999_999);

        $r = $this->service->monthlyStatement($this->userId, $this->businessId, 2024);

        // 월별
        $this->assertSame(1_000_000, $r['months'][3]['income']);
        $this->assertSame(2_000_000, $r['months'][5]['income']);
        $this->assertSame(500_000, $r['months'][5]['expense']);
        $this->assertSame(100000, $r['months'][3]['income_vat']); // 세금계산서 10%

        // 분기: 1분기(1~3) 수입 100만, 2분기(4~6) 수입 200만·비용 50만, 3분기(7~9) 비용 30만
        $this->assertSame(1_000_000, $r['quarters'][1]['income']);
        $this->assertSame(2_000_000, $r['quarters'][2]['income']);
        $this->assertSame(500_000, $r['quarters'][2]['expense']);
        $this->assertSame(300_000, $r['quarters'][3]['expense']);

        // 연간 합계
        $this->assertSame(3_000_000, $r['total']['income']);
        $this->assertSame(800_000, $r['total']['expense']);
        $this->assertSame(300000, $r['total']['income_vat']);
    }

    public function testEmptyYearReturnsZeros(): void
    {
        $r = $this->service->monthlyStatement($this->userId, $this->businessId, 2030);
        $this->assertSame(0, $r['total']['income']);
        $this->assertCount(12, $r['months']);
        $this->assertCount(4, $r['quarters']);
    }

    public function testOtherUserCannotAccess(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->monthlyStatement((int) $other->getInsertID(), $this->businessId, 2024);
    }
}
