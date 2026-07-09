<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 재고(매출원가 계산용). 사업장·귀속연도별 1건.
 * 매출원가 = 기초재고 + 당기매입 - 기말재고 (상품/재료 각각).
 * 당해 기초재고는 전기 기말재고와 연계된다.
 */
class CreateInventories extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'     => ['type' => 'BIGINT', 'unsigned' => true],
            'fiscal_year'     => ['type' => 'SMALLINT', 'unsigned' => true],
            'goods_begin'     => ['type' => 'BIGINT', 'default' => 0],
            'goods_end'       => ['type' => 'BIGINT', 'default' => 0],
            'materials_begin' => ['type' => 'BIGINT', 'default' => 0],
            'materials_end'   => ['type' => 'BIGINT', 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'fiscal_year'], 'uniq_inventories_business_year');
        $this->forge->addForeignKey('business_id', 'businesses', 'id', '', 'CASCADE');
        $this->forge->createTable('inventories', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('inventories', true);
    }
}
