<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<h1>사업장</h1>
<p class="page-desc">간편장부는 사업장별로 작성합니다. 사업장을 선택하면 장부·거래처·자산대장·영업현황표·신고서식으로 이동할 수 있습니다.</p>
<div class="toolbar">
    <a href="/admin/businesses/new" class="btn">+ 사업장 등록</a>
</div>

<?php if ($businesses === []): ?>
    <p class="muted">등록된 사업장이 없습니다. 먼저 사업장을 등록하세요.</p>
<?php else: ?>
    <div id="grid" class="ag-theme-alpine" style="height:480px; width:100%;"></div>
    <form id="deleteForm" method="post" style="display:none;"><?= csrf_field() ?></form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($businesses !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-theme-alpine.css">
<script>
function doDelete(url) {
    if (confirm('삭제하시겠습니까?')) {
        const f = document.getElementById('deleteForm');
        f.action = url;
        f.submit();
    }
}
// 유니코드를 \uXXXX(ASCII)로 이스케이프해 <script> 내부에서 안전하게 임베드
const rowData = <?= json_encode($businesses) ?>;
const gridOptions = {
    columnDefs: [
        { field: 'name', headerName: '상호', flex: 1 },
        { field: 'owner_name', headerName: '대표자', width: 120 },
        { field: 'biz_reg_no', headerName: '사업자번호', width: 140 },
        { field: 'industry_name', headerName: '업종', flex: 1 },
        { headerName: '제조업', width: 90, valueGetter: p => p.data.is_manufacturing == 1 ? 'O' : '' },
        {
            headerName: '관리', width: 220, sortable: false, filter: false,
            cellRenderer: p => {
                const id = p.data.id;
                return `<span class="actions-cell">`
                    + `<a href="/admin/businesses/${id}/ledger">장부</a>`
                    + `<a href="/admin/businesses/${id}/partners">거래처</a>`
                    + `<a href="/admin/businesses/${id}/edit">수정</a>`
                    + `<a href="#" class="del" onclick="doDelete('/admin/businesses/${id}/delete');return false;">삭제</a>`
                    + `</span>`;
            },
        },
    ],
    rowData: rowData,
    pagination: true,
    paginationPageSize: 20,
    defaultColDef: { sortable: true, resizable: true },
};
agGrid.createGrid(document.getElementById('grid'), gridOptions);
</script>
<?php endif; ?>
<?= $this->endSection() ?>
