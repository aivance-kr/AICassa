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
    protected $allowedFields = ['useful_life', 'straight_line_rate', 'declining_balance_rate'];

    /**
     * 상각방법 + 내용연수로 상각률을 조회한다. 없으면 null.
     */
    public function rateFor(DepreciationMethod $method, int $usefulLife): ?float
    {
        $row = $this->where('useful_life', $usefulLife)->first();
        if ($row === null) {
            return null;
        }

        $column = $method === DepreciationMethod::StraightLine
            ? 'straight_line_rate'
            : 'declining_balance_rate';

        return (float) $row[$column];
    }
}
