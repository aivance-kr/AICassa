<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 사업용 유형·무형자산(자산대장).
 * 감가상각 계산의 기준 데이터. 자산명은 사업장 내 유니크(원본 프로그램 제약).
 */
class CreateAssets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                       => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'              => ['type' => 'BIGINT', 'unsigned' => true],
            'asset_type'               => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'                     => ['type' => 'VARCHAR', 'constraint' => 200],
            'acquired_at'              => ['type' => 'DATE'],
            'acquisition_cost'         => ['type' => 'BIGINT'],
            'disposed_at'              => ['type' => 'DATE', 'null' => true],
            'disposal_amount'          => ['type' => 'BIGINT', 'null' => true],
            'depreciation_method'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'useful_life'              => ['type' => 'INT', 'null' => true],
            'depreciation_rate'        => ['type' => 'DECIMAL', 'constraint' => '6,4', 'null' => true],
            'accumulated_depreciation' => ['type' => 'BIGINT', 'default' => 0],
            'created_at'               => ['type' => 'DATETIME', 'null' => true],
            'updated_at'               => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'               => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('business_id', false, false, 'idx_assets_business_id');
        $this->forge->addUniqueKey(['business_id', 'name'], 'uniq_assets_name');
        $this->forge->addForeignKey('business_id', 'businesses', 'id', '', 'CASCADE');
        $this->forge->createTable('assets', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('assets', true);
    }
}
