<?php

namespace App\Services;

use App\DTOs\LedgerSearchFilter;
use App\Enums\AccountCategory;
use App\Enums\EntryType;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\PartnerModel;
use Throwable;

/**
 * 자연어 장부 검색 파서(유스케이스).
 *
 * "지난달 접대비 50만원 넘는 건" 같은 자연어를 Claude 로 해석해 **안전한 필터 DTO** 로 바꾼다.
 * 보안 핵심: AI 는 절대 SQL 을 만들지 않는다 — AI 출력은 화이트리스트 필드로만 흡수하고,
 * 계정과목·거래처는 실제 등록건과 정확일치할 때만 id 로 채운다. 나머지는 폐기한다.
 * AI 미설정·실패 시 입력 문구를 그대로 거래내용 키워드 검색으로 폴백한다(그레이스풀).
 */
final class LedgerQueryParserService
{
    /**
     * 유효한 귀속연도 범위(방어적 검증).
     */
    private const YEAR_MIN = 2000;

    private const YEAR_MAX = 2100;

    private AccountModel $accounts;
    private PartnerModel $partners;
    private ?AnthropicClient $ai;

    public function __construct(
        ?AccountModel $accounts = null,
        ?PartnerModel $partners = null,
        ?AnthropicClient $ai = null,
    ) {
        $this->accounts = $accounts ?? model(AccountModel::class);
        $this->partners = $partners ?? model(PartnerModel::class);
        // AI 클라이언트는 Services 팩토리에서 주입(env 기반). null 이면 키워드 검색으로만 동작.
        $this->ai = $ai;
    }

    /**
     * 자연어 문구를 안전한 검색 필터로 변환한다.
     *
     * @param string      $nl    사용자 입력 자연어
     * @param string|null $today 상대 날짜(지난달 등) 해석 기준일 Y-m-d(테스트용 주입, 기본 오늘)
     */
    public function parse(int $businessId, string $nl, ?string $today = null): LedgerSearchFilter
    {
        $nl = trim($nl);
        if ($nl === '') {
            return LedgerSearchFilter::empty();
        }

        // AI 미설정 시 전체 문구를 키워드로 검색(폴백).
        if ($this->ai === null) {
            return LedgerSearchFilter::keywordOnly($nl);
        }

        try {
            $prompt = $this->buildPrompt($nl, $today ?? date('Y-m-d'));
            $answer = $this->ai->completeText($prompt, 512);
            $json   = $this->parseJson($answer);

            return $this->buildFilter($businessId, $json);
        } catch (Throwable $e) {
            log_message('warning', '자연어 검색 파싱 실패(키워드 폴백): {msg}', ['msg' => $e->getMessage()]);

            return LedgerSearchFilter::keywordOnly($nl);
        }
    }

    /**
     * AI 응답(JSON)을 화이트리스트 필드로만 흡수해 필터 DTO 를 만든다.
     * 계정과목·거래처는 정확일치 매핑에 성공할 때만 채운다.
     *
     * @param array<string, mixed> $json
     */
    private function buildFilter(int $businessId, array $json): LedgerSearchFilter
    {
        // 이 화면은 수입/비용만 다룬다(자산 전표 제외).
        $entryType = EntryType::tryFrom((string) ($json['entry_type'] ?? ''));
        if ($entryType !== EntryType::Income && $entryType !== EntryType::Expense) {
            $entryType = null;
        }

        return new LedgerSearchFilter(
            fiscalYear: $this->validYear($json['fiscal_year'] ?? null),
            entryType: $entryType,
            dateFrom: $this->validDate($json['date_from'] ?? null),
            dateTo: $this->validDate($json['date_to'] ?? null),
            accountId: $this->resolveAccountId($json['account_name'] ?? null, $entryType),
            partnerId: $this->resolvePartnerId($businessId, $json['partner_name'] ?? null),
            amountMin: $this->validAmount($json['amount_min'] ?? null),
            amountMax: $this->validAmount($json['amount_max'] ?? null),
            keyword: $this->validKeyword($json['keyword'] ?? null),
        );
    }

    /**
     * 닫힌 어휘 프롬프트. 계정과목·거래처 목록과 기준일을 제공하고 엄격한 JSON 만 요청한다.
     */
    private function buildPrompt(string $nl, string $today): string
    {
        $income  = array_column($this->accounts->forCategory(AccountCategory::Income), 'name');
        $expense = array_column($this->accounts->forCategory(AccountCategory::Expense), 'name');

        $incomeList  = implode(', ', array_map('strval', $income));
        $expenseList = implode(', ', array_map('strval', $expense));

        return <<<PROMPT
            너는 한국 간편장부 검색 질의 해석기다. 아래 자연어를 검색 필터 JSON 하나로만 변환하라(설명·마크다운·코드펜스 금지).
            오늘 날짜는 {$today} 다. "지난달","올해","최근 3개월" 등 상대 기간은 이 날짜 기준으로 계산하라.
            스키마(해당 없으면 null):
            {"entry_type":"income|expense|null",
             "fiscal_year":정수 연도 또는 null,
             "date_from":"YYYY-MM-DD 또는 null",
             "date_to":"YYYY-MM-DD 또는 null",
             "account_name":"아래 계정과목 목록과 정확히 일치하는 문자열 또는 null",
             "partner_name":"거래처 상호 또는 null",
             "amount_min":정수(공급가액 하한, 원) 또는 null,
             "amount_max":정수(공급가액 상한, 원) 또는 null,
             "keyword":"거래내용 부분검색어 또는 null"}

            규칙:
            - "50만원" = 500000, "1천만원" = 10000000 처럼 한글 금액을 정수(원)로 환산하라.
            - "~넘는/초과/이상"은 amount_min, "~미만/이하"는 amount_max 로.
            - account_name 은 반드시 아래 목록과 100% 동일해야 하며, 확신이 없으면 null.
            - 특정 계정과목·거래처를 지목하지 않은 일반 단어는 keyword 로 넣어라.
            수입 계정과목: {$incomeList}
            비용 계정과목: {$expenseList}

            자연어: {$nl}
            PROMPT;
    }

    /**
     * 모델 출력에서 JSON 객체를 파싱한다(코드펜스 방어적 제거).
     *
     * @return array<string, mixed>
     */
    private function parseJson(string $text): array
    {
        $clean = trim($text);
        $clean = (string) preg_replace('/^```(?:json)?|```$/m', '', $clean);
        $clean = trim($clean);

        /** @var mixed $data */
        $data = json_decode($clean, true);
        if (! is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * 계정과목명을 정확일치로 id 해석. entry_type 을 알면 해당 카테고리로 좁힌다.
     */
    private function resolveAccountId(mixed $name, ?EntryType $entryType): ?int
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        $name = trim($name);

        $categories = match ($entryType) {
            EntryType::Income  => [AccountCategory::Income],
            EntryType::Expense => [AccountCategory::Expense],
            default            => [AccountCategory::Income, AccountCategory::Expense],
        };

        foreach ($categories as $category) {
            $account = $this->accounts->findByCategoryName($category, $name);
            if ($account !== null) {
                return (int) $account['id'];
            }
        }

        return null;
    }

    /**
     * 거래처 상호를 사업장 스코프 id 로 정확일치 해석.
     */
    private function resolvePartnerId(int $businessId, mixed $name): ?int
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $partner = $this->partners->findByName($businessId, trim($name));

        return $partner !== null ? (int) $partner['id'] : null;
    }

    /**
     * 연도 검증(정수·허용범위). 벗어나면 null.
     */
    private function validYear(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $year = (int) $value;

        return ($year >= self::YEAR_MIN && $year <= self::YEAR_MAX) ? $year : null;
    }

    /**
     * 날짜 검증(Y-m-d 형식·실재 날짜). 어긋나면 null.
     */
    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    /**
     * 금액 검증(0 이상 정수). 음수·비수치는 null.
     */
    private function validAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * 키워드 검증·정규화.
     */
    private function validKeyword(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return LedgerSearchFilter::normalizeKeyword($value);
    }
}
