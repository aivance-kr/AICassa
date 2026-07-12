<?php

use App\Database\Seeds\AccountSeeder;
use App\Enums\EntryType;
use App\Libraries\AnthropicClient;
use App\Models\BusinessModel;
use App\Models\PartnerModel;
use App\Services\LedgerQueryParserService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 자연어 장부 검색 파서 — AI 출력을 화이트리스트 필터로만 흡수하는지(SQL 직접생성 금지),
 * 계정과목·거래처 정확일치 매핑, 잘못된 값 폐기, AI 미설정·실패 시 키워드 폴백을 검증한다.
 *
 * @internal
 */
final class LedgerQueryParserServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        // 거래처는 business_id FK 를 가지므로 실제 사업장을 만든다.
        $users = new UserModel();
        $users->save(new User(['username' => 'nlq1', 'email' => 'nlq@test.com', 'password' => 'secret12345']));
        $userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $userId, 'name' => '검색상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    /**
     * 구조화된 AI 응답을 안전한 필터로 해석한다(구분·금액·계정과목·기간).
     */
    public function testParsesStructuredFilter(): void
    {
        $parser = $this->parserWithAi([
            'entry_type'   => 'expense',
            'account_name' => '기업업무추진비',
            'amount_min'   => 500000,
            'date_from'    => '2026-06-01',
            'date_to'      => '2026-06-30',
        ]);

        $filter = $parser->parse($this->businessId, '지난달 접대비 50만원 넘는 건', '2026-07-10');

        $this->assertSame(EntryType::Expense, $filter->entryType);
        $this->assertSame(500000, $filter->amountMin);
        $this->assertSame('2026-06-01', $filter->dateFrom);
        $this->assertSame('2026-06-30', $filter->dateTo);
        $this->assertNotNull($filter->accountId); // '기업업무추진비' 매핑 성공
    }

    /**
     * 거래처명은 사업장 내 등록건과 정확일치할 때만 id 로 채운다.
     */
    public function testResolvesPartnerName(): void
    {
        model(PartnerModel::class)->insert(['business_id' => $this->businessId, 'name' => '스타벅스']);

        $parser = $this->parserWithAi(['partner_name' => '스타벅스', 'keyword' => null]);
        $filter = $parser->parse($this->businessId, '스타벅스 거래', '2026-07-10');

        $this->assertNotNull($filter->partnerId);
    }

    /**
     * 목록에 없는 계정과목은 채택하지 않는다(화이트리스트).
     */
    public function testRejectsUnknownAccountName(): void
    {
        $parser = $this->parserWithAi(['account_name' => '존재하지않는계정', 'entry_type' => 'expense']);
        $filter = $parser->parse($this->businessId, '이상한 계정', '2026-07-10');

        $this->assertNull($filter->accountId);
    }

    /**
     * AI 가 스키마 밖 필드(SQL 등)를 넣어도 무시하고 안전한 필터만 만든다.
     */
    public function testIgnoresUnknownFieldsFromAi(): void
    {
        $parser = $this->parserWithAi([
            'sql'        => 'DROP TABLE ledger_entries',
            'table'      => 'users',
            'entry_type' => 'income',
        ]);

        $filter = $parser->parse($this->businessId, '수입만', '2026-07-10');

        // 알려진 필드만 흡수 — 스키마 밖 키는 필터에 존재하지 않는다.
        $this->assertSame(EntryType::Income, $filter->entryType);
        $this->assertArrayNotHasKey('sql', $filter->toFilterArray());
        $this->assertArrayNotHasKey('table', $filter->toFilterArray());
    }

    /**
     * 잘못된 날짜·음수 금액은 폐기한다.
     */
    public function testDropsInvalidDateAndNegativeAmount(): void
    {
        $parser = $this->parserWithAi([
            'date_from'  => '2026-13-99', // 존재하지 않는 날짜
            'amount_min' => -100,          // 음수
            'keyword'    => '점심',
        ]);

        $filter = $parser->parse($this->businessId, '점심', '2026-07-10');

        $this->assertNull($filter->dateFrom);
        $this->assertNull($filter->amountMin);
        $this->assertSame('점심', $filter->keyword);
    }

    /**
     * AI 미설정 시 입력 문구 전체를 키워드 검색으로 폴백한다.
     */
    public function testFallsBackToKeywordWhenAiDisabled(): void
    {
        $parser = new LedgerQueryParserService(); // ai=null

        $filter = $parser->parse($this->businessId, '문구용품 구입', '2026-07-10');

        $this->assertSame('문구용품 구입', $filter->keyword);
        $this->assertNull($filter->entryType);
    }

    /**
     * AI 호출 실패(비200)도 예외 없이 키워드 폴백.
     */
    public function testFallsBackToKeywordOnAiFailure(): void
    {
        $ai     = new AnthropicClient($this->stubHttp(500, '{"error":"boom"}'), 'k', 'claude-sonnet-5', 5);
        $parser = new LedgerQueryParserService(ai: $ai);

        $filter = $parser->parse($this->businessId, '주유비', '2026-07-10');

        $this->assertSame('주유비', $filter->keyword);
    }

    /**
     * 빈 입력은 빈 필터.
     */
    public function testEmptyInputReturnsEmptyFilter(): void
    {
        $parser = $this->parserWithAi(['entry_type' => 'income']);
        $filter = $parser->parse($this->businessId, '   ', '2026-07-10');

        $this->assertTrue($filter->isEmpty());
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────

    /**
     * 지정한 필터 JSON 을 반환하는 Claude 스텁을 붙인 파서.
     *
     * @param array<string, mixed> $filterJson
     */
    private function parserWithAi(array $filterJson): LedgerQueryParserService
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => (string) json_encode($filterJson)]]]);
        $ai   = new AnthropicClient($this->stubHttp(200, $body), 'test-key', 'claude-sonnet-5', 5);

        return new LedgerQueryParserService(ai: $ai);
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
