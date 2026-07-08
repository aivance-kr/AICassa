<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 내용연수별 상각률(참조 데이터).
 * straight_line: 정액법 상각률 / declining_balance: 정률법 상각률
 */
class CreateDepreciationRates extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                     => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'useful_life'            => ['type' => 'INT'],
            'straight_line_rate'     => ['type' => 'DECIMAL', 'constraint' => '6,4'],
            'declining_balance_rate' => ['type' => 'DECIMAL', 'constraint' => '6,4'],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('useful_life', 'uniq_depreciation_rates_useful_life');
        $this->forge->createTable('depreciation_rates', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('depreciation_rates', true);
    }
}
