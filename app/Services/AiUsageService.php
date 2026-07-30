<?php

namespace App\Services;

use App\Models\AiUsageCounterModel;
use Config\AiBudget;

/**
 * 사용자 단위 월간 AI 사용량 집계·상한 판정(유스케이스).
 *
 * 실제 외부 호출부({@see \App\Libraries\AnthropicClient})가 호출 직후 usage 를 넘기면
 * 이 서비스가 영속 카운터에 원자적으로 누적한다. 상한 판정은 이번 호출 "이전"까지의
 * 누적치로 이뤄지므로({@see AiBudgetFilter}), 어느 호출이 상한을 넘겨도 그 호출 자체는
 * 막지 못하지만 다음 호출부터는 차단된다(레이트 리밋과 동일한 보수적 원칙).
 */
final class AiUsageService
{
    private AiUsageCounterModel $counters;

    public function __construct(?AiUsageCounterModel $counters = null)
    {
        $this->counters = $counters ?? model(AiUsageCounterModel::class);
    }

    /**
     * 실제 외부 호출 1건분 사용량을 이번 달 누적치에 더한다.
     */
    public function record(int $userId, int $inputTokens, int $outputTokens): void
    {
        $this->counters->incrementUsage($userId, AiUsageCounterModel::currentPeriod(), 1, $inputTokens, $outputTokens);
    }

    /**
     * 이번 달 누적 사용량이 이미 상한(호출 수 또는 토큰 수) 이상이면 true.
     * 상한(cap)이 0 이면 해당 항목은 무제한으로 간주한다.
     */
    public function exceeds(int $userId, AiBudget $config): bool
    {
        $usage = $this->counters->getUsage($userId, AiUsageCounterModel::currentPeriod());

        if ($config->monthlyCallCap > 0 && $usage['calls'] >= $config->monthlyCallCap) {
            return true;
        }

        return (bool) ($config->monthlyTokenCap > 0
            && ($usage['input_tokens'] + $usage['output_tokens']) >= $config->monthlyTokenCap);
    }
}
