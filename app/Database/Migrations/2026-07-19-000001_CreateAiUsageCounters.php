<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 사용자 단위 월간 AI 호출/토큰 누적 카운터.
 * 레이트 리밋(분 단위·캐시)과 달리 월 경계를 넘겨 유지돼야 하므로 영속 저장한다.
 */
class CreateAiUsageCounters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            // Shield users.id 는 INT UNSIGNED 이므로 FK 컬럼 타입을 일치시킨다
            'user_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period_ym'     => ['type' => 'CHAR', 'constraint' => 6], // 예: '202607'
            'calls'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'input_tokens'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'output_tokens' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'period_ym'], 'uniq_ai_usage_counters_user_period');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('ai_usage_counters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_usage_counters', true);
    }
}
