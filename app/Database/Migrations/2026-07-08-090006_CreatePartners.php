<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 거래처.
 * 원본 프로그램은 상호·사업자등록번호 중복불가. SaaS에서는 사업장 스코프 내 유니크로 둔다.
 */
class CreatePartners extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'biz_reg_no'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'phone'       => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('business_id', false, false, 'idx_partners_business_id');
        $this->forge->addUniqueKey(['business_id', 'biz_reg_no'], 'uniq_partners_biz_reg_no');
        $this->forge->addForeignKey('business_id', 'businesses', 'id', '', 'CASCADE');
        $this->forge->createTable('partners', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('partners', true);
    }
}
