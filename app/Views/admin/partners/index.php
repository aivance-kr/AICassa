<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>거래처</h1>
<p class="page-desc">장부 거래에 사용할 거래처(상호·사업자등록번호)를 관리합니다. CSV로 한 번에 등록할 수 있습니다.</p>
<div class="toolbar">
    <a href="/admin/businesses/<?= $bid ?>/partners/new" class="btn">+ 거래처 등록</a>
    <a href="/admin/businesses/<?= $bid ?>/partners/import" class="btn secondary">CSV 일괄 업로드</a>
</div>

<?php if ($partners === []): ?>
    <p class="muted">등록된 거래처가 없습니다.</p>
<?php else: ?>
    <div id="grid" class="ag-theme-alpine" style="height:480px; width:100%;"></div>
    <form id="deleteForm" method="post" style="display:none;"><?= csrf_field() ?></form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($partners !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-theme-alpine.css">
<script>
const BID = <?= (int) $bid ?>;
function doDelete(url) {
    if (confirm('삭제하시겠습니까?')) {
        const f = document.getElementById('deleteForm');
        f.action = url;
        f.submit();
    }
}
// 유니코드를 \uXXXX(ASCII)로 이스케이프해 <script> 내부에서 안전하게 임베드
const rowData = <?= json_encode($partners) ?>;
const gridOptions = {
    columnDefs: [
        { field: 'name', headerName: '거래처 상호', flex: 1 },
        { field: 'biz_reg_no', headerName: '사업자번호', width: 160 },
        { field: 'phone', headerName: '연락처', width: 160 },
        {
            headerName: '관리', width: 150, sortable: false, filter: false,
            cellRenderer: p => {
                const id = p.data.id;
                return `<span class="actions-cell">`
                    + `<a href="/admin/businesses/${BID}/partners/${id}/edit">수정</a>`
                    + `<a href="#" class="del" onclick="doDelete('/admin/businesses/${BID}/partners/${id}/delete');return false;">삭제</a>`
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
