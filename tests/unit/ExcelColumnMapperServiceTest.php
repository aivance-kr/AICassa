<?php

use App\Libraries\AnthropicClient;
use App\Services\ExcelColumnMapperService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 엑셀/CSV 컬럼 자동매핑 — 로컬 동의어 우선, AI 보완, 닫힌 어휘 흡수.
 *
 * @internal
 */
final class ExcelColumnMapperServiceTest extends CIUnitTestCase
{
    /**
     * AI 없이도 동의어 사전으로 표준 헤더를 매핑한다.
     */
    public function testLocalSynonymMappingWithoutAi(): void
    {
        $service = new ExcelColumnMapperService(); // AI 미주입
        $headers = ['거래일자', '수입/비용', '계정과목', '적요', '거래처', '공급가액', '증빙'];

        $assign = $service->suggest($headers);

        $this->assertSame('date', $assign[0]);
        $this->assertSame('type', $assign[1]);
        $this->assertSame('account', $assign[2]);
        $this->assertSame('description', $assign[3]);
        $this->assertSame('partner', $assign[4]);
        $this->assertSame('amount', $assign[5]);
        $this->assertSame('evidence', $assign[6]);
    }

    /**
     * 정규화(공백·괄호 제거)로도 매칭된다.
     */
    public function testNormalizationMatch(): void
    {
        // 공백·괄호를 제거한 뒤 동의어와 정확일치하면 매핑된다.
        $service = new ExcelColumnMapperService();
        $assign  = $service->suggest(['거 래 일', '공급 가액', '(계정과목)']);

        $this->assertSame('date', $assign[0]);
        $this->assertSame('amount', $assign[1]);
        $this->assertSame('account', $assign[2]);
    }

    /**
     * 로컬 매칭이 안 되는 헤더는 AI 제안으로 보완하되, 닫힌 어휘만 흡수한다.
     */
    public function testAiFillsUnresolvedHeaders(): void
    {
        // 0=날짜(로컬), 1·2·3 은 로컬 미매칭 → AI 가 보완. 'bogus' 는 폐기되어야 한다.
        $aiJson  = json_encode(['1' => 'type', '2' => 'amount', '3' => 'bogus']);
        $service = $this->serviceWithAi((string) $aiJson);

        $assign = $service->suggest(['날짜', 'IN/OUT', 'Value', 'Note']);

        $this->assertSame('date', $assign[0]);   // 로컬
        $this->assertSame('type', $assign[1]);   // AI
        $this->assertSame('amount', $assign[2]); // AI
        $this->assertNull($assign[3]);           // 'bogus' 폐기 → 미매핑
    }

    /**
     * 같은 표준 필드를 두 열에 배정하지 않는다(첫 열 우선).
     */
    public function testNoDuplicateFieldAssignment(): void
    {
        $service = new ExcelColumnMapperService();
        $assign  = $service->suggest(['금액', '공급가액']); // 둘 다 amount 동의어

        $this->assertSame('amount', $assign[0]);
        $this->assertNull($assign[1]); // 중복 방지
    }

    /**
     * AI 호출이 실패해도(예: 5xx) 로컬 매칭 결과로 graceful 하게 동작한다.
     */
    public function testGracefulWhenAiFails(): void
    {
        $service = $this->serviceWithAiStatus(500);
        $assign  = $service->suggest(['날짜', '???']);

        $this->assertSame('date', $assign[0]);
        $this->assertNull($assign[1]);
    }

    /**
     * analyze() 는 헤더·샘플·매핑을 분리한다.
     */
    public function testAnalyzeSplitsHeaderAndSamples(): void
    {
        $service = new ExcelColumnMapperService();
        $grid    = [
            ['날짜', '금액'],
            ['2024-03-01', '1000'],
            ['2024-03-02', '2000'],
        ];

        $result = $service->analyze($grid);

        $this->assertSame(['날짜', '금액'], $result->headers);
        $this->assertCount(2, $result->samples);
        $this->assertSame('date', $result->assignments[0]);
        $this->assertSame('amount', $result->assignments[1]);
    }

    /**
     * 확정 매핑 검증: 유효하면 field→열index 매핑과 null 오류를 돌려준다.
     */
    public function testResolvePostedMappingValid(): void
    {
        $service = new ExcelColumnMapperService();
        $posted  = ['0' => 'date', '1' => 'type', '2' => 'description', '3' => 'amount', '4' => ''];

        $resolved = $service->resolvePostedMapping($posted);

        $this->assertNull($resolved['error']);
        $this->assertSame(['date' => 0, 'type' => 1, 'description' => 2, 'amount' => 3], $resolved['mapping']);
    }

    /**
     * 같은 표준 필드를 두 열에 지정하면 오류를 돌려준다.
     */
    public function testResolvePostedMappingRejectsDuplicate(): void
    {
        $service = new ExcelColumnMapperService();
        $posted  = ['0' => 'amount', '1' => 'amount'];

        $resolved = $service->resolvePostedMapping($posted);

        $this->assertNotNull($resolved['error']);
        $this->assertSame([], $resolved['mapping']);
    }

    /**
     * 필수 필드(날짜·구분·거래내용·금액)가 빠지면 오류를 돌려준다.
     */
    public function testResolvePostedMappingRejectsMissingRequired(): void
    {
        $service = new ExcelColumnMapperService();
        $posted  = ['0' => 'date', '1' => 'type']; // description·amount 누락

        $resolved = $service->resolvePostedMapping($posted);

        $this->assertNotNull($resolved['error']);
        $this->assertStringContainsString('필수', (string) $resolved['error']);
    }

    private function serviceWithAi(string $modelText): ExcelColumnMapperService
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);
        $ai   = new AnthropicClient($this->stubHttp(200, $body), 'test-key', 'claude-sonnet-5', 5);

        return new ExcelColumnMapperService(ai: $ai);
    }

    private function serviceWithAiStatus(int $status): ExcelColumnMapperService
    {
        $ai = new AnthropicClient($this->stubHttp($status, '{"error":"boom"}'), 'test-key', 'claude-sonnet-5', 5);

        return new ExcelColumnMapperService(ai: $ai);
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
