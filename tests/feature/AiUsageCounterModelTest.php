<?php

use App\Models\AiUsageCounterModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 사용자 단위 월간 AI 사용량 카운터 모델 — 원자적 누적·기본값.
 *
 * @internal
 */
final class AiUsageCounterModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace;
    private AiUsageCounterModel $counters;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counters = new AiUsageCounterModel();

        $users = new UserModel();
        $users->save(new User(['username' => 'usage1', 'email' => 'usage1@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();
    }

    /**
     * 기록이 없으면 0으로 채운 사용량을 반환한다.
     */
    public function testGetUsageDefaultsToZeroWhenNoRow(): void
    {
        $usage = $this->counters->getUsage($this->userId, '202607');

        $this->assertSame(['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0], $usage);
    }

    /**
     * 같은 사용자·기간에 반복 호출하면 값이 새로 덮이지 않고 누적된다(원자적 upsert).
     */
    public function testIncrementUsageAccumulatesForSamePeriod(): void
    {
        $this->counters->incrementUsage($this->userId, '202607', 1, 100, 50);
        $this->counters->incrementUsage($this->userId, '202607', 1, 30, 20);

        $usage = $this->counters->getUsage($this->userId, '202607');

        $this->assertSame(['calls' => 2, 'input_tokens' => 130, 'output_tokens' => 70], $usage);
    }

    /**
     * 다른 월(period_ym)은 별도 행으로 독립 집계된다 — 월 경계 리셋.
     */
    public function testDifferentPeriodsAreIsolated(): void
    {
        $this->counters->incrementUsage($this->userId, '202606', 5, 500, 300);
        $this->counters->incrementUsage($this->userId, '202607', 1, 10, 5);

        $this->assertSame(['calls' => 5, 'input_tokens' => 500, 'output_tokens' => 300], $this->counters->getUsage($this->userId, '202606'));
        $this->assertSame(['calls' => 1, 'input_tokens' => 10, 'output_tokens' => 5], $this->counters->getUsage($this->userId, '202607'));
    }
}
