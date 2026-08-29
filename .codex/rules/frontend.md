# 프론트엔드 라이브러리 규칙 (AICassa 전용)

Codex가 자동으로 적용하는 작업 지침은 저장소 루트의 [`AGENTS.md`](../../AGENTS.md)에 있다. 이 문서는 프론트엔드 규칙의 참고 원본이다.

## 데이터 그리드 — AG Grid Community

목록성 화면(장부·거래처 테이블 등)은 AG Grid Community를 사용한다. 기본 테마는 `ag-theme-alpine`이다. 서버사이드 페이지네이션은 `serverSideDatasource`를 사용한다. HTML 셀은 `cellRenderer`로 렌더링하고 `innerHTML`을 직접 조작하지 않는다.

## 차트 — Chart.js

통계·영업현황표 차트는 Chart.js를 사용한다. 차트 데이터는 컨트롤러에서 `$labels`, `$values`로 분리해 전달한다. 민감 집계 데이터는 별도 API 엔드포인트를 검토한다.

## 리치 에디터 — Tiptap

필요할 때 헤드리스 에디터인 Tiptap을 ES module CDN으로 로드한다. 폼 제출 시 `editor.getHTML()`을 hidden input에 동기화하고, 저장 내용을 출력할 때는 `esc($content, 'html')` 또는 허용 태그 화이트리스트 필터를 적용한다.

## 엑셀 — PhpSpreadsheet

기본 읽기는 `IOFactory::load($filePath)->getActiveSheet()->toArray()`를 사용한다. 1만 행 이상은 `ChunkReadFilter`로 청크 처리한다. 업로드 파일은 `writable/uploads/`에 저장한 뒤 처리 완료 즉시 삭제한다.
