<?php

namespace App\Database\Seeds;

use App\Enums\AccountCategory;
use CodeIgniter\Database\Seeder;

/**
 * 계정과목 — 국세청 간편장부 계정 체계.
 * is_manufacturing = 1 인 비용 계정(재료매입·제조노무비·제조경비)은 제조업 사업장에서만 노출.
 */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // [category, name, is_manufacturing]
        $defs = [
            // 수입
            [AccountCategory::Income->value, '매출', 0],
            [AccountCategory::Income->value, '기타(수입)', 0],

            // 비용 (공통)
            [AccountCategory::Expense->value, '상품매입', 0],
            [AccountCategory::Expense->value, '급료', 0],
            [AccountCategory::Expense->value, '제세공과금', 0],
            [AccountCategory::Expense->value, '임차료', 0],
            [AccountCategory::Expense->value, '지급이자', 0],
            [AccountCategory::Expense->value, '기업업무추진비', 0],
            [AccountCategory::Expense->value, '기부금', 0],
            [AccountCategory::Expense->value, '감가상각비', 0],
            [AccountCategory::Expense->value, '차량유지비', 0],
            [AccountCategory::Expense->value, '지급수수료', 0],
            [AccountCategory::Expense->value, '소모품비', 0],
            [AccountCategory::Expense->value, '복리후생비', 0],
            [AccountCategory::Expense->value, '운반비', 0],
            [AccountCategory::Expense->value, '광고선전비', 0],
            [AccountCategory::Expense->value, '여비교통비', 0],
            [AccountCategory::Expense->value, '기타(비용)', 0],

            // 비용 (제조업 전용)
            [AccountCategory::Expense->value, '재료매입', 1],
            [AccountCategory::Expense->value, '제조노무비', 1],
            [AccountCategory::Expense->value, '제조경비', 1],

            // 사업용 자산
            [AccountCategory::Asset->value, '건물 및 구축물', 0],
            [AccountCategory::Asset->value, '차량운반구', 0],
            [AccountCategory::Asset->value, '비품', 0],
            [AccountCategory::Asset->value, '기계장치', 0],
            [AccountCategory::Asset->value, '기타', 0],
        ];

        $rows = [];
        foreach ($defs as $i => [$category, $name, $isMfg]) {
            $rows[] = [
                'category'         => $category,
                'name'             => $name,
                'is_manufacturing' => $isMfg,
                'sort_order'       => $i + 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        // 재실행 안전(멱등). accounts는 FK로 참조되어 TRUNCATE 불가 → DELETE 사용
        $this->db->table('accounts')->emptyTable();
        $this->db->table('accounts')->insertBatch($rows);
    }
}
