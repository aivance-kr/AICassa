# Git 워크플로우 · CI/CD 규칙

> AICassa 브랜치 전략·배포 파이프라인·커밋 규칙. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md) 참조.

## Git 워크플로우
```
feature/* → (PR) → dev → (PR) → main
```
- PR 대상: `feature/*` → `dev`
- 머지 방식: `feature/* → dev`는 **Squash and merge**, `dev → main`(배포)은 **Merge commit** (⚠️ Squash 금지)
  - dev → main 을 Squash 하면 main 이 dev 조상에서 이탈해 이후 배포마다 3-way 충돌이 재발한다. 반드시 merge commit 으로 main 을 dev 의 조상으로 유지한다.
- `main`·`dev` 직접 push 금지 (단, 아래 **문서 전용 변경** 예외)

### 예외 — 문서 전용 변경은 dev 직접 반영
코드·설정·로직을 전혀 건드리지 않고 **문서만** 수정하는 경우엔 feature 브랜치·PR 없이 `dev`에 직접 커밋·push 한다.
```bash
git checkout dev && git pull origin dev
# 문서만 수정 후
git commit -m "docs: ..." && git push origin dev
```
- **대상(문서 전용)**: `*.md`(`CLAUDE.md`·`.claude/rules/**`·`README.md`·`docs/**` 등), 주석만 고친 변경.
- **제외(반드시 feature/* → PR)**: `app/**`·`public/**` 코드, `app/Config/**`·`composer.json`·`*.neon`·`.github/**` 등 설정·빌드·CI, 마이그레이션·시더. 코드와 문서가 **섞인** 변경도 feature 브랜치로 처리한다.
- `main` 직접 push 는 예외 없이 계속 금지(배포는 `dev → main` PR).

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
