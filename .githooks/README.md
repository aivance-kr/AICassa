# Git 훅 (개발 속도 개선)

CI 왕복(push → CI 실패 → 재커밋)을 로컬에서 미리 차단하는 공유 훅이다. `core.hooksPath` 로 활성화한다.

> GitHub Actions CI(`ci.yml`)는 `dev → main` 배포 PR 에서만 돈다 — `feature → dev` PR 은 CI 가 없고 이 훅(특히 `pre-push`)이 실질적 게이트다. 자세한 검증 게이트 구조는 [`CLAUDE.md` › 검증 게이트](../CLAUDE.md#검증-게이트--어디서-무엇을-돌리는가) 참조.

## 활성화 (최초 1회, 클론마다)

```bash
composer install        # post 스크립트가 없으므로 아래를 직접 실행
composer hooks:install  # git config core.hooksPath .githooks + 실행권한 부여
```

> 확인: `git config core.hooksPath` → `.githooks` 가 나오면 활성.

## 훅 목록

| 훅 | 동작 | 목적 |
|----|------|------|
| `pre-commit` | 스테이징된 `*.php` 를 PHP CS Fixer 로 자동수정 후 재-스테이징 | CS 위반이 CI 에서야 터지는 것을 차단 |
| `pre-push` | `composer check`(CS · PHPStan · PHPUnit) 실행, 실패 시 push 중단 | red 상태 push → CI 왕복 차단 |

## 긴급 우회

훅을 건너뛰어야 할 때(예: WIP 브랜치 백업 push):

```bash
SKIP_HOOKS=1 git commit ...
SKIP_HOOKS=1 git push ...
```

- PHP·Composer 가 없는 환경에서는 `pre-push` 가 자동으로 검증을 건너뛴다(CI 가 최종 검증).
