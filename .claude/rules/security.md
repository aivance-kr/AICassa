# 보안 규칙

> AICassa 보안 필수 규칙. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md), 일반 코딩 규칙은 [code-style.md](code-style.md) 참조.

## 입력·출력 처리 원칙
- 입력값은 반드시 CI4 Validation 또는 `esc()` 처리 (`$this->request->getPost()` 사용, `$_GET`/`$_POST` 직접 사용 금지)
- SQL은 CI4 Query Builder만 사용 — raw query·문자열 직접 조합 금지
- 시크릿은 `.env`에서만 관리 (`env('KEY')`) — 코드 하드코딩 금지
- POST 폼에는 `<?= csrf_field() ?>` 필수 (Admin 뷰)

## 절대 금지 — 보안
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

## 관련 참고
- 외부 API 호출은 타임아웃 필수 (기본 5초) — [architecture.md](architecture.md) 성능·DB 원칙
- 업로드 파일은 `writable/uploads/`에 저장 후 처리, 완료 시 임시 파일 즉시 삭제 — [frontend.md](frontend.md) 엑셀 처리
- 리치 에디터 저장 출력은 `esc($content, 'html')` 또는 허용 태그 화이트리스트 필터 — [frontend.md](frontend.md)
