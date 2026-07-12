# 아키텍처 규칙

> AICassa 핵심 아키텍처 패턴·API 규약·레이어 책임·성능/DB 원칙. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md) 참조.

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
composer check     # CS Fixer → PHPStan → PHPUnit (푸시 전 이걸로 CI 미리 검증)
```
- 새 클래스·메서드는 `array<string, mixed>` 등 제네릭 타입 명시 필수
- `@phpstan-ignore` 주석으로 억제 금지 — 원인을 찾아 수정
