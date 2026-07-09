<?php

use App\Database\Seeds\ReferenceDataSeeder;
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
use Config\Services;

/**
 * Admin 신고서식 출력 — 인쇄 페이지 렌더 + 엑셀 다운로드.
 *
 * @internal
 */
final class AdminTaxFormExportTest extends CIUnitTestCase
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

        $salesId = (int) model(AccountModel::class)->where('name', '매출')->first()['id'];
        (new LedgerService())->create((int) $this->user->id, $this->businessId, new LedgerData(
            entryDate: '2024-06-01',
            entryType: EntryType::Income,
            description: '매출',
            supplyAmount: 10_000_000,
            accountId: $salesId,
            evidenceType: EvidenceType::TaxInvoice,
        ));
    }

    public function testPrintPageRenders(): void
    {
        $res = $this->actingAs($this->user)
            ->get("/admin/businesses/{$this->businessId}/reports/tax-forms/print?fiscal_year=2024");

        $res->assertOK();
        $res->assertSee('종합소득세 신고서식');
        $res->assertSee('10,000,000');
    }

    public function testExcelDownloadsWithCorrectHeaders(): void
    {
        $res = $this->actingAs($this->user)
            ->get("/admin/businesses/{$this->businessId}/reports/tax-forms/excel?fiscal_year=2024");

        $res->assertOK();
        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // xlsx(zip) 내부 시그니처. (테스트 환경 출력 핸들러가 본문을 감싸므로 시작 문자 대신 포함 여부로 확인)
        $this->assertStringContainsString('[Content_Types].xml', $res->getBody());
    }

    public function testUnauthenticatedRedirected(): void
    {
        $this->get("/admin/businesses/{$this->businessId}/reports/tax-forms/excel")->assertRedirect();
    }
}
