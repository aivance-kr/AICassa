<?php

use App\Models\AiUsageCounterModel;
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
 * AI 엔드포인트 월간 예산 상한 필터 검증.
 *
 * - 이번 달 누적 사용량(호출 수·토큰 수)이 이미 상한 이상이면 새 호출 전에 차단한다.
 * - POST(AJAX 어시스트)는 429 MONTHLY_CAP_EXCEEDED + CSRF 토큰 동봉, GET(검색)은 플래시 리다이렉트.
 * - AI 미설정(ANTHROPIC_API_KEY 없음) 시 외부 호출이 없으므로 상한을 적용하지 않는다(폴백 비차단).
 * - 킬 스위치(enabled=false) 면 상한을 넘겼어도 미적용.
 *
 * 실제 Claude 호출은 하지 않는다 — 카운터를 직접 시딩해 "이미 이번 달 상한을 넘긴 상태"를 재현하고,
 * 레이트 리밋 필터가 함께 걸려 있으므로 그쪽 임계치는 넉넉히 올려 상호작용을 배제한다.
 *
 * @internal
 */
final class AdminAiBudgetTest extends CIUnitTestCase
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
        $users->save(new User(['username' => 'budget1', 'email' => 'budget1@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->user->id, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();

        cache()->clean();
        $this->disableAi();
        $this->clearEnv();
        // 레이트 리밋은 이 테스트의 관심사가 아니므로 넉넉히 열어둔다(상호작용 배제).
        $this->raiseRateLimit();
    }

    protected function tearDown(): void
    {
        $this->disableAi();
        $this->clearEnv();
        cache()->clean();
        parent::tearDown();
    }

    /**
     * 누적 호출 수가 상한 이상이면 새 호출은 429 MONTHLY_CAP_EXCEEDED.
     */
    public function testCallCapExceededReturns429(): void
    {
        $this->enableAi();
        $this->configureBudget(true, callCap: 3, tokenCap: 0);
        $this->seedUsage(calls: 3, inputTokens: 0, outputTokens: 0);

        $result = $this->postAsk();
        $result->assertStatus(429);

        $json = json_decode((string) $result->getJSON(), true);
        $this->assertSame('MONTHLY_CAP_EXCEEDED', $json['error']['code']);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertArrayHasKey('csrf_name', $json);
    }

    /**
     * 누적 토큰(입력+출력)이 상한 이상이면 새 호출은 429 MONTHLY_CAP_EXCEEDED.
     */
    public function testTokenCapExceededReturns429(): void
    {
        $this->enableAi();
        $this->configureBudget(true, callCap: 0, tokenCap: 1000);
        $this->seedUsage(calls: 1, inputTokens: 700, outputTokens: 300);

        $this->postAsk()->assertStatus(429);
    }

    /**
     * 아직 상한 미만이면 필터를 통과해 컨트롤러(빈 질의 → 422)에 도달한다.
     */
    public function testUnderCapPassesThrough(): void
    {
        $this->enableAi();
        $this->configureBudget(true, callCap: 3, tokenCap: 0);
        $this->seedUsage(calls: 2, inputTokens: 0, outputTokens: 0);

        $this->postAsk()->assertStatus(422);
    }

    /**
     * AI 미설정 시 외부 비용이 없으므로 상한을 적용하지 않는다(결정적·폴백 경로 비차단).
     */
    public function testFallbackNotBlockedWhenAiDisabled(): void
    {
        $this->disableAi();
        $this->configureBudget(true, callCap: 1, tokenCap: 0);
        $this->seedUsage(calls: 99, inputTokens: 0, outputTokens: 0);

        $this->postAsk()->assertStatus(422);
    }

    /**
     * 킬 스위치(enabled=false)면 상한을 넘겼어도 미적용.
     */
    public function testKillSwitchDisablesBudgetCheck(): void
    {
        $this->enableAi();
        $this->configureBudget(false, callCap: 1, tokenCap: 0);
        $this->seedUsage(calls: 99, inputTokens: 0, outputTokens: 0);

        $this->postAsk()->assertStatus(422);
    }

    /**
     * GET(자연어 검색)은 초과 시 JSON 429 대신 플래시 에러와 함께 리다이렉트한다.
     */
    public function testGetSearchRedirectsWithFlashWhenOverBudget(): void
    {
        $this->enableAi();
        $this->configureBudget(true, callCap: 1, tokenCap: 0);
        $this->seedUsage(calls: 1, inputTokens: 0, outputTokens: 0);

        $result = $this->actingAs($this->user)->get("/admin/businesses/{$this->businessId}/ledger/search?q=");
        $result->assertRedirect();
        $this->assertNotNull(session()->getFlashdata('error'));
    }

    private function postAsk(): TestResponse
    {
        return $this->actingAs($this->user)->post('/admin/tax-qa/ask', ['question' => '']);
    }

    private function seedUsage(int $calls, int $inputTokens, int $outputTokens): void
    {
        $counters = model(AiUsageCounterModel::class);
        $counters->incrementUsage((int) $this->user->id, AiUsageCounterModel::currentPeriod(), $calls, $inputTokens, $outputTokens);
    }

    private function enableAi(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = $_SERVER['ANTHROPIC_API_KEY'] = 'test-key';
    }

    private function disableAi(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);
        putenv('ANTHROPIC_API_KEY');
    }

    /**
     * 레이트 리밋 임계치를 넉넉히 열어 이 테스트(월간 상한)와의 상호작용을 배제한다.
     */
    private function raiseRateLimit(): void
    {
        $_ENV['ai.rateLimit.enabled']  = $_SERVER['ai.rateLimit.enabled'] = '1';
        $_ENV['ai.rateLimit.capacity'] = $_SERVER['ai.rateLimit.capacity'] = '1000';
        $_ENV['ai.rateLimit.seconds']  = $_SERVER['ai.rateLimit.seconds'] = '60';
        Factories::reset('config');
    }

    private function configureBudget(bool $enabled, int $callCap, int $tokenCap): void
    {
        $_ENV['ai.monthlyCap.enabled'] = $_SERVER['ai.monthlyCap.enabled'] = $enabled ? '1' : '0';
        $_ENV['ai.monthlyCap.calls']   = $_SERVER['ai.monthlyCap.calls'] = (string) $callCap;
        $_ENV['ai.monthlyCap.tokens']  = $_SERVER['ai.monthlyCap.tokens'] = (string) $tokenCap;
        Factories::reset('config');
    }

    private function clearEnv(): void
    {
        unset(
            $_ENV['ai.rateLimit.enabled'],
            $_SERVER['ai.rateLimit.enabled'],
            $_ENV['ai.rateLimit.capacity'],
            $_SERVER['ai.rateLimit.capacity'],
            $_ENV['ai.rateLimit.seconds'],
            $_SERVER['ai.rateLimit.seconds'],
            $_ENV['ai.monthlyCap.enabled'],
            $_SERVER['ai.monthlyCap.enabled'],
            $_ENV['ai.monthlyCap.calls'],
            $_SERVER['ai.monthlyCap.calls'],
            $_ENV['ai.monthlyCap.tokens'],
            $_SERVER['ai.monthlyCap.tokens'],
        );
        Factories::reset('config');
    }
}
