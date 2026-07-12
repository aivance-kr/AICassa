# 코드 스타일 규칙

> AICassa 코딩 컨벤션·네이밍·모던 PHP 스타일·금지 패턴. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md) 참조.

## 코딩 규칙
- PSR-12 준수
- 입력값은 반드시 CI4 Validation 또는 `esc()` 처리
- SQL은 CI4 Query Builder만 사용 (raw query 금지)
- 시크릿은 `.env`에서만 관리 (`env('KEY')`)
- POST 폼에는 `<?= csrf_field() ?>` 필수 (Admin 뷰)
- Model의 `$returnType`은 `'array'`로 통일

> 보안 관련 금지 항목은 [security.md](security.md) 참조.

### 절대 금지 — 코드 품질
`@` 에러 억제 · `extract()` · `global` · 비즈니스 로직 내 `die()`/`exit()` · 100줄 이상 함수 · 의미 없는 변수명(`$a`,`$tmp`) · 주석 처리한 죽은 코드 · `var_dump()`/`print_r()` 커밋

### 절대 금지 — PHP 함정
| 금지 | 대신 |
|---|---|
| `==` 타입 비교 | `===` |
| 형변환 없는 문자열 연산 | 명시적 형변환/타입 선언 |
| 타입 선언 없는 파라미터 | `string $id`, `int $count` |
| null/false 반환 혼용 | 반환 타입 통일 |
| catch 후 예외 무시 | 최소한 로깅 |

### 절대 금지 — CI4 한정
- Controller에 비즈니스 로직 작성 → Service/Model 위임
- `allowedFields` 없는 Model (mass assignment 방지)
- 뷰에서 Model 직접 호출 (MVC 위반)
- `new UserModel()` 직접 인스턴스화 → `model(UserModel::class)` 사용

---

## 네이밍 규칙

### PHP
| 대상 | 규칙 | 예시 |
|---|---|---|
| 클래스 | PascalCase | `LedgerController`, `JwtLibrary` |
| 인터페이스 | PascalCase + Interface | `AdapterInterface` |
| 추상 클래스 | Base 접두어 | `BaseApiController` |
| 메서드 | camelCase | `getIncomeTotal()` |
| 변수·프로퍼티 | camelCase | `$authUserId`, `$fiscalYear` |
| 상수 | UPPER_SNAKE_CASE | `DEFAULT_TTL` |
| 배열 키 | snake_case | `$data['access_token']` |
| 파일명 | 클래스와 동일 | `LedgerController.php` |

### DB
| 대상 | 규칙 | 예시 |
|---|---|---|
| 테이블 | snake_case · 복수형 | `ledger_entries`, `partners` |
| 컬럼 | snake_case | `created_at`, `supply_amount` |
| PK | `id` | |
| FK | `{단수테이블명}_id` | `business_id`, `partner_id` |
| 불리언 | `is_` 접두어 | `is_active` |
| 타임스탬프 | CI4 표준 | `created_at`, `updated_at`, `deleted_at` |
| 일반 인덱스 | `idx_{테이블}_{컬럼}` | `idx_ledger_entries_fiscal_year` |
| 유니크 인덱스 | `uniq_{테이블}_{컬럼}` | `uniq_partners_biz_no` |
| Pivot 테이블 | 두 테이블 알파벳순·단수 | `campaign_tag` |

---

## PHP 모던 스타일 (8.4+)
상태·타입은 배열·`define()` 대신 **readonly DTO · Backed Enum** 우선.
```php
final readonly class CreateLedgerRequest
{
    public function __construct(
        public string $date,
        public int $amount,
        public EvidenceType $evidence = EvidenceType::TaxInvoice,
    ) {}
    public static function fromRequest($request): self { /* ... */ }
}

enum EvidenceType: string
{
    case TaxInvoice   = 'tax_invoice';   // 세금계산서
    case CashReceipt  = 'cash_receipt';  // 현금영수증
    public function isVatable(): bool
    {
        return match ($this) {
            self::TaxInvoice, self::CashReceipt => true,
        };
    }
}
```
- 메서드·프로퍼티에 타입 선언(return type 포함) 완전 적용
- `match` 표현식 우선 (switch 지양)
- DTO는 `final readonly`, 정적 팩토리(`fromRequest()`/`fromArray()`)로 생성
