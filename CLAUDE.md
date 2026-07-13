# CLAUDE.md

이 파일은 Claude Code(claude.ai/code)가 이 저장소에서 작업할 때 참고하는 가이드다.

**간편장부 웹 ERP** — 국세청 간편장부 제도를 기반으로 한 다중 사용자(SaaS) 웹 ERP. CodeIgniter 4 기반 Admin + REST API 단일 프로젝트.

> **공통 규칙은 전역 [`~/.claude/CLAUDE.md`](~/.claude/CLAUDE.md) 에서 자동 상속**된다(언어·Git 워크플로우·보안·코드 스타일·테스트·API·LSP). 이 문서는 **AICassa 저장소 전용** 규칙만 정의한다.

> 도메인 분석·설계·계산식 명세는 `docs/` 참조:
> - `docs/간편장부_웹ERP_분석설계.md` — 데이터 모델·아키텍처·로드맵
> - `docs/간편장부_계산식명세.md` — 부가세·감가상각·소득금액 계산 규칙(VBA 역설계)
> - `docs/신고서식_대조_검증.md` — 공식 서식 필드 대조·계산 검증·갭(세무사 검토 대상)

## 상세 규칙 (`.claude/rules/`)
AICassa 고유 규칙은 아래 파일로 분리되어 있으며 `@import` 로 함께 로드된다.

- [`architecture.md`](.claude/rules/architecture.md) — JWT 인증 흐름·Admin 뷰 렌더링·데이터 접근
- [`frontend.md`](.claude/rules/frontend.md) — 프론트엔드 라이브러리(AG Grid·Chart.js·Tiptap·PhpSpreadsheet)

@.claude/rules/architecture.md
@.claude/rules/frontend.md

---

## 기술 스택
- **언어**: PHP 8.4+ (타입 선언·match·enum·readonly 적극 사용)
- **프레임워크**: CodeIgniter 4
- **인증**: 세션(Admin) / JWT Bearer(API) — JWT는 외부 라이브러리 없이 `JwtLibrary`(HMAC-SHA256)로 직접 구현
- **API 문서**: Swagger UI (`/api/docs`) — `zircote/swagger-php`
- **엑셀**: PhpSpreadsheet (간편장부 특성상 엑셀 입출력이 핵심)
- **정적 분석**: PHPStan 레벨 6 (`app/`, Views 제외)

---

## 로컬 환경 설정

> **⚠️ Windows 환경이면 개발·테스트를 WSL 에서 수행한다.**
> Windows 체크아웃(`E:\claude_works\AICassa`)에는 **PHP·Composer 가 없다** — 서버 구동·마이그레이션·`composer check`·PHPUnit 을 실행할 수 없다.
> 코드 편집은 Windows/WSL 어디서든 가능하나 **실행·테스트·정적분석은 반드시 WSL 클론**(`~/claude-works/AICassa`, Ubuntu-24.04)에서 한다.
> 상세 절차·클론 동기화는 아래 [커맨드 › 로컬 검증은 WSL 클론에서 실행](#로컬-검증은-wsl-클론에서-실행-ci-왕복-예방) 참조.

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
composer test                 # PHPUnit 전체 실행(커버리지 포함) — CI 패리티
composer test:fast            # PHPUnit 전체(커버리지 제외) — 커밋 전 빠른 확인
composer test:unit            # DB 불필요 유닛 스위트만(~0.3초) — 개발 중 즉시 피드백
composer analyse              # PHPStan 단독 실행
composer cs                   # PHP CS Fixer 검사(dry-run) — CI와 동일
composer cs-fix               # PHP CS Fixer 자동 수정
composer check                # CS Fixer → PHPStan → PHPUnit 순차 (CI 게이트와 동일, 푸시 전 권장)
composer check:fast           # CS → PHPStan → 유닛만 — 빠른 로컬 게이트(feature 제외)
composer hooks:install        # Git 훅(pre-commit cs-fix · pre-push check) 활성화 — 클론당 최초 1회
```

> **Git 훅으로 CI 왕복 예방** — `composer hooks:install` 로 `.githooks/` 를 활성화하면 커밋 시 CS 자동수정(`pre-commit`), push 시 `composer check`(`pre-push`)가 자동 실행돼 red 상태 push 를 차단한다. 상세는 [`.githooks/README.md`](.githooks/README.md). 긴급 우회는 `SKIP_HOOKS=1`.

### 로컬 검증 선행 (CI 왕복 예방)
`composer check`(CS·PHPStan·PHPUnit)를 **PHP·Composer 가 있는 환경에서 push 전에 반드시 로컬 실행**한다. 이 단계를 건너뛰면 CS/PHPStan/PHPUnit 실패를 CI에서야 발견해 커밋 왕복이 생긴다. `.githooks/pre-push` 를 활성화하면(`composer hooks:install`) 자동으로 강제된다.

푸시(또는 PR 리뷰 요청) 전 권장 순서:
```bash
composer cs-fix     # 포맷 자동수정 — pre-commit 훅이 있으면 커밋 시 자동 수행
composer check      # CS Fixer → PHPStan(L6) → PHPUnit, CI 게이트와 동일
```
개발 중 빠른 피드백은 `composer test:unit`(DB 불필요, ~0.3초), 커밋 전 전체 확인은 `composer test:fast`(커버리지 제외). 마이그레이션이 필요한 검증은 `php spark migrate --all` 사용(그냥 `migrate`는 Shield `users` 테이블 누락으로 FK 실패).

> **PHP 가 없는 Windows 체크아웃(`E:\claude_works\AICassa`)이라면** 코드 검증을 **별도 WSL 클론**(`~/claude-works/AICassa`, Ubuntu-24.04 — Windows 체크아웃과 별개 클론, `php`=8.5·확장 완비, dev 의존성이 `ext-sqlite3` 요구)에서 수행한다.
> - **실행**: `wsl.exe -d Ubuntu-24.04 -- bash -lc 'cd ~/claude-works/AICassa && <명령>'`. 중첩 따옴표·`$()`·리다이렉트는 인터롭에서 깨지므로, 복잡하면 스크래치패드에 `.sh`를 쓰고 `/mnt/c/...` 경로로 실행한다.
> - **동기화**: 별개 클론이므로 Windows에서 커밋·푸시한 뒤 WSL에서 `git fetch origin && git checkout <branch> && git pull` 로 맞춘 다음 검증한다. `cs-fix`가 수정한 파일은 커밋에 반드시 포함한다.

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
| `.claude/rules/` | 저장소 전용 상세 규칙 (위 [상세 규칙](#상세-규칙-clauderules) 참조) |
