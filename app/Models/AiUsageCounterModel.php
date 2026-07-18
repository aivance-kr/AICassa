<?php

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * 사용자 단위 월간 AI 호출/토큰 누적 카운터 모델.
 *
 * 동시 요청에서도 정확한 집계가 되도록 증가는 원자적으로 처리한다. MySQL 전용 구문(예:
 * `ON DUPLICATE KEY UPDATE`)은 테스트 DB(SQLite)에서 깨지므로, 드라이버에 무관한
 * "산술 증가 UPDATE 우선 → 없으면 INSERT(경합 시 UPDATE 재시도)" 패턴을 쓴다. UPDATE 문
 * 자체는 서버 측에서 단일 문장으로 실행되므로 동시 요청에도 원자적이다.
 */
class AiUsageCounterModel extends Model
{
    protected $table         = 'ai_usage_counters';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'user_id',
        'period_ym',
        'calls',
        'input_tokens',
        'output_tokens',
    ];

    /**
     * 현재 시각 기준 집계 기간(YYYYMM).
     */
    public static function currentPeriod(): string
    {
        return date('Ym');
    }

    /**
     * 이번 호출분을 원자적으로 누적한다. 행이 없으면 새로 만들고, 있으면 기존 값에 더한다.
     *
     * 먼저 산술 증가 UPDATE 를 시도한다(행이 있으면 이걸로 끝 — 단일 UPDATE 문이라 원자적).
     * 영향받은 행이 없으면(아직 이번 달 첫 호출) INSERT 하되, 동시에 다른 요청이 먼저
     * 만들었을 수 있으므로(유니크 제약 충돌) 그 경우 다시 증가 UPDATE 로 재시도한다.
     */
    public function incrementUsage(int $userId, string $periodYm, int $calls, int $inputTokens, int $outputTokens): void
    {
        $now = date('Y-m-d H:i:s');

        if ($this->incrementExisting($userId, $periodYm, $calls, $inputTokens, $outputTokens, $now)) {
            return;
        }

        try {
            $this->builder()->insert([
                'user_id'       => $userId,
                'period_ym'     => $periodYm,
                'calls'         => $calls,
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } catch (Throwable) {
            // 동시에 다른 요청이 먼저 행을 만들었다면(유니크 제약 충돌) 그 행에 더한다.
            $this->incrementExisting($userId, $periodYm, $calls, $inputTokens, $outputTokens, $now);
        }
    }

    /**
     * 이미 존재하는 행을 산술 증가로 갱신한다. 영향받은 행이 있었으면 true.
     */
    private function incrementExisting(
        int $userId,
        string $periodYm,
        int $calls,
        int $inputTokens,
        int $outputTokens,
        string $now,
    ): bool {
        $this->builder()
            ->where('user_id', $userId)
            ->where('period_ym', $periodYm)
            ->set('calls', "calls + {$calls}", false)
            ->set('input_tokens', "input_tokens + {$inputTokens}", false)
            ->set('output_tokens', "output_tokens + {$outputTokens}", false)
            ->set('updated_at', $now)
            ->update();

        return $this->db->affectedRows() > 0;
    }

    /**
     * 특정 기간의 누적 사용량. 기록이 없으면 0으로 채운 배열을 반환한다.
     *
     * @return array{calls: int, input_tokens: int, output_tokens: int}
     */
    public function getUsage(int $userId, string $periodYm): array
    {
        $row = $this->where('user_id', $userId)
            ->where('period_ym', $periodYm)
            ->first();

        return [
            'calls'         => (int) ($row['calls'] ?? 0),
            'input_tokens'  => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
        ];
    }
}
