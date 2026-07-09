<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * 표준산업분류 업종코드(참조 데이터) 시드.
 *
 * 국세청 간편장부 프로그램 v3.4 '표준산업분류연계표' 시트에서 추출한
 * 2023년 귀속 업종코드 1,611건과 업종별 자산 내용연수를 적재한다.
 * 감가상각 자동계산 시 사업장 주업종코드로 기본 내용연수를 조회하는 데 쓰인다.
 *
 * 데이터: app/Database/Seeds/Data/industry_codes.php
 */
class IndustryCodeSeeder extends Seeder
{
    /**
     * 대량 삽입 청크 크기(플레이스홀더 한도 회피)
     */
    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        /** @var list<array{0:string, 1:string, 2:int|null}> $defs */
        $defs = require __DIR__ . '/Data/industry_codes.php';

        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($defs as [$code, $name, $usefulLife]) {
            $rows[] = [
                'code'        => $code,
                'name'        => $name,
                'useful_life' => $usefulLife,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        // 재실행 안전(멱등): 기존 데이터 비우고 재삽입
        $this->db->table('industry_codes')->emptyTable();

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $this->db->table('industry_codes')->insertBatch($chunk);
        }
    }
}
