<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 세무조정(간편장부 소득금액계산서 ⑫⑬⑯⑰⑳㉑). 사업장·귀속연도별 1건.
 */
class CreateTaxAdjustments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'        => ['type' => 'BIGINT', 'unsigned' => true],
            'fiscal_year'        => ['type' => 'SMALLINT', 'unsigned' => true],
            'revenue_exclude'    => ['type' => 'BIGINT', 'default' => 0], // ⑫ 수입금액에서 제외할 금액
            'revenue_add'        => ['type' => 'BIGINT', 'default' => 0], // ⑬ 수입금액에 가산할 금액
            'expense_exclude'    => ['type' => 'BIGINT', 'default' => 0], // ⑯ 필요경비에서 제외할 금액
            'expense_add'        => ['type' => 'BIGINT', 'default' => 0], // ⑰ 필요경비에 가산할 금액
            'donation_over'      => ['type' => 'BIGINT', 'default' => 0], // ⑳ 기부금 한도초과액
            'donation_carryover' => ['type' => 'BIGINT', 'default' => 0], // ㉑ 기부금이월액 중 필요경비산입액
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'fiscal_year'], 'uniq_tax_adjustments_business_year');
        $this->forge->addForeignKey('business_id', 'businesses', 'id', '', 'CASCADE');
        $this->forge->createTable('tax_adjustments', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tax_adjustments', true);
    }
}
