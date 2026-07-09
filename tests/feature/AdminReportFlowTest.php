<?php

use App\Database\Seeds\AccountSeeder;
use App\DTOs\LedgerData;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
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
 * Admin 영업현황표 — 인증·라우팅·렌더링 검증.
 *
 * @internal
 */
final class AdminReportFlowTest extends CIUnitTestCase
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
        Services::reset(); // Shield 인증/세션 상태 격리(테스트 순서 의존 방지)
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
        $this->get("/admin/businesses/{$this->businessId}/reports/business-status")->assertRedirect();
    }

    public function testRendersStatement(): void
    {
        (new LedgerService())->create((int) $this->user->id, $this->businessId, new LedgerData(
            entryDate: '2024-03-10',
            entryType: EntryType::Income,
            description: 'x',
            supplyAmount: 1_000_000,
            evidenceType: EvidenceType::TaxInvoice,
        ));

        $result = $this->actingAs($this->user)
            ->get("/admin/businesses/{$this->businessId}/reports/business-status?fiscal_year=2024");

        $result->assertOK();
        // 월별 수입 100만 + 부가세 10만이 표에 렌더된다(숫자 포맷)
        $result->assertSee('1,000,000');
        $result->assertSee('100,000');
    }
}
