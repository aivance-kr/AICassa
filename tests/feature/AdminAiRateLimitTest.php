<?php

use App\Models\BusinessModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * AI 엔드포인트 레이트 리밋 필터 검증.
 *
 * - 임계치 초과 시 POST(AJAX)는 429 RATE_LIMITED + CSRF 토큰 동봉.
 * - 임계치는 설정으로 조정 가능(capacity).
 * - AI 미설정(ANTHROPIC_API_KEY 없음) 시 외부 호출이 없으므로 레이트 리밋 미적용(폴백 비차단).
 * - GET(자연어 검색)은 초과 시 플래시 에러와 함께 리다이렉트(JSON 429 아님).
 *
 * 실제 Claude 호출은 하지 않는다 — 입력 검증 실패/빈 질의 경로로 컨트롤러가 외부 호출 전 반환한다.
 * (레이트 리밋 필터는 컨트롤러보다 먼저 동작하므로 카운트는 그대로 발생.)
 *
 * @internal
 */
final class AdminAiRateLimitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

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

        // 스로틀 버킷은 캐시에 저장되므로 테스트 간 격리를 위해 비운다.
        cache()->clean();
        // 기본은 AI 비활성·설정 초기화(각 테스트가 명시적으로 켠다).
        $this->disableAi();
        $this->clearThrottleEnv();
    }

    protected function tearDown(): void
    {
        $this->disableAi();
        $this->clearThrottleEnv();
        cache()->clean();
        parent::tearDown();
    }

    /**
     * 임계치(capacity) 초과 시 429 + RATE_LIMITED + CSRF 토큰 + Retry-After.
     */
    public function testExceedingCapacityReturns429RateLimitedJson(): void
    {
        $this->enableAi();
        $this->setCapacity(2);

        // capacity=2 → 처음 2회는 통과(빈 질의라 컨트롤러가 422), 3회째에 필터가 429 로 차단.
        $this->postAsk()->assertStatus(422);
        $this->postAsk()->assertStatus(422);

        $result = $this->postAsk();
        $result->assertStatus(429);
        $result->assertHeader('Retry-After');

        $json = json_decode((string) $result->getJSON(), true);
        $this->assertSame('RATE_LIMITED', $json['error']['code']);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertArrayHasKey('csrf_name', $json);
    }

    /**
     * 임계치는 설정으로 조정 가능(capacity=1 이면 2회째부터 차단).
     */
    public function testThresholdIsConfigurable(): void
    {
        $this->enableAi();
        $this->setCapacity(1);

        $this->postAsk()->assertStatus(422);
        $this->postAsk()->assertStatus(429);
    }

    /**
     * AI 미설정 시 외부 비용이 없으므로 레이트 리밋을 걸지 않는다(결정적·폴백 경로 비차단).
     */
    public function testFallbackNotThrottledWhenAiDisabled(): void
    {
        $this->disableAi();       // ANTHROPIC_API_KEY 없음
        $this->setCapacity(1);    // 임계치가 낮아도

        // capacity=1 을 훨씬 넘겨도 429 가 나오지 않고 항상 컨트롤러(422)에 도달.
        $this->postAsk()->assertStatus(422);
        $this->postAsk()->assertStatus(422);
        $this->postAsk()->assertStatus(422);
    }

    /**
     * 킬 스위치(enabled=false)면 키가 있어도 레이트 리밋 미적용.
     */
    public function testKillSwitchDisablesThrottling(): void
    {
        $this->enableAi();
        $this->configureThrottle(false, 1); // enabled=false

        $this->postAsk()->assertStatus(422);
        $this->postAsk()->assertStatus(422);
    }

    /**
     * GET(자연어 검색)은 초과 시 JSON 429 대신 플래시 에러와 함께 리다이렉트한다.
     */
    public function testGetSearchRedirectsWithFlashWhenThrottled(): void
    {
        $this->enableAi();
        $this->setCapacity(1);

        $url = "/admin/businesses/{$this->businessId}/ledger/search?q=";

        // 1회째: 빈 질의 → 컨트롤러가 장부 목록으로 리다이렉트(정상, 플래시 에러 없음).
        $this->actingAs($this->user)->get($url)->assertRedirect();
        $this->assertNull(session()->getFlashdata('error'));

        // 2회째: 필터가 차단 → 리다이렉트 + 레이트 리밋 플래시 에러.
        $result = $this->actingAs($this->user)->get($url);
        $result->assertRedirect();
        $this->assertNotNull(session()->getFlashdata('error'));
    }

    /**
     * tax-qa/ask 에 빈 질의로 POST(외부 호출 없이 컨트롤러 422 경로). 레이트 리밋 필터는 그 전에 카운트.
     */
    private function postAsk(): TestResponse
    {
        return $this->actingAs($this->user)->post('/admin/tax-qa/ask', ['question' => '']);
    }

    private function enableAi(): void
    {
        // env() 는 $_ENV → $_SERVER → getenv 순으로 읽으므로 슈퍼글로벌에 직접 주입한다.
        $_ENV['ANTHROPIC_API_KEY'] = $_SERVER['ANTHROPIC_API_KEY'] = 'test-key';
    }

    private function disableAi(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);
        putenv('ANTHROPIC_API_KEY');
    }

    private function setCapacity(int $capacity): void
    {
        $this->configureThrottle(true, $capacity);
    }

    /**
     * 레이트 리밋 임계치를 env 로 주입하고 Config 팩토리를 리셋해 요청 시 재구성되게 한다.
     * (Config 객체 직접 변경은 요청 간 재구성 시 유실될 수 있어 env 경로로 확정한다.)
     */
    private function configureThrottle(bool $enabled, int $capacity): void
    {
        $_ENV['ai.rateLimit.enabled']  = $_SERVER['ai.rateLimit.enabled'] = $enabled ? '1' : '0';
        $_ENV['ai.rateLimit.capacity'] = $_SERVER['ai.rateLimit.capacity'] = (string) $capacity;
        $_ENV['ai.rateLimit.seconds']  = $_SERVER['ai.rateLimit.seconds'] = '60';
        Factories::reset('config');
    }

    private function clearThrottleEnv(): void
    {
        unset(
            $_ENV['ai.rateLimit.enabled'],
            $_SERVER['ai.rateLimit.enabled'],
            $_ENV['ai.rateLimit.capacity'],
            $_SERVER['ai.rateLimit.capacity'],
            $_ENV['ai.rateLimit.seconds'],
            $_SERVER['ai.rateLimit.seconds'],
        );
        Factories::reset('config');
    }
}
