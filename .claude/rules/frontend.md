# 프론트엔드 라이브러리 규칙

> AICassa 프론트엔드 라이브러리 사용 규약. 상위 가이드는 [CLAUDE.md](../../CLAUDE.md) 참조.

## 데이터 그리드 — AG Grid Community
목록성 화면(장부·거래처 테이블 등)은 AG Grid 를 사용한다.
```html
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<div id="myGrid" style="height:500px;" class="ag-theme-alpine"></div>
<script>
const gridOptions = {
    columnDefs: [{ field: 'date', headerName: '일자' }, { field: 'account', headerName: '계정과목' }],
    rowData: <?= json_encode($rows) ?>,
    pagination: true,
    paginationPageSize: 20,
};
agGrid.createGrid(document.getElementById('myGrid'), gridOptions);
</script>
```
- 테마 `ag-theme-alpine` 기본, 서버사이드 페이징은 `serverSideDatasource`
- HTML 셀은 `cellRenderer` 사용 (innerHTML 직접 조작 금지)

## 차트 — Chart.js
통계·영업현황표 차트는 Chart.js 를 사용한다.
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```
- 데이터는 컨트롤러에서 `$labels`, `$values` 로 분리해 전달
- 민감 집계 데이터는 뷰 직접 노출 대신 API 엔드포인트 분리 고려

## 리치 에디터 — Tiptap (필요 시)
- 헤드리스 에디터. ES 모듈 CDN 로드, 폼 제출용 hidden input에 `editor.getHTML()` 동기화
- 저장 출력은 반드시 `esc($content, 'html')` 또는 허용 태그 화이트리스트 필터

## 엑셀 — PhpSpreadsheet
```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// 읽기
$rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet()->toArray();
```
- 대용량(1만 행 이상)은 청크 단위 처리(ChunkReadFilter)
- 업로드 파일은 `writable/uploads/` 에 저장 후 처리, 처리 완료 시 임시 파일 즉시 삭제
