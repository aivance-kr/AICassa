<?php

namespace App\Services;

use App\DTOs\TaxRuleSet;
use App\Models\TaxParameterModel;
use Config\TaxRules as TaxRulesConfig;
use RuntimeException;

/**
 * 귀속연도에 적용할 스칼라 세법 룰셋(TaxRuleSet)을 시행연도 기준으로 해석한다.
 *
 * as-of 규칙: 요청 귀속연도 이하의 시행연도 중 가장 최근 룰셋을 선택한다.
 * 요청 연도가 최초 시행연도보다 앞서면 가장 이른 룰셋으로 폴백한다.
 *
 * 룰셋 소스는 운영자가 관리하는 DB(tax_parameters)를 우선하며, 비어 있으면(미시드)
 * Config\TaxRules 의 기본값으로 폴백한다. 이로써 운영자 페이지에서 값을 바꾸면
 * 코드 수정 없이 계산에 반영되고, 부트스트랩/단위테스트 상태에서도 기준선(2023)이 보장된다.
 */
final class TaxRuleResolver
{
    private TaxRulesConfig $config;
    private ?TaxParameterModel $params;

    /**
     * 시행연도별 룰셋 메모(요청 단위). 여러 연도 스케줄·배치 반복 조회의 N+1 방지.
     *
     * @var array<int, array{vat_divisor: int, memorandum_value: int, declining_residual_divisor: int, low_value_asset_threshold?: int}>|null
     */
    private ?array $setsCache = null;

    public function __construct(?TaxRulesConfig $config = null, ?TaxParameterModel $params = null)
    {
        $this->config = $config ?? config(TaxRulesConfig::class);
        $this->params = $params; // null 이면 DB 미사용(Config 기본값만) — 단위 테스트용
    }

    /**
     * 귀속연도에 유효한 세법 룰셋을 반환한다.
     */
    public function forYear(int $fiscalYear): TaxRuleSet
    {
        $sets          = $this->sets();
        $effectiveYear = null;

        foreach (array_keys($sets) as $year) {
            if ($year <= $fiscalYear && ($effectiveYear === null || $year > $effectiveYear)) {
                $effectiveYear = $year;
            }
        }

        // 요청 연도가 최초 시행연도 이전이면 가장 이른 룰셋을 사용한다.
        if ($effectiveYear === null) {
            $years = array_keys($sets);
            if ($years === []) {
                throw new RuntimeException('세법 룰셋이 비어 있습니다(DB tax_parameters · Config\TaxRules 모두 없음).');
            }
            $effectiveYear = min($years);
        }

        return TaxRuleSet::fromArray($effectiveYear, $sets[$effectiveYear]);
    }

    /**
     * 시행연도별 룰셋 맵. DB(운영자 관리) 우선, 비어 있으면 Config 기본값.
     *
     * @return array<int, array{vat_divisor: int, memorandum_value: int, declining_residual_divisor: int, low_value_asset_threshold?: int}>
     */
    private function sets(): array
    {
        if ($this->setsCache !== null) {
            return $this->setsCache;
        }

        $dbSets = $this->params?->ruleSets() ?? [];

        return $this->setsCache = $dbSets !== [] ? $dbSets : $this->config->sets;
    }
}
