<?php

use App\Database\Seeds\AccountSeeder;
use App\DTOs\LedgerData;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Services\LedgerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장부 서비스 — 부가세 자동계산·귀속연도·복사·스코프·필터 검증.
 *
 * @internal
 */
final class LedgerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private LedgerService $service;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LedgerService();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    private function salesAccountId(): int
    {
        $acc = model(AccountModel::class)->where('name', '매출')->first();

        return (int) $acc['id'];
    }

    public function testCreateComputesVatAndFiscalYear(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-03-15',
            entryType: EntryType::Income,
            description: '3월 매출',
            supplyAmount: 1_000_000,
            accountId: $this->salesAccountId(),
            evidenceType: EvidenceType::TaxInvoice,
        ));

        $entry = $this->service->get($this->userId, $this->businessId, $id);
        $this->assertSame(100000, (int) $entry['vat']);         // 세금계산서 → 10%
        $this->assertSame(2024, (int) $entry['fiscal_year']);   // 귀속연도 파생
    }

    public function testExemptEvidenceHasZeroVat(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-05-01',
            entryType: EntryType::Income,
            description: '면세 매출',
            supplyAmount: 500_000,
            accountId: $this->salesAccountId(),
            evidenceType: EvidenceType::Invoice, // 계산서 → 면세
        ));

        $this->assertSame(0, (int) $this->service->get($this->userId, $this->businessId, $id)['vat']);
    }

    public function testAccountCategoryMismatchThrows(): void
    {
        // 수입 거래에 비용 계정 지정 → 검증 실패
        $expenseAcc = model(AccountModel::class)->where('name', '임차료')->first();

        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-01-10',
            entryType: EntryType::Income,
            description: '잘못된 계정',
            supplyAmount: 100_000,
            accountId: (int) $expenseAcc['id'],
        ));
    }

    public function testCopyDuplicatesEntry(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-02-01',
            entryType: EntryType::Expense,
            description: '월 임차료',
            supplyAmount: 300_000,
            evidenceType: EvidenceType::TaxInvoice,
        ));

        $copyId = $this->service->copy($this->userId, $this->businessId, $id);
        $this->assertNotSame($id, $copyId);

        $copy = $this->service->get($this->userId, $this->businessId, $copyId);
        $this->assertSame('월 임차료', $copy['description']);
        $this->assertSame(30000, (int) $copy['vat']);
    }

    public function testInvalidReceiptPathRejected(): void
    {
        // 클라이언트가 hidden 필드로 임의 경로(경로 조작)를 주입하면 저장을 거부한다.
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-03-15',
            entryType: EntryType::Expense,
            description: '위조 첨부',
            supplyAmount: 10_000,
            receiptPath: '../../.env',
        ));
    }

    public function testOtherUserCannotAccess(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->listForBusiness((int) $other->getInsertID(), $this->businessId);
    }

    public function testFilterAndSummarize(): void
    {
        $sales = $this->salesAccountId();
        $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2023-06-01',
            entryType: EntryType::Income,
            description: '작년매출',
            supplyAmount: 1_000_000,
            accountId: $sales,
            evidenceType: EvidenceType::TaxInvoice,
        ));
        $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-06-01',
            entryType: EntryType::Income,
            description: '올해매출',
            supplyAmount: 2_000_000,
            accountId: $sales,
            evidenceType: EvidenceType::TaxInvoice,
        ));
        $this->service->create($this->userId, $this->businessId, new LedgerData(
            entryDate: '2024-07-01',
            entryType: EntryType::Expense,
            description: '올해비용',
            supplyAmount: 500_000,
            evidenceType: EvidenceType::TaxInvoice,
        ));

        // 2024년만 필터
        $entries = $this->service->listForBusiness($this->userId, $this->businessId, ['fiscal_year' => 2024]);
        $this->assertCount(2, $entries);

        $sum = $this->service->summarize($entries);
        $this->assertSame(2_000_000, $sum['income']);
        $this->assertSame(500_000, $sum['expense']);
        $this->assertSame(200000, $sum['income_vat']);

        // 계정명이 부가된다
        $incomeRow = array_values(array_filter($entries, static fn ($e) => $e['entry_type'] === 'income'))[0];
        $this->assertSame('매출', $incomeRow['account_name']);
    }
}
