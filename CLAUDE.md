# CLAUDE.md

이 파일은 Claude Code(claude.ai/code)가 이 저장소에서 작업할 때 참고하는 가이드다.

**간편장부 웹 ERP** — 국세청 간편장부 제도를 기반으로 한 다중 사용자(SaaS) 웹 ERP. CodeIgniter 4 기반 Admin + REST API 단일 프로젝트.

> 도메인 분석·설계·계산식 명세는 `docs/` 참조:
> - `docs/간편장부_웹ERP_분석설계.md` — 데이터 모델·아키텍처·로드맵
> - `docs/간편장부_계산식명세.md` — 부가세·감가상각·소득금액 계산 규칙(VBA 역설계)
> - `docs/신고서식_대조_검증.md` — 공식 서식 필드 대조·계산 검증·갭(세무사 검토 대상)

---

## 언어 규칙
- 모든 응답은 반드시 **한국어**로 작성한다.
- 코드 주석도 한국어로 작성한다.

---

## 기술 스택
- **언어**: PHP 8.4+ (타입 선언·match·enum·readonly 적극 사용)
- **프레임워크**: CodeIgniter 4
- **인증**: 세션(Admin) / JWT Bearer(API) — JWT는 외부 라이브러리 없이 `JwtLibrary`(HMAC-SHA256)로 직접 구현
- **API 문서**: Swagger UI (`/api/docs`) — `zircote/swagger-php`
- **엑셀**: PhpSpreadsheet (간편장부 특성상 엑셀 입출력이 핵심)

---

## 로컬 환경 설정
```bash
cp env .env          # env 파일을 .env로 복사 후 아래 필수 키 설정
composer install
php spark migrate
```
`.env` 필수 키:
```
# 앱
app.baseURL = http://localhost:8080/
# DB
database.default.hostname = localhost
database.default.database = aicura
database.default.username = root
database.default.password =
# JWT (필수 — 32자 이상 랜덤 문자열)
JWT_SECRET = your-secret-key-here
```

---

## 커맨드
```bash
php spark serve               # 개발 서버
php spark migrate             # DB 마이그레이션
php spark swagger:generate    # OpenAPI 스펙 생성 (public/swagger.json)
php spark routes              # 라우트 목록
composer test                 # PHPUnit 단독 실행
composer analyse              # PHPStan 단독 실행
composer check                # PHPStan + PHPUnit 순차 실행
```

---

## 디렉토리 규칙
| 경로 | 용도 |
|---|---|
| `app/Controllers/Admin/` | 관리자 컨트롤러 (세션 인증) |
| `app/Controllers/Api/V1/` | REST API 컨트롤러 (JWT 인증) |
| `app/Models/` | Admin·API 공유 모델 |
| `app/Filters/` | AdminAuthFilter / JwtAuthFilter |
| `app/Libraries/` | JwtLibrary 등 공통 라이브러리 |
| `app/Services/` | 유스케이스 단위 비즈니스 로직 (부가세·감가상각·집계 등) |
| `app/Commands/` | Spark 커스텀 커맨드 |
| `docs/` | 프로젝트 문서 |

---

## 프론트엔드 라이브러리

### 데이터 그리드 — AG Grid Community
목록성 화면(장부·거래처 테이블 등)은 AG Grid 를 사용한다.
```html
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<div id="myGrid" style="height:500px;" class="ag-theme-alpine"></div>
<script>
const gridOptions = {
    columnDefs: [{ field: 'date', headerName: '일자' }, { field: 'account', headerName: '계정과목' }],
    rowData: <?= json_encode($rows) ?>,
    pagination: true,
    paginationPageSize: 20,
};
agGrid.createGrid(document.getElementById('myGrid'), gridOptions);
</script>
```
- 테마 `ag-theme-alpine` 기본, 서버사이드 페이징은 `serverSideDatasource`
- HTML 셀은 `cellRenderer` 사용 (innerHTML 직접 조작 금지)

### 차트 — Chart.js
통계·영업현황표 차트는 Chart.js 를 사용한다.
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```
- 데이터는 컨트롤러에서 `$labels`, `$values` 로 분리해 전달
- 민감 집계 데이터는 뷰 직접 노출 대신 API 엔드포인트 분리 고려

### 리치 에디터 — Tiptap (필요 시)
- 헤드리스 에디터. ES 모듈 CDN 로드, 폼 제출용 hidden input에 `editor.getHTML()` 동기화
- 저장 출력은 반드시 `esc($content, 'html')` 또는 허용 태그 화이트리스트 필터

### 엑셀 — PhpSpreadsheet
```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// 읽기
$rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet()->toArray();
```
- 대용량(1만 행 이상)은 청크 단위 처리(ChunkReadFilter)
- 업로드 파일은 `writable/uploads/` 에 저장 후 처리, 처리 완료 시 임시 파일 즉시 삭제

---

## 아키텍처 핵심 패턴

### JWT 인증 흐름
`JwtAuthFilter`가 토큰 검증 후 `Auth::setUserId()`로 정적 홀더에 저장, 컨트롤러는 `$this->authUserId()`로 사용.
```php
Auth::setUserId((int) $payload['sub']);   // JwtAuthFilter
$userId = $this->authUserId();            // BaseApiController 상속 컨트롤러
```

### Admin 뷰 렌더링
`BaseAdminController::render()`가 공통 데이터(세션 `authUser`)를 자동 병합한다. 반드시 `$this->render()`를 사용한다.
```php
return $this->render('admin/ledger/index', ['entries' => $entries]);   // ✅
return view('admin/ledger/index', ['entries' => $entries]);            // ❌ authUser 누락
```

### API 응답 포맷
```php
$this->success($data, $meta);                       // { "status":"success", "data":{...}, "meta":{...} }
$this->error('ERROR_CODE', '메시지', $statusCode);  // { "status":"error", "code":"...", "message":"..." }
```

### 페이지네이션 meta 표준
목록 API의 meta는 아래 4개 필드를 항상 포함한다.
```php
$this->success($items, [
    'page'      => (int) $page,
    'per_page'  => (int) $limit,
    'total'     => (int) $total,
    'last_page' => (int) ceil($total / $limit),
]);
```

### 에러 코드 (UPPER_SNAKE_CASE · 도메인_동사)
`UNAUTHORIZED` · `TOKEN_EXPIRED` · `INVALID_TOKEN` · `INVALID_CREDENTIALS` · `VALIDATION_ERROR` · `NOT_FOUND` · `ALREADY_EXISTS` · `FORBIDDEN` · `INTERNAL_ERROR`

### REST URI 설계
- 복수 명사 + 버전 prefix: `/api/v1/ledger-entries`, `/api/v1/partners`
- URI에 동사 금지 (`GET /users/{id}` ✅, `/getUser` ❌)
- 필터·정렬·페이지는 쿼리스트링: `?filter[status]=active&sort=-created_at&page=1&per_page=20`

### HTTP 상태코드
| 상황 | 코드 |
|---|---|
| 조회 성공 | 200 |
| 생성 성공 | 201 |
| 응답 본문 없음 | 204 |
| 인증 실패 | 401 |
| 권한 없음 | 403 |
| 리소스 없음 | 404 |
| 유효성 검사 실패 | 422 |
| 서버 오류 | 500 |

### Swagger 어트리뷰트
새 API 엔드포인트마다 PHP 어트리뷰트 추가 필수.
```php
use OpenApi\Attributes as OA;
#[OA\Get(path: '/ledger-entries', summary: '...', security: [['bearerAuth' => []]], tags: ['Ledger'], responses: [...])]
public function index() { ... }
```

---

## 성능·DB 원칙
- `SELECT *` 금지 — 필요한 컬럼만 명시
- N+1 쿼리 금지 — 관계 데이터는 JOIN
- 목록 API는 반드시 페이징 (limit/offset 또는 커서)
- 인덱스 없는 컬럼 WHERE 금지 — 마이그레이션에 인덱스 함께 정의
- 응답 페이로드 최소화 (불필요 필드 제거)
- 외부 API 호출은 타임아웃 필수 (기본 5초)

---

## 정적 분석 (PHPStan)
코드 작성 후 반드시 통과해야 한다. 레벨 6 (`phpstan.neon`), 대상 `app/` (Views 제외).
```bash
composer analyse   # PHPStan 단독
composer check     # PHPStan + PHPUnit
```
- 새 클래스·메서드는 `array<string, mixed>` 등 제네릭 타입 명시 필수
- `@phpstan-ignore` 주석으로 억제 금지 — 원인을 찾아 수정

---

## Git 워크플로우
```
feature/* → (PR) → dev → (PR) → main
```
- PR 대상: `feature/*` → `dev`
- 머지 방식: `feature/* → dev`는 **Squash and merge**, `dev → main`(배포)은 **Merge commit** (⚠️ Squash 금지)
  - dev → main 을 Squash 하면 main 이 dev 조상에서 이탈해 이후 배포마다 3-way 충돌이 재발한다. 반드시 merge commit 으로 main 을 dev 의 조상으로 유지한다.
- `main`·`dev` 직접 push 금지

---

## CI / CD (GitHub Actions)
- **CI** (`.github/workflows/ci.yml`): `dev`·`main`·`feature/**` push + PR 시 실행. mysql:8.0 서비스에서 **PHP CS Fixer → PHPStan(level6) → PHPUnit** 순차.
- **CD** (`.github/workflows/deploy.yml`): `main` push(= dev→main PR 머지) + 수동(`workflow_dispatch`) 시 SSH 배포. 동시성 `deploy-production`(1개, 중단 안 함).
  - 서버 절차: `git reset --hard origin/main` → `writable/` 생성(마이그레이션 전 필수) → `composer install --no-dev` → `spark migrate --all -f`(출력 예외 감지 시 중단) → `cache:clear` → `systemctl reload apache2`(무중단).
  - ⚠️ `spark migrate`는 실패해도 종료코드 0 → 출력에서 예외 패턴 검사로 중단 판정.
  - **시더는 자동 실행 안 함**(참조 데이터는 최초 1회 수동): `php spark db:seed ReferenceDataSeeder`.
- **필요 GitHub Secrets**(production 환경): `DEPLOY_HOST` · `DEPLOY_USER` · `DEPLOY_SSH_KEY` · `DEPLOY_PORT` · `DEPLOY_PATH`.
- **서버 사전 준비(1회)**: 읽기전용 deploy key(SSH 리모트), 운영 `.env`(실 DB 접속정보), 비밀번호 없는 sudo(`systemctl reload apache2`), DocumentRoot=`public/`, `writable/` www-data 쓰기권한(권장 `chmod 2775` setgid).

### 기능 개발 시작
```bash
git checkout dev && git pull origin dev
git checkout -b feature/기능명       # 예: feature/ledger-crud
# dev가 앞서간 경우
git rebase origin/dev && git push --force-with-lease origin feature/기능명
```

### 커밋 메시지 (Conventional Commits)
| 접두어 | 용도 |
|---|---|
| `feat` | 새 기능 |
| `fix` | 버그 수정 |
| `refactor` | 리팩토링 |
| `docs` | 문서 |
| `chore` | 설정·빌드 |
| `test` | 테스트 |

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

## 코딩 규칙
- PSR-12 준수
- 입력값은 반드시 CI4 Validation 또는 `esc()` 처리
- SQL은 CI4 Query Builder만 사용 (raw query 금지)
- 시크릿은 `.env`에서만 관리 (`env('KEY')`)
- POST 폼에는 `<?= csrf_field() ?>` 필수 (Admin 뷰)
- Model의 `$returnType`은 `'array'`로 통일

### 절대 금지 — 보안
| 금지 | 대신 |
|---|---|
| `$_GET`·`$_POST` 직접 사용 | `$this->request->getPost()` |
| SQL 문자열 직접 조합 | Query Builder / 바인딩 |
| `echo $변수` (뷰) | `echo esc($변수)` |
| `eval()` | 제거 |
| `md5()`/`sha1()` 비밀번호 | `password_hash()` |
| 시크릿 하드코딩 | `.env` + `env('KEY')` |
| CSRF 토큰 없는 POST | `csrf_field()` |
| `$_FILES` 직접 저장 | 확장자·MIME 검증 필수 |
| 스택 트레이스 노출 | 운영 `CI_ENVIRONMENT=production` |

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

---

## 레이어 책임 (Controller · Service)
- Controller는 얇게: 유효성 검사 → Service 호출 → 응답 반환만
- 하나의 Service 메서드 = 하나의 유스케이스
- DB 트랜잭션은 Service 레이어에서 관리 (`$db->transStart()` / `transComplete()`)
- 데이터 접근은 `model(XxxModel::class)` 헬퍼 경유 (직접 `new` 금지)
- 별도 Repository 레이어 없이 CI4 Model을 데이터 접근 계층으로 사용, 복잡한 쿼리는 Model 메서드로 캡슐화
```php
class LedgerController extends BaseApiController
{
    public function store(): ResponseInterface
    {
        $dto    = CreateLedgerRequest::fromRequest($this->request);
        $result = service('ledgerService')->create($dto);
        return $this->success($result, statusCode: 201);
    }
}
```

---

## 도메인 예외 처리
- 도메인 예외는 `app/Exceptions/`에 커스텀 클래스로 정의
- 예외는 HTTP 상태코드 + 에러 코드(문자열) 포함
- 전역 핸들러는 `app/Config/Exceptions.php`에 등록
```php
abstract class DomainException extends \RuntimeException
{
    abstract public function httpStatusCode(): int;
    abstract public function errorCode(): string;   // 예: 'PARTNER_NOT_FOUND'
}
```

---

## 테스트
```bash
composer test
```
- 단위: `tests/unit/` — 외부 의존성 Mock
- 통합: `tests/feature/` — CIUnitTestCase + DB 트랜잭션 롤백
- 커버리지 목표: Service 레이어 80% 이상 (부가세·감가상각 계산은 반드시 테스트)
- 테스트 DB는 `.env.testing` 별도 설정 — 운영 DB 절대 사용 금지
- 새 기능 구현 시 테스트 코드를 함께 작성한다
