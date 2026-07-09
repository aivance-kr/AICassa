<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 장부와 자산대장 연결: ledger_entries 에 asset_id(선택) 추가.
 * 자산_구입/자산_매각 전표가 어느 자산에서 파생됐는지 추적한다.
 * (SQLite 테스트 호환을 위해 DB FK 제약 대신 인덱스만 둔다 — 무결성은 앱 레이어에서 보장)
 */
class AddAssetIdToLedgerEntries extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ledger_entries', [
            'asset_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'partner_id',
            ],
        ]);

        $this->forge->addKey('asset_id', false, false, 'idx_ledger_entries_asset_id');
        $this->forge->processIndexes('ledger_entries');
    }

    public function down(): void
    {
        $this->forge->dropColumn('ledger_entries', 'asset_id');
    }
}
