# 아키텍처 규칙 (AICassa 전용)

> 공통 API 설계·레이어 책임·성능/DB·정적분석 규칙은 전역 [`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md)·[`code-style.md`](~/.claude/rules/code-style.md)·[`testing.md`](~/.claude/rules/testing.md) 참조. 이 문서는 AICassa 고유 패턴만 정의한다.

## JWT 인증 흐름
`JwtAuthFilter`가 토큰 검증 후 `Auth::setUserId()`로 정적 홀더에 저장, 컨트롤러는 `$this->authUserId()`로 사용.
```php
Auth::setUserId((int) $payload['sub']);   // JwtAuthFilter
$userId = $this->authUserId();            // BaseApiController 상속 컨트롤러
```

## Admin 뷰 렌더링
`BaseAdminController::render()`가 공통 데이터(세션 `authUser`)를 자동 병합한다. 반드시 `$this->render()`를 사용한다.
```php
return $this->render('admin/ledger/index', ['entries' => $entries]);   // ✅
return view('admin/ledger/index', ['entries' => $entries]);            // ❌ authUser 누락
```

## 데이터 접근
별도 Repository 레이어 없이 CI4 Model 을 데이터 접근 계층으로 사용, 복잡한 쿼리는 Model 메서드로 캡슐화. 데이터 접근은 `model(XxxModel::class)` 헬퍼 경유(직접 `new` 금지). Model 의 `$returnType`은 `'array'`로 통일.
