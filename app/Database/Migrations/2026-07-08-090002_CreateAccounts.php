<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 계정과목(참조 데이터).
 * category: income(수입) / expense(비용) / asset(사업용 자산)
 * is_manufacturing: 제조업 전용 비용 계정 여부(재료매입·제조노무비·제조경비 등)
 */
class CreateAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'category'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 50],
            'is_manufacturing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'sort_order'       => ['type' => 'INT', 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('category', false, false, 'idx_accounts_category');
        $this->forge->createTable('accounts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('accounts', true);
    }
}
