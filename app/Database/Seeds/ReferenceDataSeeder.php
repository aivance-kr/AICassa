<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * 참조 데이터 일괄 시드(계정과목 + 상각률 + 업종코드).
 * 실행: php spark db:seed ReferenceDataSeeder
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccountSeeder::class);
        $this->call(DepreciationRateSeeder::class);
        $this->call(IndustryCodeSeeder::class);
    }
}
