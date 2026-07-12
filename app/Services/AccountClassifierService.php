<?php

namespace App\Services;

use App\DTOs\AccountSuggestion;
use App\Enums\AccountCategory;
use App\Enums\ClassifierSource;
use App\Enums\EntryType;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\LedgerEntryModel;
use App\Models\PartnerModel;
use Throwable;

/**
 * 계정과목 자동분류(유스케이스).
 *
 * OCR·수기 입력·엑셀 임포트 세 경로가 공유하는 공통 엔진이다. 비용 최소화를 위해
 *   1) 과거 거래 이력(정확일치·거래처)으로 먼저 추천하고(LLM 미사용),
 *   2) 이력이 없을 때만 Claude 로 폴백한다.
 * 분류는 "제안"일 뿐 — 실패해도 예외를 던지지 않고 {@see AccountSuggestion::none()} 을 반환한다.
 */
final class AccountClassifierService
{
    /**
     * 이력 정확일치(거래내용) 추천 확신도.
     */
    private const CONF_EXACT_DESCRIPTION = 0.9;

    /**
     * 이력 거래처 매칭 추천 확신도.
     */
    private const CONF_PARTNER = 0.75;

    /**
     * AI 폴백 추천 확신도(정확일치 매핑 성공 시).
     */
    private const CONF_AI = 0.7;

    /**
     * AI few-shot 표본 개수.
     */
    private const FEWSHOT_LIMIT = 10;

    private LedgerEntryModel $entries;
    private AccountModel $accounts;
    private PartnerModel $partners;
    private ?AnthropicClient $ai;

    public function __construct(
        ?LedgerEntryModel $entries = null,
        ?AccountModel $accounts = null,
        ?PartnerModel $partners = null,
        ?AnthropicClient $ai = null,
    ) {
        $this->entries  = $entries ?? model(LedgerEntryModel::class);
        $this->accounts = $accounts ?? model(AccountModel::class);
        $this->partners = $partners ?? model(PartnerModel::class);
        // AI 클라이언트는 Services 팩토리에서 주입한다(env 기반). null 이면 AI 폴백 비활성 →
        // 이력 기반 추천만 수행하고 절대 외부 호출하지 않는다(테스트 결정성 보장).
        $this->ai = $ai;
    }

    /**
     * 거래내용(+거래처)으로 계정과목을 추천한다.
     *
     * @param string      $description     거래내용(적요)
     * @param string|null $partnerName     거래처 상호(있으면 이력·정확일치에 활용)
     * @param bool        $isManufacturing 제조업 여부(제조 전용 계정 노출 판단)
     */
    public function suggest(
        int $businessId,
        EntryType $entryType,
        string $description,
        ?string $partnerName = null,
        bool $isManufacturing = true,
    ): AccountSuggestion {
        $description = trim($description);
        $category    = $this->categoryFor($entryType);
        if ($category === null || $description === '') {
            return AccountSuggestion::none();
        }

        // 1) 이력 기반 (LLM 미사용, 최우선)
        $fromHistory = $this->fromHistory($businessId, $entryType, $description, $partnerName);
        if ($fromHistory !== null) {
            return $fromHistory;
        }

        // 2) AI 폴백 (이력 miss 시에만)
        return $this->fromAi($businessId, $entryType, $category, $description, $isManufacturing);
    }

    /**
     * 과거 거래 이력으로 추천한다. 정확일치(거래내용) → 거래처 순으로 신뢰한다.
     */
    private function fromHistory(
        int $businessId,
        EntryType $entryType,
        string $description,
        ?string $partnerName,
    ): ?AccountSuggestion {
        // (a) 동일 거래내용을 과거에 쓴 적이 있으면 그때의 계정과목이 가장 확실하다.
        $byDescription = $this->entries->accountUsage($businessId, $entryType->value, $description);
        if ($byDescription !== []) {
            return $this->toSuggestion((int) array_key_first($byDescription), self::CONF_EXACT_DESCRIPTION, ClassifierSource::History);
        }

        // (b) 같은 거래처의 과거 거래에서 가장 자주 쓴 계정과목.
        $partnerId = $this->resolvePartnerId($businessId, $partnerName);
        if ($partnerId !== null) {
            $byPartner = $this->entries->accountUsage($businessId, $entryType->value, null, $partnerId);
            if ($byPartner !== []) {
                return $this->toSuggestion((int) array_key_first($byPartner), self::CONF_PARTNER, ClassifierSource::History);
            }
        }

        return null;
    }

    /**
     * Claude 로 계정과목을 추론한다. 실패·비활성 시 조용히 none() 을 반환한다(그레이스풀 폴백).
     */
    private function fromAi(
        int $businessId,
        EntryType $entryType,
        AccountCategory $category,
        string $description,
        bool $isManufacturing,
    ): AccountSuggestion {
        if ($this->ai === null) {
            return AccountSuggestion::none();
        }

        try {
            $prompt = $this->buildPrompt($businessId, $entryType, $category, $description, $isManufacturing);
            $answer = $this->ai->completeText($prompt, 64);
            $name   = $this->normalizeName($answer);
            if ($name === null) {
                return AccountSuggestion::none();
            }

            // AI 출력도 불신 — 반드시 실제 계정과목과 정확일치할 때만 채택한다(닫힌 어휘).
            $account = $this->accounts->findByCategoryName($category, $name);
            if ($account === null) {
                return AccountSuggestion::none();
            }

            return new AccountSuggestion((int) $account['id'], (string) $account['name'], self::CONF_AI, ClassifierSource::Ai);
        } catch (Throwable $e) {
            log_message('warning', '계정과목 AI 분류 실패(폴백): {msg}', ['msg' => $e->getMessage()]);

            return AccountSuggestion::none();
        }
    }

    /**
     * 계정과목·few-shot 이력을 담은 닫힌 어휘 프롬프트를 만든다.
     */
    private function buildPrompt(
        int $businessId,
        EntryType $entryType,
        AccountCategory $category,
        string $description,
        bool $isManufacturing,
    ): string {
        $names = array_map(
            'strval',
            array_column($this->accounts->forCategory($category, $isManufacturing), 'name'),
        );
        $vocabulary = implode(', ', $names);

        $examples     = $this->buildExamples($businessId, $entryType);
        $exampleBlock = $examples === '' ? '' : "\n참고(이 사업장의 과거 분류 예시):\n{$examples}\n";

        return <<<PROMPT
            너는 한국 간편장부 계정과목 분류기다. 아래 거래내용에 가장 알맞은 계정과목 하나만 고른다.
            반드시 아래 목록의 문자열과 100% 동일하게, 계정과목명만 출력하라(설명·따옴표·문장 금지). 확신이 없으면 정확히 NONE 이라고만 출력하라.
            계정과목 목록: {$vocabulary}
            {$exampleBlock}
            거래내용: {$description}
            PROMPT;
    }

    /**
     * few-shot 예시 블록(거래내용 → 계정과목명). 이력이 없으면 빈 문자열.
     */
    private function buildExamples(int $businessId, EntryType $entryType): string
    {
        $samples = $this->entries->recentClassified($businessId, $entryType->value, self::FEWSHOT_LIMIT);
        if ($samples === []) {
            return '';
        }

        $nameMap = $this->accounts->nameMap();
        $lines   = [];

        foreach ($samples as $sample) {
            $name = $nameMap[$sample['account_id']] ?? null;
            if ($name !== null) {
                $lines[] = "- {$sample['description']} → {$name}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * account_id 로 계정과목명을 붙여 제안을 구성한다. 이름 조회 실패 시 none().
     */
    private function toSuggestion(int $accountId, float $confidence, ClassifierSource $source): AccountSuggestion
    {
        /** @var array<string, mixed>|null $account */
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            return AccountSuggestion::none();
        }

        return new AccountSuggestion($accountId, (string) $account['name'], $confidence, $source);
    }

    /**
     * 거래처 상호를 사업장 스코프 id 로 해석한다. 미등록·빈값이면 null.
     */
    private function resolvePartnerId(int $businessId, ?string $partnerName): ?int
    {
        if ($partnerName === null || trim($partnerName) === '') {
            return null;
        }

        $partner = $this->partners->findByName($businessId, $partnerName);

        return $partner !== null ? (int) $partner['id'] : null;
    }

    /**
     * 이 폼이 다루는 수입/비용만 계정 카테고리로 매핑한다(자산 전표는 대상 아님).
     */
    private function categoryFor(EntryType $entryType): ?AccountCategory
    {
        return match ($entryType) {
            EntryType::Income  => AccountCategory::Income,
            EntryType::Expense => AccountCategory::Expense,
            default            => null,
        };
    }

    /**
     * AI 출력 정규화 — 코드펜스·따옴표 제거, NONE/빈값은 null.
     */
    private function normalizeName(string $answer): ?string
    {
        $clean = trim($answer);
        $clean = trim($clean, "\"'`");
        $clean = trim($clean);

        if ($clean === '' || strtoupper($clean) === 'NONE') {
            return null;
        }

        return $clean;
    }
}
