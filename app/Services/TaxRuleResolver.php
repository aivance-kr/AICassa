<?php

namespace App\Services;

use App\DTOs\TaxRuleSet;
use Config\TaxRules as TaxRulesConfig;
use RuntimeException;

/**
 * 귀속연도에 적용할 스칼라 세법 룰셋(TaxRuleSet)을 시행연도 기준으로 해석한다.
 *
 * as-of 규칙: 요청 귀속연도 이하의 시행연도 중 가장 최근 룰셋을 선택한다.
 * 요청 연도가 최초 시행연도보다 앞서면 가장 이른 룰셋으로 폴백한다.
 */
final class TaxRuleResolver
{
    private TaxRulesConfig $config;

    public function __construct(?TaxRulesConfig $config = null)
    {
        $this->config = $config ?? config(TaxRulesConfig::class);
    }

    /**
     * 귀속연도에 유효한 세법 룰셋을 반환한다.
     */
    public function forYear(int $fiscalYear): TaxRuleSet
    {
        $effectiveYear = null;

        foreach (array_keys($this->config->sets) as $year) {
            if ($year <= $fiscalYear && ($effectiveYear === null || $year > $effectiveYear)) {
                $effectiveYear = $year;
            }
        }

        // 요청 연도가 최초 시행연도 이전이면 가장 이른 룰셋을 사용한다.
        if ($effectiveYear === null) {
            $years = array_keys($this->config->sets);
            if ($years === []) {
                throw new RuntimeException('세법 룰셋(Config\TaxRules::$sets)이 비어 있습니다.');
            }
            $effectiveYear = min($years);
        }

        return TaxRuleSet::fromArray($effectiveYear, $this->config->sets[$effectiveYear]);
    }
}
