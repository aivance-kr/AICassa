<?php

use App\Database\Seeds\AccountSeeder;
use App\Enums\AccountCategory;
use App\Enums\ClassifierSource;
use App\Enums\EntryType;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\BusinessModel;
use App\Models\LedgerEntryModel;
use App\Models\PartnerModel;
use App\Services\AccountClassifierService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 계정과목 자동분류 서비스 — 이력 기반 추천(정확일치·거래처)과 AI 폴백을 검증한다.
 * Claude 호출은 스텁 CURLRequest 로 대체하고, 기본 생성자는 AI 비활성(네트워크 차단)이다.
 *
 * @internal
 */
final class AccountClassifierServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        // ledger_entries·partners 는 business_id FK 를 가지므로 실제 사업장을 만든다.
        $users = new UserModel();
        $users->save(new User(['username' => 'clf1', 'email' => 'clf@test.com', 'password' => 'secret12345']));
        $userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $userId, 'name' => '분류상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    /**
     * 정확일치 이력: 같은 거래내용을 과거에 쓴 계정과목을 그대로 추천한다(LLM 미사용).
     */
    public function testSuggestsFromExactDescriptionHistory(): void
    {
        $suppliesId = $this->accountId(AccountCategory::Expense, '소모품비');
        $this->insertEntry('expense', '토너 구입', $suppliesId);

        $service    = new AccountClassifierService(); // AI 비활성
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '토너 구입');

        $this->assertSame($suppliesId, $suggestion->accountId);
        $this->assertSame(ClassifierSource::History, $suggestion->source);
        $this->assertTrue($suggestion->isConfident());
    }

    /**
     * 거래처 이력: 거래내용은 처음이어도 같은 거래처의 과거 계정과목으로 추천한다.
     */
    public function testSuggestsFromPartnerHistory(): void
    {
        $mealsId   = $this->accountId(AccountCategory::Expense, '복리후생비');
        $partnerId = $this->insertPartner('행복도시락');
        $this->insertEntry('expense', '지난주 점심', $mealsId, $partnerId);

        $service    = new AccountClassifierService();
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '오늘 점심(신규 내용)', '행복도시락');

        $this->assertSame($mealsId, $suggestion->accountId);
        $this->assertSame(ClassifierSource::History, $suggestion->source);
    }

    /**
     * 정확일치가 거래처보다 우선한다.
     */
    public function testExactDescriptionTakesPrecedenceOverPartner(): void
    {
        $suppliesId = $this->accountId(AccountCategory::Expense, '소모품비');
        $mealsId    = $this->accountId(AccountCategory::Expense, '복리후생비');
        $partnerId  = $this->insertPartner('만능상회');

        $this->insertEntry('expense', 'A4 용지', $suppliesId, $partnerId);      // 이 거래처의 소모품비
        $this->insertEntry('expense', '간식', $mealsId, $partnerId);           // 이 거래처의 복리후생비

        $service    = new AccountClassifierService();
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, 'A4 용지', '만능상회');

        // 정확일치('A4 용지')가 있으므로 소모품비.
        $this->assertSame($suppliesId, $suggestion->accountId);
    }

    /**
     * 이력이 없고 AI 도 비활성이면 추천 없음(그레이스풀).
     */
    public function testReturnsNoneWhenNoHistoryAndAiDisabled(): void
    {
        $service    = new AccountClassifierService();
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '처음 보는 거래');

        $this->assertNull($suggestion->accountId);
        $this->assertSame(ClassifierSource::None, $suggestion->source);
        $this->assertFalse($suggestion->isConfident());
    }

    /**
     * AI 폴백: 이력이 없으면 Claude 응답(계정과목명)을 정확일치 매핑해 채택한다.
     */
    public function testUsesAiFallbackWhenNoHistory(): void
    {
        $suppliesId = $this->accountId(AccountCategory::Expense, '소모품비');

        $service    = $this->serviceWithAi('소모품비');
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '문구 잡화');

        $this->assertSame($suppliesId, $suggestion->accountId);
        $this->assertSame(ClassifierSource::Ai, $suggestion->source);
    }

    /**
     * 대량 임포트는 이력 추천은 유지하되 AI 폴백을 제한할 수 있어야 한다.
     */
    public function testCanDisableAiFallbackWithoutDisablingHistory(): void
    {
        $service    = $this->serviceWithAi('소모품비');
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '처음 보는 거래', allowAiFallback: false);

        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    /**
     * AI 가 목록에 없는 계정과목을 뱉으면 채택하지 않는다(닫힌 어휘, AI 불신).
     */
    public function testAiFallbackRejectsUnknownVocabulary(): void
    {
        $service    = $this->serviceWithAi('존재하지않는계정');
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '애매한 거래');

        $this->assertNull($suggestion->accountId);
        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    /**
     * AI 가 NONE 을 반환하면 추천 없음.
     */
    public function testAiFallbackHonorsNone(): void
    {
        $service    = $this->serviceWithAi('NONE');
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '분류 불가 거래');

        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    /**
     * 외부 호출이 실패해도 예외를 던지지 않고 추천 없음으로 폴백한다.
     */
    public function testAiFailureFallsBackToNone(): void
    {
        $service    = $this->serviceWithAiStatus(500);
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '서버 오류 거래');

        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    /**
     * 자산 전표 구분은 이 분류기 대상이 아니다(수입/비용만).
     */
    public function testAssetEntryTypeReturnsNone(): void
    {
        $service    = $this->serviceWithAi('소모품비');
        $suggestion = $service->suggest($this->businessId, EntryType::AssetPurchase, '노트북 구입');

        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    /**
     * 빈 거래내용은 추천하지 않는다.
     */
    public function testEmptyDescriptionReturnsNone(): void
    {
        $service    = $this->serviceWithAi('소모품비');
        $suggestion = $service->suggest($this->businessId, EntryType::Expense, '   ');

        $this->assertSame(ClassifierSource::None, $suggestion->source);
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────

    private function accountId(AccountCategory $category, string $name): int
    {
        $account = model(AccountModel::class)->findByCategoryName($category, $name);
        $this->assertNotNull($account, "시드 계정과목 '{$name}' 없음");

        return (int) $account['id'];
    }

    private function insertPartner(string $name): int
    {
        $partners = model(PartnerModel::class);
        $partners->insert(['business_id' => $this->businessId, 'name' => $name]);

        return (int) $partners->getInsertID();
    }

    private function insertEntry(string $entryType, string $description, int $accountId, ?int $partnerId = null): void
    {
        model(LedgerEntryModel::class)->insert([
            'business_id'   => $this->businessId,
            'fiscal_year'   => 2026,
            'entry_date'    => '2026-05-01',
            'entry_type'    => $entryType,
            'account_id'    => $accountId,
            'partner_id'    => $partnerId,
            'description'   => $description,
            'supply_amount' => 10000,
            'vat'           => 0,
            'evidence_type' => 'cash_receipt',
        ]);
    }

    /**
     * 지정한 계정과목명을 텍스트로 반환하는 Claude 스텁을 붙인 서비스.
     */
    private function serviceWithAi(string $modelText): AccountClassifierService
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);
        $ai   = new AnthropicClient($this->stubHttp(200, $body), 'test-key', 'claude-sonnet-5', 5);

        return new AccountClassifierService(ai: $ai);
    }

    private function serviceWithAiStatus(int $status): AccountClassifierService
    {
        $ai = new AnthropicClient($this->stubHttp($status, '{"error":"boom"}'), 'test-key', 'claude-sonnet-5', 5);

        return new AccountClassifierService(ai: $ai);
    }

    private function stubHttp(int $status, string $body): CURLRequest
    {
        return new class ($status, $body) extends CURLRequest {
            public function __construct(private int $stubStatus, private string $stubBody)
            {
                // 부모 생성자(네트워크 설정)는 건너뛴다.
            }

            public function request($method, string $url, array $options = []): ResponseInterface
            {
                $response = new Response(config('App'));
                $response->setStatusCode($this->stubStatus);
                $response->setBody($this->stubBody);

                return $response;
            }
        };
    }
}
