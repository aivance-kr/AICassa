<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\DTOs\AssetData;
use App\DTOs\InventoryData;
use App\DTOs\LedgerData;
use App\Enums\DepreciationMethod;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Services\AssetService;
use App\Services\LedgerService;
use App\Services\TaxFormService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 신고서식 — 소득금액계산서(매출원가·소득금액), 감가상각조정명세서.
 *
 * @internal
 */
final class TaxFormServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ReferenceDataSeeder::class;
    protected $namespace;
    private TaxFormService $service;
    private LedgerService $ledger;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaxFormService();
        $this->ledger  = new LedgerService();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    private function accountId(string $name): int
    {
        return (int) model(AccountModel::class)->where('name', $name)->first()['id'];
    }

    private function entry(EntryType $type, string $account, int $amount): void
    {
        $this->ledger->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-06-01',
            entryType: $type,
            description: 'x',
            supplyAmount: $amount,
            accountId: $this->accountId($account),
            evidenceType: EvidenceType::TaxInvoice,
        ));
    }

    public function testIncomeStatementWithCostOfGoods(): void
    {
        // 매출 1,000만 / 상품매입 400만 / 임차료 100만
        $this->entry(EntryType::Income, '매출', 10_000_000);
        $this->entry(EntryType::Expense, '상품매입', 4_000_000);
        $this->entry(EntryType::Expense, '임차료', 1_000_000);

        // 재고: 기초상품 50만, 기말상품 150만 → 매출원가 = 50만+400만-150만 = 300만
        $this->service->saveInventory($this->userId, $this->businessId, 2024, new InventoryData(
            goodsBegin: 500_000,
            goodsEnd: 1_500_000,
        ));

        $s = $this->service->incomeStatement($this->userId, $this->businessId, 2024);

        $this->assertSame(10_000_000, $s['total_revenue']);
        $this->assertSame(3_000_000, $s['goods_cogs']);           // 매출원가
        // 필요경비 = 매출원가 300만 + 임차료 100만 = 400만
        $this->assertSame(4_000_000, $s['necessary_expense']);
        // 소득금액 = 1,000만 − 400만 = 600만
        $this->assertSame(6_000_000, $s['income_amount']);
    }

    public function testIncomeStatementWithoutInventory(): void
    {
        // 재고 미입력 시 상품매입이 그대로 필요경비
        $this->entry(EntryType::Income, '매출', 5_000_000);
        $this->entry(EntryType::Expense, '상품매입', 2_000_000);

        $s = $this->service->incomeStatement($this->userId, $this->businessId, 2024);
        // 재고 0이면 매출원가 = 0 + 매입200만 − 0 = 200만 (= 상품매입 그대로)
        $this->assertSame(2_000_000, $s['goods_cogs']);
        $this->assertSame(2_000_000, $s['necessary_expense']);
        $this->assertSame(3_000_000, $s['income_amount']);
    }

    public function testDepreciationAdjustment(): void
    {
        (new AssetService())->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
            depreciationMethod: DepreciationMethod::StraightLine,
            usefulLife: 5,
        ));

        $lines = $this->service->depreciationAdjustment($this->userId, $this->businessId, 2024);
        $this->assertCount(1, $lines);
        $this->assertSame(2_000_000, $lines[0]['depreciation']);
        $this->assertSame('노트북', $lines[0]['name']);
    }

    public function testOtherUserCannotAccess(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->incomeStatement((int) $other->getInsertID(), $this->businessId, 2024);
    }
}
