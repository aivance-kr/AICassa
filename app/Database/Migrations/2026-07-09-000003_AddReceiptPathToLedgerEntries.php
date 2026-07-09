<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 장부에 증빙 사진 첨부: ledger_entries 에 receipt_path(선택) 추가.
 * 영수증/세금계산서 AI 판독 시 업로드한 원본 사진의 상대경로(writable/uploads/ 기준)를 보관한다.
 * (조회·정렬 조건으로 쓰이지 않으므로 인덱스는 두지 않는다)
 */
class AddReceiptPathToLedgerEntries extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ledger_entries', [
            'receipt_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'evidence_type',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ledger_entries', 'receipt_path');
    }
}
