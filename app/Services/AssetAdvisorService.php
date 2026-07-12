<?php

namespace App\Services;

use App\DTOs\AssetAdviceResult;
use App\Enums\AccountCategory;
use App\Enums\ClassifierSource;
use App\Enums\DepreciationMethod;
use App\Libraries\AnthropicClient;
use App\Models\AccountModel;
use App\Models\AssetModel;
use App\Models\BusinessModel;
use App\Models\IndustryCodeModel;
use Throwable;

/**
 * 자산 등록 어시스트(유스케이스).
 *
 * 품목명으로 **자산분류·상각방법**을 제안하고, **내용연수(업종기준)·소액자산 여부**는 결정적으로 산출한다.
 * 설계 원칙(에픽 #20):
 *  - **이력 우선**: 같은 사업장의 동일 자산명 이력이 있으면 그 분류·방법을 재사용(LLM 미사용).
 *  - **AI 는 제안만**: 자산분류(닫힌 어휘 5종)·상각방법(enum)만 제안하고 실제 값과 정확일치할 때만 채택.
 *  - **계산·기준은 도메인**: 내용연수는 업종기준(IndustryCode), 소액자산 한도는 TaxRuleResolver 로 산출.
 *    상각액 계산은 항상 DepreciationService 가 수행(이 서비스는 손대지 않는다).
 *  - **graceful**: AI 미설정·실패 시 이력/결정적 결과만으로 동작한다.
 */
final class AssetAdvisorService
{
    private const CONF_HISTORY = 0.9;
    private const CONF_AI      = 0.7;

    private AssetModel $assets;
    private AccountModel $accounts;
    private BusinessModel $businesses;
    private IndustryCodeModel $industryCodes;
    private TaxRuleResolver $taxRules;
    private ?AnthropicClient $ai;

    public function __construct(
        ?AssetModel $assets = null,
        ?AccountModel $accounts = null,
        ?BusinessModel $businesses = null,
        ?IndustryCodeModel $industryCodes = null,
        ?TaxRuleResolver $taxRules = null,
        ?AnthropicClient $ai = null,
    ) {
        $this->assets        = $assets ?? model(AssetModel::class);
        $this->accounts      = $accounts ?? model(AccountModel::class);
        $this->businesses    = $businesses ?? model(BusinessModel::class);
        $this->industryCodes = $industryCodes ?? model(IndustryCodeModel::class);
        $this->taxRules      = $taxRules ?? service('taxRuleResolver');
        // AI 클라이언트는 Services 팩토리에서 env 기반 주입. null 이면 이력·결정적 결과만.
        $this->ai = $ai;
    }

    /**
     * 품목명으로 자산 등록 초안을 제안한다.
     *
     * @param string      $itemName        자산명(품목)
     * @param int|null    $acquisitionCost 취득금액(원). 소액자산 판정용, 없으면 판정 생략
     * @param string|null $acquiredAt      취득일 Y-m-d. 세법기준 as-of 연도, 없으면 올해
     */
    public function advise(int $businessId, string $itemName, ?int $acquisitionCost = null, ?string $acquiredAt = null): AssetAdviceResult
    {
        $itemName = trim($itemName);
        $year     = $this->resolveYear($acquiredAt);

        // 결정적 근거(도메인) — 내용연수·소액자산 한도.
        $usefulLife = $this->industryUsefulLife($businessId, $year);
        $threshold  = $this->taxRules->forYear($year)->lowValueAssetThreshold;
        $isLowValue = $acquisitionCost !== null && $acquisitionCost > 0 && $acquisitionCost <= $threshold;

        // 자산분류·상각방법 — 이력 우선, 없으면 AI.
        [$assetType, $method, $confidence, $source] = $this->classify($businessId, $itemName);

        return new AssetAdviceResult(
            assetType: $assetType,
            method: $method,
            usefulLife: $usefulLife,
            isLowValue: $isLowValue,
            lowValueThreshold: $threshold,
            confidence: $confidence,
            source: $source,
        );
    }

    /**
     * 자산분류·상각방법을 이력 우선으로 해석한다. 실패 시 AI, 그것도 실패면 없음.
     *
     * @return array{0: string|null, 1: DepreciationMethod|null, 2: float, 3: ClassifierSource}
     */
    private function classify(int $businessId, string $itemName): array
    {
        if ($itemName === '') {
            return [null, null, 0.0, ClassifierSource::None];
        }

        // 1) 이력: 동일 자산명 최근 등록건 재사용.
        $past = $this->assets->findLatestByName($businessId, $itemName);
        if ($past !== null) {
            return [
                $this->validAssetType((string) ($past['asset_type'] ?? '')),
                $this->resolveMethod((string) ($past['depreciation_method'] ?? '')),
                self::CONF_HISTORY,
                ClassifierSource::History,
            ];
        }

        // 2) AI: 닫힌 어휘로 분류·방법 제안 후 정확일치 재검증.
        if ($this->ai !== null) {
            try {
                [$type, $method] = $this->aiClassify($itemName);
                if ($type !== null || $method !== null) {
                    return [$type, $method, self::CONF_AI, ClassifierSource::Ai];
                }
            } catch (Throwable $e) {
                log_message('warning', '자산 어시스트 AI 실패(결정적 결과만): {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return [null, null, 0.0, ClassifierSource::None];
    }

    /**
     * Claude 로 자산분류·상각방법을 제안받아 실제 값과 정확일치 검증한다.
     *
     * @return array{0: string|null, 1: DepreciationMethod|null}
     */
    private function aiClassify(string $itemName): array
    {
        if ($this->ai === null) {
            return [null, null];
        }

        $answer = $this->ai->completeText($this->buildPrompt($itemName), 128);
        $json   = $this->parseJson($answer);

        $type   = is_string($json['asset_type'] ?? null) ? $this->validAssetType((string) $json['asset_type']) : null;
        $method = is_string($json['method'] ?? null) ? $this->resolveMethod((string) $json['method']) : null;

        return [$type, $method];
    }

    /**
     * 닫힌 어휘 프롬프트. 실제 자산종류 목록과 상각방법만 제시하고 엄격한 JSON 만 요청한다.
     */
    private function buildPrompt(string $itemName): string
    {
        $types = implode(', ', array_map('strval', array_column($this->assetTypeNames(), 'name')));

        return <<<PROMPT
            너는 한국 간편장부 자산 등록 어시스트다. 아래 자산 품목명을 보고 자산분류와 상각방법을 제안하라.
            JSON 객체 하나만 출력하라(설명·마크다운·코드펜스 금지):
            {"asset_type":"아래 목록 중 정확히 하나 또는 null","method":"정액법|정률법|null"}
            규칙:
            - asset_type 은 반드시 아래 목록의 문자열과 100% 동일해야 하며, 확신이 없으면 null.
            - 건물·구축물은 정액법이 일반적이고, 기계장치·차량·비품은 정률법이 흔하다. 확신이 없으면 method 는 null.
            자산분류 목록: {$types}

            품목명: {$itemName}
            PROMPT;
    }

    /**
     * 자산종류명을 닫힌 어휘(자산 카테고리 계정)와 정확일치 검증한다. 실패 시 null.
     */
    private function validAssetType(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $account = $this->accounts->findByCategoryName(AccountCategory::Asset, $name);

        return $account !== null ? (string) $account['name'] : null;
    }

    /**
     * 상각방법 라벨/값을 enum 으로 정확일치 해석한다. 실패 시 null.
     */
    private function resolveMethod(string $value): ?DepreciationMethod
    {
        $value = trim($value);

        return match ($value) {
            '정액법', DepreciationMethod::StraightLine->value     => DepreciationMethod::StraightLine,
            '정률법', DepreciationMethod::DecliningBalance->value => DepreciationMethod::DecliningBalance,
            default                                               => null,
        };
    }

    /**
     * 자산 카테고리 계정 목록(닫힌 어휘).
     *
     * @return list<array<string, mixed>>
     */
    private function assetTypeNames(): array
    {
        return $this->accounts->forCategory(AccountCategory::Asset);
    }

    /**
     * 사업장 주업종코드로 취득연도에 유효한 업종별 자산 내용연수를 조회한다(없으면 null).
     */
    private function industryUsefulLife(int $businessId, int $year): ?int
    {
        $business = $this->businesses->find($businessId);
        $code     = $business['industry_code'] ?? null;
        if ($code === null || $code === '') {
            return null;
        }

        return $this->industryCodes->usefulLifeFor((string) $code, $year);
    }

    /**
     * 취득일에서 as-of 연도를 뽑는다(없으면 올해).
     */
    private function resolveYear(?string $acquiredAt): int
    {
        if ($acquiredAt !== null && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $acquiredAt, $m) === 1) {
            return (int) $m[1];
        }

        return (int) date('Y');
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
}
