<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\DTOs\LedgerData;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Services\LedgerService;
use App\Services\TaxFormService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin 신고서식 — 인증·렌더링·재고 저장 → 매출원가 반영 검증.
 *
 * @internal
 */
final class AdminTaxFormFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $seed = ReferenceDataSeeder::class;
    protected $namespace;
    private User $user;
    private int $businessId;

    protected function setUp(): void
    {
        Services::reset();
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->user->id, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    private function entry(EntryType $type, string $account, int $amount): void
    {
        $accountId = (int) model(AccountModel::class)->where('name', $account)->first()['id'];
        (new LedgerService())->create((int) $this->user->id, $this->businessId, new LedgerData(
            entryDate: '2024-06-01',
            entryType: $type,
            description: 'x',
            supplyAmount: $amount,
            accountId: $accountId,
            evidenceType: EvidenceType::TaxInvoice,
        ));
    }

    public function testUnauthenticatedRedirected(): void
    {
        $this->get("/admin/businesses/{$this->businessId}/reports/tax-forms")->assertRedirect();
    }

    public function testRendersIncomeStatement(): void
    {
        $this->entry(EntryType::Income, '매출', 10_000_000);
        $this->entry(EntryType::Expense, '임차료', 1_000_000);

        $res = $this->actingAs($this->user)
            ->get("/admin/businesses/{$this->businessId}/reports/tax-forms?fiscal_year=2024");

        $res->assertOK();
        $res->assertSee('9,000,000'); // 소득금액 = 1,000만 − 100만
    }

    public function testSaveInventoryAffectsCostOfGoods(): void
    {
        $this->entry(EntryType::Income, '매출', 10_000_000);
        $this->entry(EntryType::Expense, '상품매입', 4_000_000);

        $this->actingAs($this->user)->post(
            "/admin/businesses/{$this->businessId}/reports/tax-forms/inventory",
            ['fiscal_year' => '2024', 'goods_begin' => '500,000', 'goods_end' => '1,500,000'],
        )->assertRedirect();

        $s = (new TaxFormService())->incomeStatement((int) $this->user->id, $this->businessId, 2024);
        $this->assertSame(3_000_000, $s['goods_cogs']);        // 50만+400만-150만
        $this->assertSame(7_000_000, $s['income_amount']);     // 1,000만 − 300만
    }

    public function testSaveAdjustmentsAffectsIncome(): void
    {
        $this->entry(EntryType::Income, '매출', 10_000_000);

        $this->actingAs($this->user)->post(
            "/admin/businesses/{$this->businessId}/reports/tax-forms/adjustments",
            ['fiscal_year' => '2024', 'revenue_exclude' => '1,000,000', 'donation_over' => '500,000'],
        )->assertRedirect();

        $s = (new TaxFormService())->incomeStatement((int) $this->user->id, $this->businessId, 2024);
        $this->assertSame(9_000_000, $s['adjusted_revenue']);  // ⑭ = 1000만 −100만
        $this->assertSame(9_500_000, $s['income_amount']);     // ㉒ = 900만 +50만
    }
}
