<?php

use App\Libraries\AnthropicClient;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Anthropic Messages API 공통 클라이언트 — 텍스트/Vision 호출의 상태판정·파싱·타임아웃·페이로드 검증.
 * 실제 네트워크 대신 요청 옵션을 포착하는 CURLRequest 스텁으로 검증한다.
 *
 * @internal
 */
final class AnthropicClientTest extends CIUnitTestCase
{
    /**
     * 포착된 요청 옵션(json·timeout·headers 등)을 담는다.
     *
     * @var array<string, mixed>
     */
    private array $captured = [];

    /**
     * 텍스트 호출은 text 블록 하나만 보내고, 기본 타임아웃(15초)을 사용한다.
     */
    public function testCompleteTextSendsTextBlockAndReturnsOutput(): void
    {
        $client = $this->client(200, $this->body('안녕하세요'));

        $result = $client->completeText('질문', 128);

        $this->assertSame('안녕하세요', $result);
        $this->assertSame(15, $this->captured['timeout']);
        $this->assertSame(128, $this->captured['json']['max_tokens']);

        $content = $this->captured['json']['messages'][0]['content'];
        $this->assertCount(1, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('질문', $content[0]['text']);
    }

    /**
     * Vision 호출은 image 블록 + text 블록을 보내고, 기본 타임아웃(25초)을 사용한다.
     */
    public function testCompleteVisionSendsImageBlockWithDefaultTimeout(): void
    {
        $client = $this->client(200, $this->body('{"ok":true}'));

        $result = $client->completeVision('BASE64DATA', 'image/png', '분석하라');

        $this->assertSame('{"ok":true}', $result);
        $this->assertSame(25, $this->captured['timeout']);

        $content = $this->captured['json']['messages'][0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('base64', $content[0]['source']['type']);
        $this->assertSame('image/png', $content[0]['source']['media_type']);
        $this->assertSame('BASE64DATA', $content[0]['source']['data']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertSame('분석하라', $content[1]['text']);
    }

    /**
     * Vision 타임아웃은 호출측에서 오버라이드할 수 있다.
     */
    public function testCompleteVisionHonoursCustomTimeout(): void
    {
        $client = $this->client(200, $this->body('x'));

        $client->completeVision('DATA', 'image/jpeg', '프롬프트', 512, 40);

        $this->assertSame(40, $this->captured['timeout']);
        $this->assertSame(512, $this->captured['json']['max_tokens']);
    }

    /**
     * 200 이 아니면 RuntimeException 으로 전환된다(텍스트·Vision 공통 경로).
     */
    public function testNon200StatusThrows(): void
    {
        $client = $this->client(429, '{"error":"rate_limited"}');

        $this->expectException(RuntimeException::class);
        $client->completeVision('DATA', 'image/png', '프롬프트');
    }

    /**
     * 텍스트 블록이 없는 응답은 형식 오류로 전환된다.
     */
    public function testMalformedResponseThrows(): void
    {
        $client = $this->client(200, '{"content":[]}');

        $this->expectException(RuntimeException::class);
        $client->completeText('질문');
    }

    /**
     * content[0].text 형태의 200 응답 바디를 만든다.
     */
    private function body(string $text): string
    {
        return (string) json_encode(['content' => [['type' => 'text', 'text' => $text]]]);
    }

    /**
     * 요청 옵션을 $this->captured 에 포착하는 CURLRequest 스텁으로 클라이언트를 만든다.
     */
    private function client(int $status, string $body): AnthropicClient
    {
        // 스텁이 포착한 요청 옵션을 이 테스트의 $captured 로 되돌려받기 위해 참조를 전달한다.
        $sink = &$this->captured;

        $http = new class ($status, $body, $sink) extends CURLRequest {
            /**
             * @param array<string, mixed> $sink
             */
            public function __construct(private int $stubStatus, private string $stubBody, private array &$sink)
            {
                // 부모 생성자(네트워크 설정)는 건너뛴다.
            }

            public function request($method, string $url, array $options = []): ResponseInterface
            {
                $this->sink = $options;

                $response = new Response(config('App'));
                $response->setStatusCode($this->stubStatus);
                $response->setBody($this->stubBody);

                return $response;
            }
        };

        return new AnthropicClient($http, 'test-key', 'claude-sonnet-5', 15);
    }
}
