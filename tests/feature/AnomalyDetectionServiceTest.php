<?php

use App\Database\Seeds\AccountSeeder;
use App\DTOs\AnomalyFinding;
use App\DTOs\AnomalyReport;
use App\Enums\AccountCategory;
use App\Enums\AnomalySeverity;
use App\Enums\EvidenceType;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Models\LedgerEntryModel;
use App\Services\AnomalyDetectionService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 신고 전 이상탐지 — 결정적 규칙(미분류·증빙미비·부가세불일치·접대비비중·전년비급증)과
 * 선택적 AI 오분류 점검을 검증한다. 금액은 도메인 서비스가 재계산하며, AI 는 스텁으로 대체한다.
 *
 * @internal
 */
final class AnomalyDetectionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'anom1', 'email' => 'anom@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '점검상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    /**
     * 정상 장부는 경고가 없다.
     */
    public function testCleanLedgerHasNoFindings(): void
    {
        $this->insert('income', '매출', 1_000_000, EvidenceType::TaxInvoice);
        $this->insert('expense', '소모품비', 100_000, EvidenceType::TaxInvoice);

        $report = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);

        $this->assertTrue($report->isClean());
    }

    /**
     * R1. 계정과목 미분류 → 위험.
     */
    public function testDetectsUnclassified(): void
    {
        $this->insert('expense', null, 50_000, EvidenceType::TaxInvoice);

        $report  = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);
        $finding = $this->find($report, 'UNCLASSIFIED_ACCOUNT');

        $this->assertNotNull($finding);
        $this->assertSame(AnomalySeverity::High, $finding->severity);
    }

    /**
     * R2. 3만원 초과 간이영수증·기타 증빙 지출 → 주의.
     */
    public function testDetectsInadequateEvidence(): void
    {
        $this->insert('expense', '소모품비', 100_000, EvidenceType::SimpleReceipt);

        $report = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);

        $this->assertNotNull($this->find($report, 'INADEQUATE_EVIDENCE'));
    }

    /**
     * R3. 저장된 부가세가 재계산값과 다르면 위험(수기 수정·업로드 오류).
     */
    public function testDetectsVatMismatch(): void
    {
        // 세금계산서 100,000원이면 부가세 10,000원이어야 하는데 0으로 저장.
        $this->insert('expense', '소모품비', 100_000, EvidenceType::TaxInvoice, vatOverride: 0);

        $report  = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);
        $finding = $this->find($report, 'VAT_MISMATCH');

        $this->assertNotNull($finding);
        $this->assertSame(AnomalySeverity::High, $finding->severity);
    }

    /**
     * R5. 기업업무추진비 비중이 총비용의 10% 이상 → 주의.
     */
    public function testDetectsEntertainmentRatio(): void
    {
        $this->insert('expense', '기업업무추진비', 300_000, EvidenceType::TaxInvoice);
        $this->insert('expense', '소모품비', 100_000, EvidenceType::TaxInvoice);

        $report = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);

        $this->assertNotNull($this->find($report, 'ENTERTAINMENT_RATIO_HIGH'));
    }

    /**
     * R4. 계정과목별 비용이 전년 대비 3배 이상·100만원 이상 → 주의.
     */
    public function testDetectsYearOverYearSurge(): void
    {
        $this->insert('expense', '광고선전비', 200_000, EvidenceType::TaxInvoice, year: 2025);
        $this->insert('expense', '광고선전비', 1_500_000, EvidenceType::TaxInvoice, year: 2026);

        $report = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);

        $this->assertNotNull($this->find($report, 'YOY_SURGE'));
    }

    /**
     * AI 오분류 의심 — 스텁 응답을 참고 경고로 변환한다.
     */
    public function testAiMisclassificationFindings(): void
    {
        $this->insert('expense', '복리후생비', 60_000, EvidenceType::TaxInvoice, description: '주유비');

        $suspectJson = json_encode([
            ['description' => '주유비', 'account' => '복리후생비', 'reason' => '차량유지비가 적절합니다.'],
        ]);
        $service = new AnomalyDetectionService(ai: $this->stubAi((string) $suspectJson));

        $report  = $service->analyze($this->userId, $this->businessId, 2026);
        $finding = $this->find($report, 'MISCLASSIFICATION_SUSPECT');

        $this->assertNotNull($finding);
        $this->assertSame('ai', $finding->source);
        $this->assertSame(AnomalySeverity::Info, $finding->severity);
    }

    /**
     * AI 미설정 시 AI 경고는 없지만 결정적 규칙은 그대로 동작한다.
     */
    public function testAiDisabledStillRunsRules(): void
    {
        $this->insert('expense', null, 50_000, EvidenceType::TaxInvoice); // 미분류

        $report = (new AnomalyDetectionService())->analyze($this->userId, $this->businessId, 2026);

        $this->assertNull($this->find($report, 'MISCLASSIFICATION_SUSPECT'));
        $this->assertNotNull($this->find($report, 'UNCLASSIFIED_ACCOUNT'));
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────

    private function insert(
        string $type,
        ?string $accountName,
        int $amount,
        EvidenceType $evidence,
        int $year = 2026,
        ?string $description = null,
        ?int $vatOverride = null,
    ): void {
        $accountId = null;
        if ($accountName !== null) {
            $category  = $type === 'income' ? AccountCategory::Income : AccountCategory::Expense;
            $account   = model(AccountModel::class)->findByCategoryName($category, $accountName);
            $accountId = $account !== null ? (int) $account['id'] : null;
        }

        // 부가세는 도메인 서비스가 산출한 값을 저장(오류 재현 시에만 override).
        $vat = $vatOverride ?? service('vatCalculator')->calculate($amount, $evidence, $year);

        model(LedgerEntryModel::class)->insert([
            'business_id'   => $this->businessId,
            'fiscal_year'   => $year,
            'entry_date'    => "{$year}-05-01",
            'entry_type'    => $type,
            'account_id'    => $accountId,
            'description'   => $description ?? ($accountName ?? '거래'),
            'supply_amount' => $amount,
            'vat'           => $vat,
            'evidence_type' => $evidence->value,
        ]);
    }

    private function find(AnomalyReport $report, string $code): ?AnomalyFinding
    {
        foreach ($report->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }

    private function stubAi(string $modelText): AnthropicClient
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);

        $http = new class (200, $body) extends CURLRequest {
            public function __construct(private int $stubStatus, private string $stubBody)
            {
            }

            public function request($method, string $url, array $options = []): ResponseInterface
            {
                $response = new Response(config('App'));
                $response->setStatusCode($this->stubStatus);
                $response->setBody($this->stubBody);

                return $response;
            }
        };

        return new AnthropicClient($http, 'test-key', 'claude-sonnet-5', 5);
    }
}
