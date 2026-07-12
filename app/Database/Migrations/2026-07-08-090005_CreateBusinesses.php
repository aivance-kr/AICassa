<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 사업장(테넌트 하위 단위).
 * 간편장부는 사업장별·소득종류별로 각각 작성하므로 장부/자산/재고의 스코프 기준이 된다.
 */
class CreateBusinesses extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            // Shield users.id 는 INT UNSIGNED 이므로 FK 컬럼 타입을 일치시킨다
            'user_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 200],
            'owner_name'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'birth_date'       => ['type' => 'DATE', 'null' => true],
            'biz_reg_no'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'address'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'phone'            => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'industry_code'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'industry_name'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'income_type'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'is_manufacturing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id', false, false, 'idx_businesses_user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('businesses', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('businesses', true);
    }
}
