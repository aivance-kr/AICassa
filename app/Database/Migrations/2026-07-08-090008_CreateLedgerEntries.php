<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 장부(거래) — 핵심 테이블.
 * entry_type: income(수입) / expense(비용) / asset_purchase(자산_구입) / asset_disposal(자산_매각)
 * supply_amount: 공급가액(자산 매각은 음수). vat: 증빙유형 기반 자동계산 부가세.
 */
class CreateLedgerEntries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'BIGINT', 'unsigned' => true],
            'fiscal_year'   => ['type' => 'SMALLINT', 'unsigned' => true],
            'entry_date'    => ['type' => 'DATE'],
            'entry_type'    => ['type' => 'VARCHAR', 'constraint' => 20],
            'account_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'partner_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'description'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'supply_amount' => ['type' => 'BIGINT', 'default' => 0],
            'vat'           => ['type' => 'BIGINT', 'default' => 0],
            'evidence_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['business_id', 'fiscal_year'], false, false, 'idx_ledger_entries_business_year');
        $this->forge->addKey('entry_date', false, false, 'idx_ledger_entries_entry_date');
        $this->forge->addKey('entry_type', false, false, 'idx_ledger_entries_entry_type');
        $this->forge->addForeignKey('business_id', 'businesses', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('partner_id', 'partners', 'id', '', 'SET NULL');
        $this->forge->createTable('ledger_entries', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ledger_entries', true);
    }
}
