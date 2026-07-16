# 서버 배포 후 해야 할 일 (Post-Deploy Setup)

`dev` → `main` PR 머지 시 CD([`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml))가 SSH 로 운영 서버에 배포한다. 이 문서는 **CD 가 자동으로 처리하지 않아 사람이 직접 해야 하는 일**을 정리한다.

- 최초 설치 절차(요구사항·확장·DB 생성)는 [`설치_가이드.md`](설치_가이드.md), 배포 개요는 [README › 배포](../README.md#배포-deployment) 참조.
- ⚠️ **`dev` → `main` 은 반드시 Merge commit(Squash 금지).** Squash 하면 `main` 이 `dev` 조상에서 이탈해 이후 배포마다 3-way 충돌이 재발한다.

---

## 0. CD 가 자동으로 하는 일 (사람이 다시 안 해도 됨)

매 배포 시 `deploy.yml` 이 서버에서 자동 실행한다:

| 단계 | 명령 |
|---|---|
| 최신 `main` 반영 | `git fetch --prune` → `git reset --hard origin/main` |
| `writable/` 디렉토리 보장 | `mkdir -p writable/{cache,logs,session,uploads,debugbar}` + `chmod 2775` |
| 운영 의존성 설치 | `composer install --no-dev --optimize-autoloader` |
| DB 마이그레이션 | `php spark migrate --all -f` (예외 감지 시 배포 중단) |
| 캐시 정리 | `php spark cache:clear` |
| 무중단 리로드 | `sudo systemctl reload apache2` (OPcache 갱신) |

> 즉 **코드·의존성·스키마·캐시·재기동은 자동**이다. 아래 1~4는 CD 가 손대지 **않는** 부분이다.

---

## 1. 최초 1회만 (서버 최초 구축 시)

배포 파이프라인이 돌기 **전**, 서버를 처음 세팅할 때 한 번만 한다. 이후 배포에서는 반복하지 않는다.

### 1-1. 운영 `.env` 작성 (⚠️ 절대 커밋 금지)
서버의 배포 경로(`DEPLOY_PATH`)에 `.env` 를 두고 아래 **운영 값**을 채운다. `.env` 는 `git reset --hard` 대상이 아니므로 배포로 덮어써지지 않는다(`.gitignore` 등록됨).

```dotenv
CI_ENVIRONMENT = production                 # ⚠️ 필수 — 스택 트레이스 노출 차단
app.baseURL = 'https://실서비스도메인/'      # 실제 도메인 (끝 슬래시 포함)
app.forceGlobalSecureRequests = true        # HTTPS 강제 (TLS 적용 후)

database.default.hostname = 127.0.0.1
database.default.database = aicassa
database.default.username = aicassa         # root 지양 — 전용 계정
database.default.password = <강한-비밀번호>
database.default.DBDriver = MySQLi
database.default.port = 3306

JWT_SECRET = <32자 이상 랜덤 문자열>          # API 인증. 노출·재사용 금지

# 선택 — AI 기능 (미설정 시 비-AI 폴백 동작)
ANTHROPIC_API_KEY = <키>
# ANTHROPIC_MODEL = claude-sonnet-5

# 세법 파라미터 운영자 콘솔 (사업장 Admin 과 별도)
operator.id = admin
operator.password = <강한-비밀번호>          # 평문 또는 password_hash() bcrypt 해시
```

### 1-2. 암호화 키 생성
세션·Shield 인증용 키를 `.env` 에 기록한다(1회).
```bash
php spark key:generate
```

### 1-3. DB 생성 + 최초 마이그레이션
DB 자체는 CD 가 만들지 않는다. 사전에 생성한다.
```sql
CREATE DATABASE aicassa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
```bash
php spark migrate --all      # 최초 1회 수동. 이후는 CD 가 자동 수행
```

### 1-4. 관리자(Shield) 계정 생성
Admin 로그인 사용자를 만든다.
```bash
php spark shield:user create
```

### 1-5. 웹서버 문서 루트 = `public/`
문서 루트를 반드시 **`public/`** 으로 지정한다(프로젝트 루트 금지 — `.env`·소스 노출). Apache 는 `public/.htaccess`, Nginx 는 `try_files` 로 `index.php` 라우팅.

### 1-6. `sudo` 무암호 권한 (CD 리로드용)
`deploy.yml` 마지막 단계가 `sudo -n systemctl reload apache2` 를 실행한다. 배포 사용자에게 이 명령만 무암호로 허용한다.
```bash
# /etc/sudoers.d/deploy
<DEPLOY_USER> ALL=(root) NOPASSWD: /bin/systemctl reload apache2
```

---

## 2. 매 배포 후 확인 (스모크 테스트)

배포가 끝나면(Actions 녹색) 서비스가 실제로 뜨는지 확인한다.

```bash
php spark routes            # 라우트 정상 등록 확인
tail -n 50 writable/logs/log-$(date +%Y-%m-%d).php   # 신규 에러 없는지
```

브라우저/`curl` 로 최소 경로 점검:
- [ ] 메인 페이지 200 응답, `index.php` 가 URL 에 노출되지 않음
- [ ] Admin 로그인 성공(세션 정상)
- [ ] `/api/docs` (Swagger UI) 접근 가능
- [ ] 엑셀 업로드/다운로드 1건 (PhpSpreadsheet 확장 정상)
- [ ] (AI 사용 시) 영수증 판독 등 1건 — 실패해도 폴백으로 500 안 나는지

> 마이그레이션은 CD 가 자동 적용하지만, `deploy.yml` 은 예외 패턴을 grep 으로만 감지한다. **새 마이그레이션이 포함된 배포**는 위 로그 확인을 반드시 한다.

---

## 3. 참조데이터 시딩 (⚠️ 자동 실행 안 됨)

**`ReferenceDataSeeder` 는 CD 가 실행하지 않는다.** 아래 두 경우에만 서버에서 수동 실행한다.

- **최초 배포 시** (기준 데이터가 비어 있음)
- **참조데이터 개정 시** — 감가상각률표·업종코드·**연도별 세법 파라미터** 변경

```bash
php spark db:seed ReferenceDataSeeder
```

> 증상: 계정과목·상각률이 비어 있거나 세액 계산이 0 → 이 시더 미실행이 원인. 세법 버전 관리 규칙은 [README › 개정세법 버전 관리](../README.md#개정세법-버전-관리-tax-rule-versioning) 참조.

---

## 4. 보안 점검 체크리스트 (배포 후)

| 항목 | 확인 |
|---|---|
| `CI_ENVIRONMENT = production` | 스택 트레이스·디버그바 비노출 |
| 문서 루트 = `public/` | `.env`·`app/`·`vendor/` 웹 접근 불가 |
| `.env` 미커밋 | `git status` 에 `.env` 없음, `.gitignore` 등록 확인 |
| HTTPS/TLS 적용 | `app.forceGlobalSecureRequests = true` |
| DB 계정 최소권한 | `root` 아님, 해당 스키마 권한만 |
| `JWT_SECRET`·`operator.password` 강함 | 기본값·예시값 사용 금지 |
| `writable/` 소유권 | 웹서버 사용자(`www-data`) 쓰기 가능, 웹 접근 불가 |

---

## 5. 롤백

CD 실패 또는 배포 후 장애 시:

1. **재배포/수동 배포**: GitHub Actions → CD 워크플로우 → **Run workflow**(`workflow_dispatch`)로 재실행.
2. **이전 버전으로 되돌리기**: `main` 을 직전 정상 커밋으로 되돌리는 PR(Merge commit) 후 재배포. 서버에서 직접 `git reset` 하지 말 것 — 다음 CD 가 `origin/main` 으로 다시 덮어쓴다.
3. **마이그레이션 롤백이 필요하면**: `php spark migrate:rollback` 은 데이터 손실 위험이 있으므로 DB 백업 확인 후 신중히.

---

## 6. CD 사전 요건 — GitHub Actions Secrets

CD 가 동작하려면 저장소 **Settings → Secrets and variables → Actions** 에 아래가 등록돼 있어야 한다(서버 최초 구축 시 1회).

| Secret | 용도 |
|---|---|
| `DEPLOY_HOST` | 운영 서버 호스트/IP |
| `DEPLOY_USER` | SSH 접속 사용자 |
| `DEPLOY_SSH_KEY` | 접속용 개인키 |
| `DEPLOY_PORT` | SSH 포트 |
| `DEPLOY_PATH` | 서버 내 배포 경로(`.env`·소스 위치) |
| `SLACK_WEBHOOK_URL` | 배포 성공/실패 Slack 알림용 Incoming Webhook URL (Slack App 관리 → Incoming Webhooks → 채널 선택 → URL 발급) |

> **현 상태 주의**: 실운영 서버가 아직 없어 CD 워크플로우는 수동 비활성화되어 있다. 서버 구축·Secrets 등록 후 활성화한다.

---

## 참고

- 최초 설치·요구사항·트러블슈팅: [`설치_가이드.md`](설치_가이드.md)
- 배포 개요·세법 버전 관리: [README › 배포](../README.md#배포-deployment)
- 명령어·디렉토리 규칙: [CLAUDE.md](../CLAUDE.md)
