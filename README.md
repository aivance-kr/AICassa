# CodeIgniter 4 Application Starter

---

## 개정세법 버전 관리 (Tax Rule Versioning)

세법은 매년 개정되고, 개정 내용은 원칙적으로 개정 이후 개시하는 **과세연도(귀속분)부터** 적용된다.
따라서 2025 귀속분(2026년 5월 신고)은 기존 세법으로, 2026 귀속분(2027년 5월 신고)은 개정 세법으로
계산되어야 하며, 과거 귀속연도 신고서를 다시 열어도 **그 연도의 세법으로 동일하게 재현**되어야 한다.

이를 위해 세법이 정하는 값을 **시행연도(effective_year) 기준 버전**으로 관리한다.

### 조회 규칙 — as-of

귀속연도 Y로 계산할 때, `시행연도 <= Y` 중 **가장 최근 시행연도**의 값을 적용한다.
값이 바뀌지 않은 연도는 별도 항목 없이 직전 룰셋을 그대로 상속하므로, 불변 값을 연도마다
중복 저장하지 않는다. 귀속연도가 최초 시행연도 이전이면 **가장 이른 시행연도** 값으로 폴백한다.

### 구성 (하이브리드)

| 대상 | 관리 위치 | 조회 |
|---|---|---|
| 내용연수별 상각률표 | `depreciation_rates` 테이블 `effective_year` 컬럼 | `DepreciationRateModel::rateFor($method, $life, $year)` |
| 업종별 기준 내용연수 | `industry_codes` 테이블 `effective_year` 컬럼 | `IndustryCodeModel::usefulLifeFor($code, $year)` |
| 스칼라 상수(부가세 제수·비망가액·정률 잔존율) | `app/Config/TaxRules.php` 시행연도별 룰셋 | `TaxRuleResolver::forYear($year): TaxRuleSet` |

- **표 형태 참조데이터**는 DB에 시행연도 복합 유니크(`(useful_life, effective_year)`,
  `(code, effective_year)`)로 보관한다. 개정 시 새 `effective_year` 행을 **추가**한다(기존 행 유지).
- **스칼라 상수**는 `Config\TaxRules::$sets`(`시행연도 => 파라미터`)로 보관하고,
  `TaxRuleResolver`가 as-of로 해석해 `TaxRuleSet`(읽기 전용 DTO)으로 반환한다.
- 감가상각 스케줄처럼 **여러 연도에 걸친 계산은 각 연도에 유효한 룰셋**을 적용하므로,
  개정 세법이 걸친 기간도 연도별로 정확히 재현된다.

기준선은 **2023 시행연도**(국세청 간편장부 프로그램 v3.4 원본 규칙)이며,
현재 룰셋은 2023 하나뿐이라 계산 결과는 종전과 100% 동일하다.

### 개정 세법이 확정되면

1. **상각률표/업종코드 개정** — 새 시행연도로 시더에 행을 추가하거나 임포트한다.
   (예: `effective_year = 2027` 행 삽입. 기존 2023 행은 삭제하지 않는다.)
2. **스칼라 상수 개정**(부가세율 등) — `app/Config/TaxRules.php`의 `$sets`에 시행연도 키를 추가한다.
   ```php
   public array $sets = [
       2023 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
       // 예: 2027년부터 부가세율이 개정되는 경우(값은 실제 개정 수치로 교체)
       2027 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
   ];
   ```
3. **신고서식 개정** — `app/Config/TaxForm.php`의 `$versions`에 귀속연도별 서식 버전을 추가하고
   `$latest`를 갱신한다. 신고서 화면에는 적용된 세법 기준연도(`rule_effective_year`)가 함께 노출된다.

기존 데이터·전표는 손대지 않고 **행/설정 항목만 추가**하면 해당 귀속연도부터 자동으로 분기된다.

### 관련 파일

- `app/Config/TaxRules.php` · `app/DTOs/TaxRuleSet.php` · `app/Services/TaxRuleResolver.php`
- `app/Models/DepreciationRateModel.php` · `app/Models/IndustryCodeModel.php`
- `app/Services/VatCalculatorService.php` · `app/Services/DepreciationService.php`
- 마이그레이션: `*_AddEffectiveYearToDepreciationRates.php` · `*_AddEffectiveYearToIndustryCodes.php`

---

## AI 업무효율화 기능 (Epic #20)

Anthropic Claude 연동 인프라(`app/Libraries/AnthropicClient.php` · `env('ANTHROPIC_API_KEY')` · 기본 모델 `claude-sonnet-5`)를 재활용해
간편장부 작성·결산·신고의 반복노동과 세무 리스크를 AI로 줄인다. 6개 기능 모두 아래 **공통 설계 원칙**을 따른다.

### 공통 설계 원칙

- **계산은 AI 금지** — 부가세·상각액 등 수치는 항상 도메인 서비스가 재계산한다(단일 진실 소스).
- **정확일치·이력 우선** — AI 호출 전에 캐시·이력·규칙을 먼저 적용하고, miss일 때만 LLM을 호출한다(토큰 비용·5초 타임아웃).
- **닫힌 어휘 JSON** — AI 출력은 실제 참조데이터와 **정확일치할 때만** 채택하고(화이트리스트), 나머지는 폐기한다.
- **초안(draft)** — AI 결과는 제안일 뿐이며, 최종 확정은 사람이 한다.
- **graceful 폴백** — `ANTHROPIC_API_KEY` 미설정·AI 실패 시 비-AI 경로(이력·규칙·검색)로 자동 폴백해 기능이 멈추지 않는다.

### 기능 목록

| # | 기능 | 요약 | 핵심 서비스 |
|---|---|---|---|
| ① | 계정과목 AI 자동분류 | 거래내용 → 계정과목 추천, 이력 우선·AI 폴백 | `AccountClassifierService` |
| ② | 자연어 장부 검색 | "지난달 접대비 50만원 넘는 건" → 안전한 필터 DTO (AI는 SQL 미생성, 화이트리스트 흡수) | `LedgerQueryParserService` |
| ③ | 결산·신고 전 이상탐지 | 결정적 규칙 + 선택적 AI 오분류 점검 리포트 | `AnomalyDetectionService` |
| ④ | 감가상각/자산 판단 어시스트 | 품목명 → 자산분류·상각방법 제안, 내용연수·소액자산은 결정적 산출 | `AssetAdvisorService` |
| ⑤ | 세무 Q&A 챗봇 (RAG) | `docs/*.md` 근거 렉시컬 RAG — 인덱싱+검색+인용 응답, 근거 없으면 "확인 불가" | `TaxQaService` |
| ⑥ | 엑셀 임포트 컬럼 자동매핑 | 고객사별 엑셀/CSV 헤더 → 장부 표준 스키마 AI 매핑 + 확인 UI | `ExcelColumnMapperService` · `SpreadsheetReader` |

### 설정

`.env`에 `ANTHROPIC_API_KEY`(및 선택 `ANTHROPIC_MODEL`, 기본 `claude-sonnet-5`)를 설정하면 AI 경로가 활성화된다.
미설정 시에도 각 기능은 비-AI 경로로 동작하므로, AI 키 없이 개발·테스트가 가능하다.

### 레이트 리밋 (유료 LLM 호출 보호)

유료 외부 LLM 호출을 유발하는 AI 엔드포인트에 **로그인 사용자 단위** 레이트 리밋을 건다(`AiRateLimitFilter`).
다중 테넌트에서 한 계정이 외부 API 비용·지연을 무제한 유발하는 것을 막는다.

- **정책**: 사용자당 `seconds` 창에서 `capacity` 회까지 허용. 초과 시 POST(AJAX 어시스트)는 **HTTP 429**(에러 코드 `RATE_LIMITED`, CSRF 토큰 동봉), GET(자연어 검색)은 플래시 에러와 함께 이전 페이지로 리다이렉트한다.
- **폴백 비차단**: `ANTHROPIC_API_KEY` 미설정 시 대상 엔드포인트는 외부 호출 없이 이력·규칙·검색 폴백만 하므로 레이트 리밋을 적용하지 않는다.
- **설정(`.env`, 무배포 조정)**: `ai.rateLimit.enabled`(기본 `true`) · `ai.rateLimit.capacity`(기본 `20`) · `ai.rateLimit.seconds`(기본 `60`) → 기본 분당 20회.

| 엔드포인트 | 메서드 |
|---|---|
| `admin/tax-qa/ask` | POST |
| `admin/businesses/{id}/assets/advise` | POST |
| `admin/businesses/{id}/ledger/receipts/recognize` | POST |
| `admin/businesses/{id}/ledger/classify-account` | POST |
| `admin/businesses/{id}/ledger/import` | POST |
| `admin/businesses/{id}/reports/anomalies` | POST |
| `admin/businesses/{id}/ledger/search` | GET |

> **월간 비용 상한**은 영속 카운터(DB)가 필요해 이 단계에서는 제외했다. 현재는 레이트 리밋으로 순간 폭주·남용을 차단하며, 장기 예산 상한은 후속 과제로 둔다.

---

## 배포 (Deployment)

`dev` → `main` PR을 머지하면 CD(`.github/workflows/deploy.yml`)가 운영 서버에 SSH 배포한다.
CD 자동 절차: `git reset --hard origin/main` → `composer install --no-dev` → `php spark migrate --all -f` → 캐시 클리어 → Apache 무중단 리로드.

> ⚠️ **`dev` → `main` 은 반드시 Merge commit 으로 머지한다(Squash 금지).**
> Squash 하면 `main` 이 `dev` 조상에서 이탈해 이후 배포마다 3-way 충돌이 재발한다.

### 배포 후 수동 절차

- **참조데이터 시더는 자동 실행되지 않는다.** 최초 배포 또는 참조데이터(감가상각률·업종코드·연도별 세법 파라미터) 변경 시
  서버에서 한 번 실행한다:
  ```bash
  php spark db:seed ReferenceDataSeeder
  ```
- 운영 `.env` 에 `ANTHROPIC_API_KEY`(선택 `ANTHROPIC_MODEL`, 기본 `claude-sonnet-5`)를 설정하면 AI 기능이 활성화된다.
  미설정 시 비-AI 폴백으로 동작한다.

---

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library
