<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use CodeIgniter\Model;

/**
 * 내용연수별 상각률(참조 데이터) 모델.
 */
class DepreciationRateModel extends Model
{
    protected $table         = 'depreciation_rates';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = ['useful_life', 'effective_year', 'straight_line_rate', 'declining_balance_rate'];

    /**
     * 상각방법 + 내용연수로 귀속연도에 유효한 상각률을 조회한다. 없으면 null.
     *
     * as-of 규칙: `시행연도 <= 귀속연도` 중 가장 최근 시행연도의 상각률을 사용한다.
     * 귀속연도가 최초 시행연도 이전이면 가장 이른 시행연도 표로 폴백한다.
     */
    public function rateFor(DepreciationMethod $method, int $usefulLife, int $fiscalYear): ?float
    {
        $row = $this->where('useful_life', $usefulLife)
            ->where('effective_year <=', $fiscalYear)
            ->orderBy('effective_year', 'DESC')
            ->first();

        // 최초 시행연도 이전 취득분은 가장 이른 시행연도 표를 적용한다.
        if ($row === null) {
            $row = $this->where('useful_life', $usefulLife)
                ->orderBy('effective_year', 'ASC')
                ->first();
        }
        if ($row === null) {
            return null;
        }

        $column = $method === DepreciationMethod::StraightLine
            ? 'straight_line_rate'
            : 'declining_balance_rate';

        return (float) $row[$column];
    }
}
