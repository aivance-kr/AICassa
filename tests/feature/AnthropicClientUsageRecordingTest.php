<?php

use App\Libraries\AnthropicClient;
use App\Models\AiUsageCounterModel;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * AnthropicClient 는 실제 외부 호출 성공 직후 usage(호출 수·토큰)를 월간 카운터에 기록한다.
 * 로그인 사용자가 있는 컨텍스트(Admin 세션)에서만 기록되며, 텍스트 응답 자체는 기록 성패와 무관하다.
 *
 * @internal
 */
final class AnthropicClientUsageRecordingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AuthenticationTesting;

    protected $namespace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'usage3', 'email' => 'usage3@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());
    }

    /**
     * 로그인 사용자 컨텍스트에서 completeText() 성공 시 usage(calls=1, 토큰)가 카운터에 기록된다.
     */
    public function testCompleteTextRecordsUsageForLoggedInUser(): void
    {
        $this->actingAs($this->user);

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => '응답']],
            'usage'   => ['input_tokens' => 120, 'output_tokens' => 45],
        ]);
        $client = $this->client(200, (string) $body);

        $client->completeText('질문');

        $usage = model(AiUsageCounterModel::class)->getUsage((int) $this->user->id, AiUsageCounterModel::currentPeriod());
        $this->assertSame(['calls' => 1, 'input_tokens' => 120, 'output_tokens' => 45], $usage);
    }

    /**
     * 로그인 사용자가 없으면(비정상 흐름) 기록을 건너뛴다 — 예외 없이 텍스트만 반환.
     */
    public function testNoUsageRecordedWithoutLoggedInUser(): void
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => '응답']],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
        $client = $this->client(200, (string) $body);

        $result = $client->completeText('질문');

        $this->assertSame('응답', $result);
    }

    private function client(int $status, string $body): AnthropicClient
    {
        $http = new class ($status, $body) extends CURLRequest {
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

        return new AnthropicClient($http, 'test-key', 'claude-sonnet-5', 15);
    }
}
