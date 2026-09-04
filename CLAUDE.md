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
app.baseURL = http://localhost:8302/
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
php spark serve --host 127.0.0.1 --port 8302   # 개발 서버 (cassa.test, Caddy 리버스 프록시 경유)
php spark migrate             # DB 마이그레이션
php spark swagger:generate    # OpenAPI 스펙 생성 (public/swagger.json)
```

> ⚠️ **`--host` 를 빼면 `cassa.test` 접속이 `502 Bad Gateway` 로 실패한다.** `--host` 없이 기본값 `localhost` 로 바인딩하면 이 macOS 환경에서는 IPv6(`::1`)로만 리슨되는데, `cassa.test` 를 프록시하는 공용 Caddy(`~/claude-works/dev-proxy/Caddyfile`)는 `127.0.0.1:8302`(IPv4)로 연결을 시도해 거부당한다. 반드시 `--host 127.0.0.1` 을 명시할 것.

```bash
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

### 검증 게이트 — 어디서 무엇을 돌리는가
검증은 로컬에서 끝낸다. `feature → dev` PR 에는 CI 를 걸지 않고, CI 는 `dev → main` 배포 PR 에서만 돈다.

```
feature/*  ──[로컬 검증: composer check]──▶  dev  ──[PR + CI]──▶  main
                    ↑                          ↑
              여기가 실질적 게이트          여기서만 CI 가 돈다
```

| 시점 | 무엇을 | 누가 |
|---|---|---|
| 개발 중 | `composer test:unit`(DB 불필요) | 사람 / Claude, 수시로 |
| `dev` 푸시 전 | `composer check`(CS·PHPStan·PHPUnit) 전체 필수 — 실패하면 푸시하지 않는다 | 사람 / Claude 로컬 |
| `feature → dev` PR | CI 없음, 코드 리뷰만 | — |
| `dev → main` PR | GitHub Actions 전체(`ci.yml`) | CI |

`.github/workflows/ci.yml` 의 트리거는 `main` 대상 `pull_request` 로만 한정된다(`branches: [main]`). `feature → dev` 에 CI 가 없다는 건 `dev` 브랜치가 검증받지 않은 코드를 받을 수 있다는 뜻이라, **로컬 검증이 유일한 방어선**이다 — 생략하면 여러 기능이 쌓인 뒤 배포 PR 에서야 CI 가 처음 돌아 어느 커밋이 깨뜨렸는지 찾는 비용이 커진다. Claude 가 작업할 때도 동일하다 — `dev` 로 올리는 PR 을 만들기 전에 `composer check` 를 실제로 실행하고 출력을 확인한 뒤 진행한다("통과할 것 같다"로 넘어가지 않는다).

#### self-hosted 러너에서 돈다
GitHub 호스팅 러너(`ubuntu-latest`)가 아니라 **조직(`aivance-kr`) 레벨 self-hosted 러너 1대**를 등록해서 돈다 — 저장소별 러너가 아니라 조직의 모든 저장소가 이 러너 하나를 공유한다. `ci.yml`·`deploy.yml` 모두 `runs-on: [self-hosted, Linux, X64]`.

- **전환 계기**: 2026-07-30, `deploy.yml`(당시 `ubuntu-latest`)이 "recent account payments have failed or your spending limit needs to be increased"로 잡이 시작조차 못 하고 실패 — GitHub 결제/지출한도 문제로 호스팅 러너가 막히면 CI 뿐 아니라 배포까지 멈춘다. `ci.yml`은 이미 self-hosted 였어서 영향 없었고, `deploy.yml`도 동일하게 전환했다.
- **러너 구성**: 조직(`aivance-kr`) 설정에서 등록한 Linux/X64 러너 1대 — 저장소마다 별도 러너를 두지 않고 이 러너 하나를 모든 워크플로가 공유한다. 러너가 1대뿐이므로 여러 저장소·워크플로의 잡이 동시에 몰려도 순차 실행된다(진짜 동시 실행 충돌 없음).
- **MySQL**: Linux self-hosted 러너는 `services:` 도커 컨테이너를 지원한다(macOS 러너 시절엔 미지원이라 `docker run` 을 잡에서 직접 기동·정리했으나 더 이상 필요 없음) — `ci.yml` 은 표준 `services:` 블록으로 MySQL 을 띄운다.
- **포트**: 러너가 1대뿐이라 잡이 순차 실행되므로 포트 충돌 우려가 없다 — 표준 포트 **3306** 을 그대로 쓴다(과거 macOS 러너 시절 시스템 `mysqld`·다른 저장소 CI 와의 충돌을 피하려 썼던 33306 오버라이드는 제거).
- **배포(`deploy.yml`)**: SSH 배포 스크립트 자체는 원격 운영 서버에서 실행되므로 러너 종류와 무관하다 — `appleboy/ssh-action`(Docker 컨테이너 액션)이 러너 호스트의 Docker 위에서 실행되고 그 안에서 운영 서버로 SSH 접속한다.
- **호스팅 러너로 되돌리려면**: `runs-on` 을 `ubuntu-latest` 로 바꾸면 된다(MySQL `services:` 블록·포트는 그대로 유지 가능). 단, GitHub 결제 문제가 해결되지 않으면 호스팅 러너로는 잡이 다시 시작되지 않는다.

`composer check`(CS·PHPStan·PHPUnit)를 **PHP·Composer 가 있는 환경에서 push 전에 반드시 로컬 실행**한다. 이 단계를 건너뛰면 CS/PHPStan/PHPUnit 실패를 CI에서야 발견해 커밋 왕복이 생긴다. `.githooks/pre-push` 를 활성화하면(`composer hooks:install`) push 대상 브랜치와 무관하게 자동으로 강제된다(feature 브랜치 포함 — 이 저장소는 참고 정책과 달리 feature 푸시도 훅으로 게이트한다).

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
