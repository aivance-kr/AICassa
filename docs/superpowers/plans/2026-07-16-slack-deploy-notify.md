# 배포 후 Slack 알림 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `dev → main` 배포(CD) 성공/실패를 Slack 채널로 즉시 통보한다.

**Architecture:** `.github/workflows/deploy.yml`의 기존 SSH 배포 스텝에 `id: deploy`를 부여하고, `if: always()` 조건의 신규 shell 스텝에서 `steps.deploy.outcome`을 읽어 `jq`로 안전하게 JSON 페이로드를 조립한 뒤 `curl`로 Slack Incoming Webhook에 POST한다. 알림 실패가 배포 성공/실패 판정에 영향을 주지 않도록 격리한다.

**Tech Stack:** GitHub Actions (YAML), bash, `curl`, `jq`(ubuntu-latest 기본 포함), Slack Incoming Webhook

## Global Constraints

- `.github/workflows/**` 변경은 문서 전용 예외 대상이 아니다 — `feature/*` → `dev` PR로 진행(전역 git-workflow.md).
- 커밋 메시지는 이모지 + Conventional Commits 접두어 + 한국어 설명.
- 신규 GitHub Secret `SLACK_WEBHOOK_URL`은 저장소 Settings에 별도 등록 필요(이 플랜 범위 밖 — 문서에만 안내).
- 이 작업은 YAML/bash 설정 변경이라 PHPUnit/PHPStan 대상 아님. `composer check`는 기존 PHP 코드에 영향 없으므로 통과해야 한다(회귀 확인용).

---

### Task 1: deploy.yml에 Slack 알림 스텝 추가 + Secrets 문서 갱신

**Files:**
- Modify: `.github/workflows/deploy.yml` (기존 `Deploy over SSH` 스텝에 `id: deploy` 추가, 신규 알림 스텝 추가)
- Modify: `docs/setup.md` (Secrets 표에 `SLACK_WEBHOOK_URL` 행 추가)

**Interfaces:**
- Consumes: 기존 `Deploy over SSH` 스텝의 실행 결과(`steps.deploy.outcome` — GitHub Actions 표준 컨텍스트, `"success"` | `"failure"` | `"cancelled"` | `"skipped"`)
- Produces: 없음(워크플로우 최종 스텝, 후속 태스크 없음)

- [ ] **Step 1: `Deploy over SSH` 스텝에 `id: deploy` 추가**

`.github/workflows/deploy.yml`의 18번째 줄 부근, 기존 스텝을 아래처럼 수정한다(변경분은 `id: deploy` 한 줄 추가뿐):

```yaml
    steps:
      - name: Deploy over SSH
        id: deploy
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: ${{ secrets.DEPLOY_USER }}
          key: ${{ secrets.DEPLOY_SSH_KEY }}
          port: ${{ secrets.DEPLOY_PORT }}
          command_timeout: 15m
          script: |
            set -e
            cd "${{ secrets.DEPLOY_PATH }}"

            echo "▶ 최신 main 반영"
            git fetch --prune origin
            git reset --hard origin/main

            echo "▶ writable 디렉토리 보장 (마이그레이션/부팅 전 필수 — 없으면 WRITEPATH 오류)"
            mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar
            # 런타임에 아파치(www-data)가 만든 파일 소유권 충돌 대비 best-effort
            chmod -R 2775 writable 2>/dev/null || echo "  (writable chmod 건너뜀)"

            echo "▶ 의존성 설치 (운영)"
            composer install --no-dev --optimize-autoloader --no-interaction

            echo "▶ DB 마이그레이션"
            # spark migrate 는 DB 오류·예외에도 종료코드 0 을 반환하므로
            # 출력을 캡처해 예외 패턴을 직접 검사하고, 감지 시 배포를 중단한다.
            MIGRATE_OUT="$(php spark migrate --all -f 2>&1)" || true
            echo "$MIGRATE_OUT"
            if echo "$MIGRATE_OUT" | grep -qiE "Exception|Unable to connect|Access denied|Call to|Fatal error"; then
              echo "✖ 마이그레이션 실패 감지 — 배포 중단"
              exit 1
            fi

            echo "▶ 캐시 정리"
            php spark cache:clear || true

            echo "▶ 아파치 리로드 (OPcache 갱신, 무중단)"
            sudo -n systemctl reload apache2

            echo "✔ 배포 완료"
```

**Step 1 검증:** `id: deploy` 한 줄만 추가됐는지 `git diff .github/workflows/deploy.yml`로 확인.

- [ ] **Step 2: Slack 알림 스텝 추가**

같은 파일, `Deploy over SSH` 스텝 바로 아래(파일 끝)에 이어서 추가:

```yaml
      - name: Slack 배포 알림
        if: always()
        env:
          SLACK_WEBHOOK_URL: ${{ secrets.SLACK_WEBHOOK_URL }}
          DEPLOY_OUTCOME: ${{ steps.deploy.outcome }}
          COMMIT_URL: ${{ github.server_url }}/${{ github.repository }}/commit/${{ github.sha }}
          RUN_URL: ${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}
          ACTOR: ${{ github.actor }}
          SHORT_SHA: ${{ github.sha }}
        run: |
          if [ -z "$SLACK_WEBHOOK_URL" ]; then
            echo "⚠ SLACK_WEBHOOK_URL 미설정 — 알림 건너뜀"
            exit 0
          fi

          if [ "$DEPLOY_OUTCOME" = "success" ]; then
            STATUS_TEXT="✅ 배포 성공"
            COLOR="#2eb886"
          else
            STATUS_TEXT="❌ 배포 실패"
            COLOR="#e01e5a"
          fi

          PAYLOAD="$(jq -n \
            --arg status "$STATUS_TEXT" \
            --arg color "$COLOR" \
            --arg commit_url "$COMMIT_URL" \
            --arg sha "${SHORT_SHA:0:7}" \
            --arg actor "$ACTOR" \
            --arg run_url "$RUN_URL" \
            '{
              attachments: [{
                color: $color,
                blocks: [{
                  type: "section",
                  text: {
                    type: "mrkdwn",
                    text: ("*" + $status + "* — AICassa\n*커밋:* <" + $commit_url + "|" + $sha + ">\n*실행자:* " + $actor + "\n*Run:* <" + $run_url + "|바로가기>")
                  }
                }]
              }]
            }')"

          curl -sS -X POST -H 'Content-type: application/json' \
            --data "$PAYLOAD" \
            "$SLACK_WEBHOOK_URL" || echo "⚠ Slack 알림 전송 실패(배포 결과에는 영향 없음)"
```

**Step 2 검증:** `git diff .github/workflows/deploy.yml`로 스텝이 파일 최상위 `steps:` 리스트 아래 올바른 들여쓰기(6칸)로 추가됐는지 확인. YAML 문법 오류 여부는 아래 Step 4에서 `yamllint`/`python -c`로 확인.

- [ ] **Step 3: `docs/setup.md`의 Secrets 표에 `SLACK_WEBHOOK_URL` 추가**

`docs/setup.md`의 "6. CD 사전 요건" 절, 기존 표(149~159번째 줄 부근)를 아래처럼 수정:

```markdown
| Secret | 용도 |
|---|---|
| `DEPLOY_HOST` | 운영 서버 호스트/IP |
| `DEPLOY_USER` | SSH 접속 사용자 |
| `DEPLOY_SSH_KEY` | 접속용 개인키 |
| `DEPLOY_PORT` | SSH 포트 |
| `DEPLOY_PATH` | 서버 내 배포 경로(`.env`·소스 위치) |
| `SLACK_WEBHOOK_URL` | 배포 성공/실패 Slack 알림용 Incoming Webhook URL (Slack App 관리 → Incoming Webhooks → 채널 선택 → URL 발급) |
```

- [ ] **Step 4: YAML 문법 검증**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))" && echo OK`
Expected: `OK` 출력(예외 없음)

- [ ] **Step 5: 회귀 확인 — 기존 PHP 코드 무영향**

Run: `composer check`
Expected: CS Fixer 0 fixable / PHPStan No errors / PHPUnit 기존 테스트 전부 PASS (이 태스크는 PHP 코드를 건드리지 않으므로 기존과 동일한 결과가 나와야 함)

- [ ] **Step 6: 커밋**

```bash
git add .github/workflows/deploy.yml docs/setup.md
git commit -m "✨ feat: 배포 성공/실패 Slack 알림 추가"
```

- [ ] **Step 7: PR 생성 및 병합**

```bash
git push -u origin feature/slack-deploy-notify
gh pr create --base dev --title "✨ feat: 배포 성공/실패 Slack 알림 추가" --body "$(cat <<'EOF'
## Summary
- deploy.yml에 Slack Incoming Webhook 알림 스텝 추가 (성공/실패 모두)
- docs/setup.md에 SLACK_WEBHOOK_URL Secret 안내 추가

## Test plan
- [x] YAML 문법 검증 (`python3 -c "import yaml; ..."`)
- [x] `composer check` 통과 (PHP 코드 무영향 회귀 확인)
- [ ] 저장소에 `SLACK_WEBHOOK_URL` Secret 등록 후 `workflow_dispatch`로 1회 수동 실행해 Slack 채널 도착 확인 (병합 후 사람이 수행)

🤖 Generated with Claude Code
EOF
)"
```

PR 승인 후 `gh pr merge <PR번호> --squash --delete-branch`로 머지(전역 git-workflow.md 규칙).

**Note:** `SLACK_WEBHOOK_URL` Secret이 아직 등록되지 않았다면 Step 2의 스텝은 "미설정 — 알림 건너뜀"으로 조용히 스킵되고 배포 자체는 정상 진행된다(Global Constraints 참조). Secret 등록은 저장소 관리자가 GitHub Settings에서 별도로 수행해야 하며 이 플랜 범위 밖이다.
