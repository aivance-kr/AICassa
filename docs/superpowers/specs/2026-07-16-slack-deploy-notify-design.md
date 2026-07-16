# 배포 후 Slack 알림 — 설계

## 목적
`dev → main` 머지로 CD(`deploy.yml`)가 실서버에 배포한 뒤, 성공/실패 여부를 Slack 채널로 즉시 통보한다. 현재는 Actions 탭을 직접 열어야만 배포 결과를 알 수 있다.

## 범위
- 대상: `.github/workflows/deploy.yml`
- 알림 시점: 배포 성공 시 / 실패 시 모두
- 연동 방식: Slack Incoming Webhook (신규 GitHub Secret `SLACK_WEBHOOK_URL`)
- 구현: 별도 서드파티 Action 없이 `curl` + `jq` 로 워크플로우 내 shell step에서 직접 POST

## 변경 사항

### 1. `deploy.yml`
- 기존 `Deploy over SSH` 스텝에 `id: deploy` 부여
- 그 뒤에 `Slack 배포 알림` 스텝 추가, `if: always()` — 이전 스텝 실패 여부와 무관하게 항상 실행
  - `steps.deploy.outcome` 값으로 성공(`success`)/실패 분기
  - 페이로드는 `jq -n`으로 조립해 특수문자(따옴표·개행 등)로 인한 JSON 깨짐 방지
  - 메시지 포함 정보: 상태 이모지(✅/❌) · 커밋 링크(`github.sha`) · 실행자(`github.actor`) · Actions 실행 링크(`github.run_id`)
  - 성공은 초록(`#2eb886`), 실패는 빨강(`#e01e5a`) attachment color로 구분
  - `SLACK_WEBHOOK_URL` 미설정(예: 시크릿 등록 전) 시에도 워크플로우 자체가 깨지지 않도록 curl 실패는 배포 성공/실패 판정에 영향 주지 않음(알림 자체 실패는 무시)

### 2. `docs/setup.md`
- "6. CD 사전 요건 — GitHub Actions Secrets" 표에 `SLACK_WEBHOOK_URL` 행 추가
- Slack Incoming Webhook 발급 경로 한 줄 안내(Slack App 관리 → Incoming Webhooks → 채널 선택 → URL 발급)

## 에러 처리
- Slack 알림 자체가 실패(webhook 오류, 네트워크 문제)해도 배포 워크플로우의 최종 성공/실패 판정에는 영향을 주지 않는다 — 알림은 부가 기능이지 배포 게이트가 아니다.

## 테스트
- GitHub Actions 워크플로우이므로 로컬 유닛 테스트 대상 아님.
- `workflow_dispatch` 로 실제(또는 스테이징) 환경에서 1회 수동 실행해 Slack 채널에 메시지 도착 확인(성공 케이스). 실패 케이스는 임시로 배포 스텝을 깨뜨려 확인 후 원복하거나, 리뷰 시 코드 검토로 갈음.

## 브랜치 전략
`.github/workflows/**` 는 문서 전용 예외 대상이 아니므로 `feature/slack-deploy-notify` → `dev` PR로 진행한다.
