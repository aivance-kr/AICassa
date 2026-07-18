<?php

use App\Models\AiUsageCounterModel;
use App\Services\AiUsageService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\AiBudget;

/**
 * 사용자 단위 월간 AI 사용량 집계·상한 판정 서비스.
 *
 * @internal
 */
final class AiUsageServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace;
    private AiUsageService $service;
    private AiUsageCounterModel $counters;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counters = new AiUsageCounterModel();
        $this->service  = new AiUsageService($this->counters);

        $users = new UserModel();
        $users->save(new User(['username' => 'usage2', 'email' => 'usage2@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();
    }

    /**
     * record() 는 이번 호출 1건분(calls=1)을 토큰과 함께 누적한다.
     */
    public function testRecordAccumulatesCallsAndTokens(): void
    {
        $this->service->record($this->userId, 100, 40);
        $this->service->record($this->userId, 60, 20);

        $usage = $this->counters->getUsage($this->userId, AiUsageCounterModel::currentPeriod());
        $this->assertSame(2, $usage['calls']);
        $this->assertSame(160, $usage['input_tokens']);
        $this->assertSame(60, $usage['output_tokens']);
    }

    /**
     * 상한(cap)이 0(무제한)이면 아무리 사용해도 초과 판정을 하지 않는다.
     */
    public function testExceedsIsFalseWhenCapsAreUnlimited(): void
    {
        $this->service->record($this->userId, 100_000, 100_000);

        $config                  = new AiBudget();
        $config->monthlyCallCap  = 0;
        $config->monthlyTokenCap = 0;

        $this->assertFalse($this->service->exceeds($this->userId, $config));
    }

    /**
     * 누적 호출 수가 상한 이상이면 초과로 판정한다.
     */
    public function testExceedsWhenCallCapReached(): void
    {
        $this->service->record($this->userId, 1, 1);
        $this->service->record($this->userId, 1, 1);

        $config                 = new AiBudget();
        $config->monthlyCallCap = 2;

        $this->assertTrue($this->service->exceeds($this->userId, $config));
    }

    /**
     * 누적 토큰(입력+출력)이 상한 이상이면 초과로 판정한다.
     */
    public function testExceedsWhenTokenCapReached(): void
    {
        $this->service->record($this->userId, 400, 200); // 합계 600

        $config                  = new AiBudget();
        $config->monthlyTokenCap = 600;

        $this->assertTrue($this->service->exceeds($this->userId, $config));
    }

    /**
     * 상한에 아직 못 미치면 초과가 아니다.
     */
    public function testNotExceededBeforeReachingCap(): void
    {
        $this->service->record($this->userId, 10, 10);

        $config                 = new AiBudget();
        $config->monthlyCallCap = 5;

        $this->assertFalse($this->service->exceeds($this->userId, $config));
    }
}
