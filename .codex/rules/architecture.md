# 아키텍처 규칙 (AICassa 전용)

Codex가 자동으로 적용하는 작업 지침은 저장소 루트의 [`AGENTS.md`](../../AGENTS.md)에 있다. 이 문서는 아키텍처 규칙의 참고 원본이다.

## JWT 인증 흐름

`JwtAuthFilter`가 토큰 검증 후 `Auth::setUserId()`로 정적 홀더에 저장한다. API 컨트롤러는 `BaseApiController`의 `$this->authUserId()`로 사용자 ID를 가져온다.

```php
Auth::setUserId((int) $payload['sub']); // JwtAuthFilter
$userId = $this->authUserId(); // BaseApiController 상속 컨트롤러
```

## Admin 뷰 렌더링

`BaseAdminController::render()`가 세션의 `authUser` 등 공통 데이터를 병합한다. Admin 컨트롤러에서는 `$this->render()`를 사용하고 `view()`를 직접 반환하지 않는다.

## 데이터 접근

CI4 Model을 데이터 접근 계층으로 사용한다. 복잡한 쿼리는 Model 메서드로 캡슐화하며 `model(XxxModel::class)` 헬퍼로 가져온다. Model을 직접 `new` 하지 않고 `$returnType`은 `'array'`로 통일한다.
