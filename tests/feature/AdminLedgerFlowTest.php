<?php

use App\Database\Seeds\AccountSeeder;
use App\DTOs\LedgerData;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Services\LedgerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Admin 장부 화면 — 인증·라우팅·입력 동작 통합 검증.
 *
 * @internal
 */
final class AdminLedgerFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private User $user;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->user->id, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    public function testUnauthenticatedRedirected(): void
    {
        $this->get("/admin/businesses/{$this->businessId}/ledger")->assertRedirect();
    }

    public function testAuthenticatedCanViewLedger(): void
    {
        $result = $this->actingAs($this->user)->get("/admin/businesses/{$this->businessId}/ledger");
        $result->assertOK();
        $result->assertSee("/admin/businesses/{$this->businessId}/ledger/new");
    }

    public function testCreateEntryComputesVatViaController(): void
    {
        $salesId = (int) model(AccountModel::class)->where('name', '매출')->first()['id'];

        $this->actingAs($this->user)->post("/admin/businesses/{$this->businessId}/ledger", [
            'entry_type'    => 'income',
            'entry_date'    => '2024-08-10',
            'account_id'    => $salesId,
            'description'   => '8월 매출',
            'supply_amount' => '1,000,000', // 콤마 포함 입력
            'evidence_type' => 'tax_invoice',
        ])->assertRedirectTo("/admin/businesses/{$this->businessId}/ledger");

        $entries = (new LedgerService())->listForBusiness((int) $this->user->id, $this->businessId);
        $this->assertCount(1, $entries);
        $this->assertSame(1_000_000, (int) $entries[0]['supply_amount']);
        $this->assertSame(100000, (int) $entries[0]['vat']);
        $this->assertSame(2024, (int) $entries[0]['fiscal_year']);
    }

    public function testImportFormRenders(): void
    {
        $result = $this->actingAs($this->user)->get("/admin/businesses/{$this->businessId}/ledger/import");
        $result->assertOK();
        $result->assertSee("/admin/businesses/{$this->businessId}/ledger/import");
    }

    public function testImportWithoutFileRedirectsWithError(): void
    {
        $this->actingAs($this->user)
            ->post("/admin/businesses/{$this->businessId}/ledger/import")
            ->assertRedirect();
    }

    public function testCopyCreatesDuplicate(): void
    {
        $svc = new LedgerService();
        $id  = $svc->create((int) $this->user->id, $this->businessId, new LedgerData(
            entryDate: '2024-02-01',
            entryType: EntryType::Expense,
            description: '월 임차료',
            supplyAmount: 300_000,
            evidenceType: EvidenceType::TaxInvoice,
        ));

        $this->actingAs($this->user)
            ->post("/admin/businesses/{$this->businessId}/ledger/{$id}/copy")
            ->assertRedirect();

        $this->assertCount(2, $svc->listForBusiness((int) $this->user->id, $this->businessId));
    }
}
