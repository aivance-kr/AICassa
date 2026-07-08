<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>장부 <span class="muted">— <?= esc($business['name']) ?></span></h1>

<div class="toolbar">
    <a href="/admin/businesses/<?= $bid ?>/ledger/new" class="btn">+ 거래 입력</a>
    <a href="/admin/businesses/<?= $bid ?>/partners" class="btn secondary">거래처</a>
    <a href="/admin/businesses" class="btn secondary">← 사업장 목록</a>
</div>

<form method="get" class="filter" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">
    <div>
        <label class="muted" style="display:block; font-size:12px;">귀속연도</label>
        <select name="fiscal_year">
            <option value="">전체</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= (string) ($filters['fiscal_year'] ?? '') === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="muted" style="display:block; font-size:12px;">구분</label>
        <select name="entry_type">
            <option value="">전체</option>
            <option value="income" <?= ($filters['entry_type'] ?? '') === 'income' ? 'selected' : '' ?>>수입</option>
            <option value="expense" <?= ($filters['entry_type'] ?? '') === 'expense' ? 'selected' : '' ?>>비용</option>
        </select>
    </div>
    <div>
        <label class="muted" style="display:block; font-size:12px;">시작일</label>
        <input type="date" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
    </div>
    <div>
        <label class="muted" style="display:block; font-size:12px;">종료일</label>
        <input type="date" name="date_to" value="<?= esc($filters['date_to'] ?? '') ?>">
    </div>
    <button type="submit" class="btn secondary">조회</button>
</form>

<div style="display:flex; gap:12px; margin-bottom:16px;">
    <div class="card-sum" style="flex:1; background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px;">
        <div class="muted" style="font-size:13px;">수입 합계 (부가세)</div>
        <div style="font-size:20px; font-weight:700; color:var(--primary);">
            <?= number_format($summary['income']) ?><span class="muted" style="font-size:13px;"> (<?= number_format($summary['income_vat']) ?>)</span>
        </div>
    </div>
    <div class="card-sum" style="flex:1; background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px;">
        <div class="muted" style="font-size:13px;">비용 합계 (부가세)</div>
        <div style="font-size:20px; font-weight:700; color:#b45309;">
            <?= number_format($summary['expense']) ?><span class="muted" style="font-size:13px;"> (<?= number_format($summary['expense_vat']) ?>)</span>
        </div>
    </div>
</div>

<?php if ($entries === []): ?>
    <p class="muted">해당 조건의 거래가 없습니다.</p>
<?php else: ?>
    <div id="grid" class="ag-theme-alpine" style="height:520px; width:100%;"></div>
    <form id="actionForm" method="post" style="display:none;"><?= csrf_field() ?></form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($entries !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-theme-alpine.css">
<script>
const BID = <?= (int) $bid ?>;
function doPost(url, msg) {
    if (!msg || confirm(msg)) {
        const f = document.getElementById('actionForm');
        f.action = url;
        f.submit();
    }
}
const won = p => (p.value == null ? '' : Number(p.value).toLocaleString());
const rowData = <?= json_encode($entries) ?>;
const gridOptions = {
    columnDefs: [
        { field: 'entry_date', headerName: '일자', width: 110 },
        { field: 'entry_type_label', headerName: '구분', width: 80 },
        { field: 'account_name', headerName: '계정과목', width: 120 },
        { field: 'description', headerName: '거래내용', flex: 1 },
        { field: 'partner_name', headerName: '거래처', width: 130 },
        { field: 'supply_amount', headerName: '공급가액', width: 120, type: 'rightAligned', valueFormatter: won },
        { field: 'vat', headerName: '부가세', width: 110, type: 'rightAligned', valueFormatter: won },
        { field: 'evidence_label', headerName: '증빙', width: 100 },
        {
            headerName: '관리', width: 170, sortable: false, filter: false,
            cellRenderer: p => {
                const id = p.data.id;
                const base = `/admin/businesses/${BID}/ledger/${id}`;
                return `<span class="actions-cell">`
                    + `<a href="${base}/edit">수정</a>`
                    + `<a href="#" onclick="doPost('${base}/copy');return false;">복사</a>`
                    + `<a href="#" class="del" onclick="doPost('${base}/delete','삭제하시겠습니까?');return false;">삭제</a>`
                    + `</span>`;
            },
        },
    ],
    rowData: rowData,
    pagination: true,
    paginationPageSize: 25,
    defaultColDef: { sortable: true, resizable: true },
};
agGrid.createGrid(document.getElementById('grid'), gridOptions);
</script>
<?php endif; ?>
<?= $this->endSection() ?>
