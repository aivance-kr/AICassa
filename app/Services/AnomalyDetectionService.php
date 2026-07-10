<?php

namespace App\Services;

use App\DTOs\AnomalyFinding;
use App\DTOs\AnomalyReport;
use App\Enums\AnomalySeverity;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Libraries\AnthropicClient;
use Throwable;

/**
 * 신고·결산 전 이상탐지(유스케이스).
 *
 * 확정 전 장부를 스캔해 세무 리스크 경고 리포트를 만든다.
 * 원칙: 금액·집계는 모두 도메인 서비스(부가세·집계)가 재계산한다 — AI 는 숫자를 만들지 않는다.
 * 결정적 규칙(R1~R5)이 근거와 함께 경고를 생성하고, AI 는 계정과목 오분류 "의심"만 덧붙인다
 * (키 없으면 생략, 그레이스풀).
 */
final class AnomalyDetectionService
{
    /**
     * 적격증빙 수취의무 기준(경비 3만원 초과).
     */
    private const ADEQUATE_EVIDENCE_MIN = 30_000;

    /**
     * 전년比 급증 판정 배수·최소 규모.
     */
    private const SURGE_RATIO = 3.0;

    private const SURGE_MIN_AMOUNT = 1_000_000;

    /**
     * 기업업무추진비 과다 비중 경고 임계(총비용 대비).
     */
    private const ENTERTAINMENT_RATIO = 0.10;

    private const ENTERTAINMENT_ACCOUNT = '기업업무추진비';

    /**
     * AI 오분류 점검에 넘길 최대 거래 수(토큰 제한).
     */
    private const AI_SAMPLE_LIMIT = 40;

    private LedgerService $ledger;
    private VatCalculatorService $vat;
    private ?AnthropicClient $ai;

    public function __construct(
        ?LedgerService $ledger = null,
        ?VatCalculatorService $vat = null,
        ?AnthropicClient $ai = null,
    ) {
        $this->ledger = $ledger ?? service('ledgerService');
        $this->vat    = $vat ?? service('vatCalculator');
        // AI 는 Services 팩토리에서 env 기반 주입. null 이면 결정적 규칙만 수행.
        $this->ai = $ai;
    }

    /**
     * 귀속연도 장부를 점검해 이상탐지 리포트를 만든다(사업장 소유권은 LedgerService 가 검증).
     */
    public function analyze(int $userId, int $businessId, int $fiscalYear): AnomalyReport
    {
        $entries = $this->ledger->listForBusiness($userId, $businessId, ['fiscal_year' => $fiscalYear]);

        $findings = [];
        $this->push($findings, $this->checkUnclassified($entries));
        $this->push($findings, $this->checkInadequateEvidence($entries));
        $this->push($findings, $this->checkVatIntegrity($entries, $fiscalYear));
        $this->push($findings, $this->checkEntertainmentRatio($entries));
        $this->push($findings, $this->checkYearOverYearSurge($userId, $businessId, $fiscalYear, $entries));

        // AI 오분류 의심(선택). 실패해도 리포트는 유효.
        foreach ($this->checkMisclassificationByAi($entries) as $finding) {
            $findings[] = $finding;
        }

        usort($findings, static fn (AnomalyFinding $a, AnomalyFinding $b): int => $b->severity->weight() <=> $a->severity->weight());

        return new AnomalyReport($fiscalYear, $findings);
    }

    /**
     * R1. 계정과목 미분류 — 수입/비용인데 account_id 가 비어 있으면 신고 전 분류가 필요하다.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function checkUnclassified(array $entries): ?AnomalyFinding
    {
        $count = 0;

        foreach ($entries as $entry) {
            $type = (string) $entry['entry_type'];
            if (($type === EntryType::Income->value || $type === EntryType::Expense->value)
                && ($entry['account_id'] === null || $entry['account_id'] === '')) {
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return new AnomalyFinding(
            AnomalySeverity::High,
            'UNCLASSIFIED_ACCOUNT',
            "계정과목이 비어 있는 거래 {$count}건",
            "규칙: 수입·비용 거래는 계정과목이 있어야 신고서식에 집계됩니다. 미분류 {$count}건을 확인·분류하세요.",
        );
    }

    /**
     * R2. 증빙 미비 고액 지출 — 3만원 초과인데 간이영수증·기타 증빙으로 처리된 비용.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function checkInadequateEvidence(array $entries): ?AnomalyFinding
    {
        $count   = 0;
        $total   = 0;
        $samples = [];

        foreach ($entries as $entry) {
            if ((string) $entry['entry_type'] !== EntryType::Expense->value) {
                continue;
            }
            $evidence = EvidenceType::tryFrom((string) ($entry['evidence_type'] ?? ''));
            $amount   = (int) $entry['supply_amount'];
            if (($evidence === EvidenceType::SimpleReceipt || $evidence === EvidenceType::Other)
                && $amount > self::ADEQUATE_EVIDENCE_MIN) {
                $count++;
                $total += $amount;
                if (count($samples) < 3) {
                    $samples[] = (string) $entry['entry_date'] . ' ' . (string) $entry['description'] . ' ' . number_format($amount) . '원';
                }
            }
        }

        if ($count === 0) {
            return null;
        }

        $example = implode(' · ', $samples);

        return new AnomalyFinding(
            AnomalySeverity::Warning,
            'INADEQUATE_EVIDENCE',
            "적격증빙 미비 의심 고액 지출 {$count}건 (합계 " . number_format($total) . '원)',
            '규칙: 3만원 초과 경비는 적격증빙(세금계산서·계산서·신용카드·현금영수증) 수취 의무가 있습니다. '
                . "간이영수증·기타 증빙 {$count}건이 대상입니다. 예) {$example}",
        );
    }

    /**
     * R3. 부가세 정합성 — 저장된 부가세가 증빙·공급가액으로 재계산한 값과 다르면 오입력 의심.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function checkVatIntegrity(array $entries, int $fiscalYear): ?AnomalyFinding
    {
        $count = 0;

        foreach ($entries as $entry) {
            $evidence = EvidenceType::tryFrom((string) ($entry['evidence_type'] ?? ''));
            if ($evidence === null) {
                continue;
            }
            $expected = $this->vat->calculate((int) $entry['supply_amount'], $evidence, $fiscalYear);
            if ($expected !== (int) $entry['vat']) {
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return new AnomalyFinding(
            AnomalySeverity::High,
            'VAT_MISMATCH',
            "부가세가 증빙·공급가액과 맞지 않는 거래 {$count}건",
            '규칙: 부가세는 증빙유형·공급가액으로 자동계산됩니다(과세증빙 10%, 면세증빙 0). '
                . "저장값과 재계산값이 다른 {$count}건을 확인하세요(수기 수정·업로드 오류 의심).",
        );
    }

    /**
     * R5. 기업업무추진비 과다 비중 — 총비용 대비 비중이 임계 이상이면 한도 검토를 권고한다.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function checkEntertainmentRatio(array $entries): ?AnomalyFinding
    {
        $entertainment = 0;
        $totalExpense  = 0;

        foreach ($entries as $entry) {
            if ((string) $entry['entry_type'] !== EntryType::Expense->value) {
                continue;
            }
            $amount = (int) $entry['supply_amount'];
            $totalExpense += $amount;
            if ((string) ($entry['account_name'] ?? '') === self::ENTERTAINMENT_ACCOUNT) {
                $entertainment += $amount;
            }
        }

        if ($totalExpense <= 0 || $entertainment <= 0) {
            return null;
        }

        $ratio = $entertainment / $totalExpense;
        if ($ratio < self::ENTERTAINMENT_RATIO) {
            return null;
        }

        $pct = round($ratio * 100, 1);

        return new AnomalyFinding(
            AnomalySeverity::Warning,
            'ENTERTAINMENT_RATIO_HIGH',
            "기업업무추진비 비중 과다 ({$pct}%)",
            '규칙: 기업업무추진비 ' . number_format($entertainment) . '원이 총비용 '
                . number_format($totalExpense) . "원의 {$pct}%입니다. 접대비 한도(수입금액 기준) 초과 여부는 세무사 검토 대상입니다.",
        );
    }

    /**
     * R4. 전년 대비 급증 — 계정과목별 비용 합이 전년의 배수 이상으로 늘면 소명 대상.
     *
     * @param list<array<string, mixed>> $current 당해연도 거래(중복 조회 방지 위해 전달)
     */
    private function checkYearOverYearSurge(int $userId, int $businessId, int $fiscalYear, array $current): ?AnomalyFinding
    {
        $prior = $this->ledger->listForBusiness($userId, $businessId, ['fiscal_year' => $fiscalYear - 1]);
        if ($prior === []) {
            return null; // 비교할 전년 자료 없음
        }

        $curByAccount   = $this->expenseByAccount($current);
        $priorByAccount = $this->expenseByAccount($prior);

        $surged = [];

        foreach ($curByAccount as $account => $curTotal) {
            $priorTotal = $priorByAccount[$account] ?? 0;
            if ($priorTotal <= 0 || $curTotal < self::SURGE_MIN_AMOUNT) {
                continue;
            }
            if ($curTotal / $priorTotal >= self::SURGE_RATIO) {
                $multiple = round($curTotal / $priorTotal, 1);
                $surged[] = "{$account} " . number_format($priorTotal) . '→' . number_format($curTotal) . "원({$multiple}배)";
            }
        }

        if ($surged === []) {
            return null;
        }

        return new AnomalyFinding(
            AnomalySeverity::Warning,
            'YOY_SURGE',
            '전년 대비 급증한 비용 계정 ' . count($surged) . '건',
            '규칙: 전년 대비 ' . self::SURGE_RATIO . '배 이상 늘어난 비용은 소명 자료가 필요할 수 있습니다. '
                . implode(' · ', $surged),
        );
    }

    /**
     * AI 오분류 의심 점검(선택). 거래내용과 계정과목의 부조화를 Claude 로 표시한다.
     * 숫자를 만들지 않으며(제목·근거 텍스트만), 실패 시 빈 배열.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<AnomalyFinding>
     */
    private function checkMisclassificationByAi(array $entries): array
    {
        if ($this->ai === null) {
            return [];
        }

        $rows = [];

        foreach ($entries as $entry) {
            if ((string) $entry['entry_type'] !== EntryType::Expense->value) {
                continue;
            }
            $account = (string) ($entry['account_name'] ?? '');
            $desc    = (string) $entry['description'];
            if ($account === '' || $desc === '') {
                continue;
            }
            $rows[] = "- {$desc} → {$account}";
            if (count($rows) >= self::AI_SAMPLE_LIMIT) {
                break;
            }
        }

        if ($rows === []) {
            return [];
        }

        try {
            $answer   = $this->ai->completeText($this->misclassificationPrompt($rows), 512);
            $suspects = $this->parseAiSuspects($answer);
            $findings = [];

            foreach ($suspects as $suspect) {
                $findings[] = new AnomalyFinding(
                    AnomalySeverity::Info,
                    'MISCLASSIFICATION_SUSPECT',
                    '계정과목 오분류 의심: ' . $suspect['description'],
                    "AI 판단: '{$suspect['description']}'(현재 {$suspect['account']}) → {$suspect['reason']}",
                    'ai',
                );
            }

            return $findings;
        } catch (Throwable $e) {
            log_message('warning', '이상탐지 AI 점검 실패(생략): {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param list<string> $rows
     */
    private function misclassificationPrompt(array $rows): string
    {
        $list = implode("\n", $rows);

        return <<<PROMPT
            아래는 한국 간편장부의 (거래내용 → 계정과목) 목록이다. 계정과목이 명백히 어울리지 않는 항목만 골라
            JSON 배열로만 출력하라(설명·코드펜스 금지). 애매하면 포함하지 마라. 없으면 빈 배열 [] 을 출력하라.
            각 원소: {"description":"거래내용","account":"현재 계정과목","reason":"왜 부적절한지 한 문장"}
            목록:
            {$list}
            PROMPT;
    }

    /**
     * AI JSON 배열을 안전하게 파싱한다(형식 불량은 폐기).
     *
     * @return list<array{description:string, account:string, reason:string}>
     */
    private function parseAiSuspects(string $text): array
    {
        $clean = trim($text);
        $clean = (string) preg_replace('/^```(?:json)?|```$/m', '', $clean);
        $clean = trim($clean);

        /** @var mixed $data */
        $data = json_decode($clean, true);
        if (! is_array($data)) {
            return [];
        }

        $suspects = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            $desc    = isset($item['description']) ? trim((string) $item['description']) : '';
            $account = isset($item['account']) ? trim((string) $item['account']) : '';
            $reason  = isset($item['reason']) ? trim((string) $item['reason']) : '';
            if ($desc !== '' && $account !== '' && $reason !== '') {
                $suspects[] = ['description' => $desc, 'account' => $account, 'reason' => $reason];
            }
        }

        return $suspects;
    }

    /**
     * 비용 계정과목별 공급가액 합.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, int>
     */
    private function expenseByAccount(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            if ((string) $entry['entry_type'] !== EntryType::Expense->value) {
                continue;
            }
            $account = (string) ($entry['account_name'] ?? '');
            if ($account === '') {
                continue;
            }
            $totals[$account] = ($totals[$account] ?? 0) + (int) $entry['supply_amount'];
        }

        return $totals;
    }

    /**
     * null 이 아닌 경고만 목록에 추가한다.
     *
     * @param list<AnomalyFinding> $findings
     */
    private function push(array &$findings, ?AnomalyFinding $finding): void
    {
        if ($finding !== null) {
            $findings[] = $finding;
        }
    }
}
