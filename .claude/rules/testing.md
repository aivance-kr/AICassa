# 테스트 규칙

> AICassa 테스트 작성·실행 규칙. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md) 참조.

```bash
composer test
```
- 단위: `tests/unit/` — 외부 의존성 Mock
- 통합: `tests/feature/` — CIUnitTestCase + DB 트랜잭션 롤백
- 커버리지 목표: Service 레이어 80% 이상 (부가세·감가상각 계산은 반드시 테스트)
- 테스트 DB는 `.env.testing` 별도 설정 — 운영 DB 절대 사용 금지
- 새 기능 구현 시 테스트 코드를 함께 작성한다
