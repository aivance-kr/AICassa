<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * 내용연수별 상각률(정액법/정률법) — 국세청 간편장부 프로그램 v3.4 원본 표.
 * 내용연수 2~60년.
 */
class DepreciationRateSeeder extends Seeder
{
    public function run(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['useful_life' => 2, 'straight_line_rate' => 0.5, 'declining_balance_rate' => 0.777],
            ['useful_life' => 3, 'straight_line_rate' => 0.333, 'declining_balance_rate' => 0.632],
            ['useful_life' => 4, 'straight_line_rate' => 0.25, 'declining_balance_rate' => 0.528],
            ['useful_life' => 5, 'straight_line_rate' => 0.2, 'declining_balance_rate' => 0.451],
            ['useful_life' => 6, 'straight_line_rate' => 0.166, 'declining_balance_rate' => 0.394],
            ['useful_life' => 7, 'straight_line_rate' => 0.142, 'declining_balance_rate' => 0.349],
            ['useful_life' => 8, 'straight_line_rate' => 0.125, 'declining_balance_rate' => 0.313],
            ['useful_life' => 9, 'straight_line_rate' => 0.111, 'declining_balance_rate' => 0.284],
            ['useful_life' => 10, 'straight_line_rate' => 0.1, 'declining_balance_rate' => 0.259],
            ['useful_life' => 11, 'straight_line_rate' => 0.09, 'declining_balance_rate' => 0.239],
            ['useful_life' => 12, 'straight_line_rate' => 0.083, 'declining_balance_rate' => 0.221],
            ['useful_life' => 13, 'straight_line_rate' => 0.076, 'declining_balance_rate' => 0.206],
            ['useful_life' => 14, 'straight_line_rate' => 0.071, 'declining_balance_rate' => 0.193],
            ['useful_life' => 15, 'straight_line_rate' => 0.066, 'declining_balance_rate' => 0.182],
            ['useful_life' => 16, 'straight_line_rate' => 0.062, 'declining_balance_rate' => 0.171],
            ['useful_life' => 17, 'straight_line_rate' => 0.058, 'declining_balance_rate' => 0.162],
            ['useful_life' => 18, 'straight_line_rate' => 0.055, 'declining_balance_rate' => 0.154],
            ['useful_life' => 19, 'straight_line_rate' => 0.052, 'declining_balance_rate' => 0.146],
            ['useful_life' => 20, 'straight_line_rate' => 0.05, 'declining_balance_rate' => 0.14],
            ['useful_life' => 21, 'straight_line_rate' => 0.048, 'declining_balance_rate' => 0.133],
            ['useful_life' => 22, 'straight_line_rate' => 0.046, 'declining_balance_rate' => 0.128],
            ['useful_life' => 23, 'straight_line_rate' => 0.044, 'declining_balance_rate' => 0.123],
            ['useful_life' => 24, 'straight_line_rate' => 0.042, 'declining_balance_rate' => 0.118],
            ['useful_life' => 25, 'straight_line_rate' => 0.04, 'declining_balance_rate' => 0.113],
            ['useful_life' => 26, 'straight_line_rate' => 0.039, 'declining_balance_rate' => 0.109],
            ['useful_life' => 27, 'straight_line_rate' => 0.037, 'declining_balance_rate' => 0.106],
            ['useful_life' => 28, 'straight_line_rate' => 0.036, 'declining_balance_rate' => 0.102],
            ['useful_life' => 29, 'straight_line_rate' => 0.035, 'declining_balance_rate' => 0.099],
            ['useful_life' => 30, 'straight_line_rate' => 0.034, 'declining_balance_rate' => 0.096],
            ['useful_life' => 31, 'straight_line_rate' => 0.033, 'declining_balance_rate' => 0.093],
            ['useful_life' => 32, 'straight_line_rate' => 0.032, 'declining_balance_rate' => 0.09],
            ['useful_life' => 33, 'straight_line_rate' => 0.031, 'declining_balance_rate' => 0.087],
            ['useful_life' => 34, 'straight_line_rate' => 0.03, 'declining_balance_rate' => 0.085],
            ['useful_life' => 35, 'straight_line_rate' => 0.029, 'declining_balance_rate' => 0.083],
            ['useful_life' => 36, 'straight_line_rate' => 0.028, 'declining_balance_rate' => 0.08],
            ['useful_life' => 37, 'straight_line_rate' => 0.027, 'declining_balance_rate' => 0.078],
            ['useful_life' => 38, 'straight_line_rate' => 0.027, 'declining_balance_rate' => 0.076],
            ['useful_life' => 39, 'straight_line_rate' => 0.026, 'declining_balance_rate' => 0.074],
            ['useful_life' => 40, 'straight_line_rate' => 0.025, 'declining_balance_rate' => 0.073],
            ['useful_life' => 41, 'straight_line_rate' => 0.025, 'declining_balance_rate' => 0.071],
            ['useful_life' => 42, 'straight_line_rate' => 0.024, 'declining_balance_rate' => 0.069],
            ['useful_life' => 43, 'straight_line_rate' => 0.024, 'declining_balance_rate' => 0.068],
            ['useful_life' => 44, 'straight_line_rate' => 0.023, 'declining_balance_rate' => 0.066],
            ['useful_life' => 45, 'straight_line_rate' => 0.023, 'declining_balance_rate' => 0.065],
            ['useful_life' => 46, 'straight_line_rate' => 0.022, 'declining_balance_rate' => 0.064],
            ['useful_life' => 47, 'straight_line_rate' => 0.022, 'declining_balance_rate' => 0.062],
            ['useful_life' => 48, 'straight_line_rate' => 0.021, 'declining_balance_rate' => 0.061],
            ['useful_life' => 49, 'straight_line_rate' => 0.021, 'declining_balance_rate' => 0.06],
            ['useful_life' => 50, 'straight_line_rate' => 0.02, 'declining_balance_rate' => 0.059],
            ['useful_life' => 51, 'straight_line_rate' => 0.02, 'declining_balance_rate' => 0.058],
            ['useful_life' => 52, 'straight_line_rate' => 0.02, 'declining_balance_rate' => 0.056],
            ['useful_life' => 53, 'straight_line_rate' => 0.019, 'declining_balance_rate' => 0.055],
            ['useful_life' => 54, 'straight_line_rate' => 0.019, 'declining_balance_rate' => 0.054],
            ['useful_life' => 55, 'straight_line_rate' => 0.019, 'declining_balance_rate' => 0.054],
            ['useful_life' => 56, 'straight_line_rate' => 0.018, 'declining_balance_rate' => 0.053],
            ['useful_life' => 57, 'straight_line_rate' => 0.018, 'declining_balance_rate' => 0.052],
            ['useful_life' => 58, 'straight_line_rate' => 0.018, 'declining_balance_rate' => 0.051],
            ['useful_life' => 59, 'straight_line_rate' => 0.017, 'declining_balance_rate' => 0.05],
            ['useful_life' => 60, 'straight_line_rate' => 0.017, 'declining_balance_rate' => 0.049],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        // 재실행 안전(멱등): 기존 데이터 비우고 재삽입 (DELETE, FK 안전)
        $this->db->table('depreciation_rates')->emptyTable();
        $this->db->table('depreciation_rates')->insertBatch($rows);
    }
}
