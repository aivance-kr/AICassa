<?php

use App\Libraries\AnthropicClient;
use App\Services\TaxQaService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 세무 Q&A(RAG) — 인덱싱·렉시컬 검색·인용 응답·확인 불가·할루시네이션 인용 폐기.
 *
 * @internal
 */
final class TaxQaServiceTest extends CIUnitTestCase
{
    private string $docsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docsPath = dirname(__DIR__) . '/_support/TaxQa';
    }

    public function testRetrieveRanksHeadingMatchFirst(): void
    {
        $hits = (new TaxQaService(docsPath: $this->docsPath))->retrieve('부가세 계산');

        $this->assertNotSame([], $hits);
        $this->assertStringContainsString('부가세', $hits[0]['heading']);
    }

    public function testCodeFenceHashIsNotTreatedAsHeading(): void
    {
        // '# 아래는 코드 주석' 이 별도 섹션 제목으로 잘리지 않고 감가상각 섹션 본문에 남아야 한다.
        $sections = (new TaxQaService(docsPath: $this->docsPath))->retrieve('정액법');

        $this->assertNotSame([], $sections);
        $depreciation = $sections[0];
        $this->assertStringContainsString('감가상각', $depreciation['heading']);
        $this->assertStringContainsString('코드 주석', $depreciation['body']);
        $this->assertStringNotContainsString('코드 주석', $depreciation['heading']);
    }

    public function testNoMatchReturnsUnanswerableWithoutAi(): void
    {
        // 근거에 없는 주제 → 검색 0건 → AI 호출 없이 확인 불가.
        $service = $this->serviceWithAi('{"answerable":true,"answer":"엉뚱","sources":["S1"]}');

        $answer = $service->ask('블록체인 채굴 난이도');

        $this->assertFalse($answer->answerable);
        $this->assertSame([], $answer->sources);
    }

    public function testAnswersWithCitedSources(): void
    {
        $service = $this->serviceWithAi('{"answerable":true,"answer":"과세 증빙은 10%.","sources":["S1"]}');

        $answer = $service->ask('세금계산서 부가세는 어떻게 계산돼?');

        $this->assertTrue($answer->answerable);
        $this->assertStringContainsString('10%', $answer->answer);
        $this->assertNotSame([], $answer->sources);
        $this->assertSame('sample.md', $answer->sources[0]->doc);
    }

    public function testUnanswerableWhenModelSaysSo(): void
    {
        $service = $this->serviceWithAi('{"answerable":false,"answer":"근거 문서에서 확인되지 않는 내용입니다.","sources":[]}');

        $answer = $service->ask('부가세 세율 알려줘');

        $this->assertFalse($answer->answerable);
        $this->assertSame([], $answer->sources);
    }

    public function testHallucinatedSourceIsDropped(): void
    {
        // 검색되지 않은 S99 인용 → 폐기 → 유효 출처 0 → 확인 불가 처리.
        $service = $this->serviceWithAi('{"answerable":true,"answer":"단정","sources":["S99"]}');

        $answer = $service->ask('부가세 계산 방법');

        $this->assertFalse($answer->answerable);
        $this->assertSame([], $answer->sources);
    }

    public function testGracefulWhenAiFails(): void
    {
        $service = $this->serviceWithAiStatus(500);

        $answer = $service->ask('부가세 계산 방법');

        $this->assertFalse($answer->answerable);
        $this->assertSame([], $answer->sources);
    }

    public function testAiDisabledReturnsUnanswerable(): void
    {
        // AI 미주입 — 검색은 되지만 답변 불가.
        $answer = (new TaxQaService(docsPath: $this->docsPath))->ask('부가세 계산 방법');

        $this->assertFalse($answer->answerable);
    }

    public function testSourceViewerLookup(): void
    {
        $service = new TaxQaService(docsPath: $this->docsPath);
        $hit     = $service->retrieve('부가세')[0];

        $section = $service->section($hit['doc'], $hit['anchor']);

        $this->assertNotNull($section);
        $this->assertSame($hit['heading'], $section['heading']);
        // 경로우회·미존재 앵커는 null.
        $this->assertNull($service->section('sample.md', 'sec-999'));
        $this->assertNull($service->section('../evil.md', 'sec-1'));
    }

    private function serviceWithAi(string $modelText): TaxQaService
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);
        $ai   = new AnthropicClient($this->stubHttp(200, $body), 'test-key', 'claude-sonnet-5', 5);

        return new TaxQaService(ai: $ai, docsPath: $this->docsPath);
    }

    private function serviceWithAiStatus(int $status): TaxQaService
    {
        $ai = new AnthropicClient($this->stubHttp($status, '{"error":"boom"}'), 'test-key', 'claude-sonnet-5', 5);

        return new TaxQaService(ai: $ai, docsPath: $this->docsPath);
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
