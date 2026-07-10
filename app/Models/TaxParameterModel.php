<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * 연도별 세법 파라미터(운영자 관리) 모델.
 * 귀속연도 × 파라미터키 범용 키-값 저장소에 대한 조회/해석을 캡슐화한다.
 */
class TaxParameterModel extends Model
{
    /**
     * TaxRuleSet(시행연도별 스칼라 룰셋)을 구성하는 파라미터 키.
     *
     * @var list<string>
     */
    public const RULE_KEYS = ['vat_divisor', 'memorandum_value', 'declining_residual_divisor'];

    protected $table         = 'tax_parameters';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'fiscal_year',
        'param_key',
        'value_type',
        'param_value',
        'category',
        'label',
        'unit',
        'sort_order',
        'description',
    ];

    /**
     * 등록된 귀속연도 목록(내림차순)과 연도별 파라미터 개수.
     *
     * @return list<array{fiscal_year: int, param_count: int}>
     */
    public function registeredYears(): array
    {
        $rows = $this->builder()
            ->select('fiscal_year, COUNT(*) AS param_count')
            ->groupBy('fiscal_year')
            ->orderBy('fiscal_year', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $r): array => [
            'fiscal_year' => (int) $r['fiscal_year'],
            'param_count' => (int) $r['param_count'],
        ], $rows);
    }

    /**
     * 특정 귀속연도에 등록된 전체 파라미터(정렬순).
     *
     * @return list<array<string, mixed>>
     */
    public function paramsForYear(int $fiscalYear): array
    {
        return $this->where('fiscal_year', $fiscalYear)
            ->orderBy('category', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('param_key', 'ASC')
            ->findAll();
    }

    /**
     * 시행연도별 스칼라 세법 룰셋을 DB에서 읽어 TaxRuleResolver 가 소비할 형태로 반환한다.
     * (Config\TaxRules::$sets 와 동일한 shape) — 3개 룰 키를 모두 가진 연도만 포함한다.
     *
     * @return array<int, array{vat_divisor: int, memorandum_value: int, declining_residual_divisor: int}>
     */
    public function ruleSets(): array
    {
        $rows = $this->builder()
            ->select('fiscal_year, param_key, param_value')
            ->whereIn('param_key', self::RULE_KEYS)
            ->get()
            ->getResultArray();

        /** @var array<int, array<string, int>> $byYear */
        $byYear = [];

        foreach ($rows as $row) {
            $byYear[(int) $row['fiscal_year']][(string) $row['param_key']] = (int) $row['param_value'];
        }

        // 3개 룰 키를 모두 갖춘 연도만 유효한 룰셋으로 채택한다.
        $sets = [];

        foreach ($byYear as $year => $params) {
            if (count(array_intersect_key($params, array_flip(self::RULE_KEYS))) === count(self::RULE_KEYS)) {
                $sets[$year] = [
                    'vat_divisor'                => $params['vat_divisor'],
                    'memorandum_value'           => $params['memorandum_value'],
                    'declining_residual_divisor' => $params['declining_residual_divisor'],
                ];
            }
        }

        return $sets;
    }

    /**
     * 가장 최근(최대) 귀속연도. 없으면 null.
     */
    public function latestYear(): ?int
    {
        $row = $this->builder()
            ->select('fiscal_year')
            ->orderBy('fiscal_year', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row === null ? null : (int) $row['fiscal_year'];
    }
}
