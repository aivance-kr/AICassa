<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>장부</h1>
<p class="page-desc">일자별 수입·비용 거래를 입력·관리합니다. 증빙유형에 따라 부가세가 자동 계산됩니다.</p>

<div class="toolbar">
    <a href="/admin/businesses/<?= $bid ?>/ledger/new" class="btn">+ 거래 입력</a>
    <a href="/admin/businesses/<?= $bid ?>/ledger/import" class="btn secondary">CSV 일괄 업로드</a>
</div>

<form method="get" action="/admin/businesses/<?= $bid ?>/ledger/search" class="nl-search"
      style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
    <input type="text" name="q" value="<?= esc($query ?? '') ?>" style="flex:1;"
           placeholder="자연어로 검색 — 예: 지난달 접대비 50만원 넘는 건, 올해 스타벅스 거래">
    <button type="submit" class="btn">AI 검색</button>
</form>

<?php if (! empty($filterSummary)): ?>
    <div class="filter-chips muted" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; font-size:13px;">
        <span>적용된 필터:</span>
        <?php foreach ($filterSummary as $chip): ?>
            <span style="background:#eef2ff; color:#3730a3; border-radius:12px; padding:2px 10px;"><?= esc($chip) ?></span>
        <?php endforeach; ?>
        <a href="/admin/businesses/<?= $bid ?>/ledger">초기화</a>
    </div>
<?php endif; ?>

<form method="get" class="filter" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">
    <?php // 자연어 검색으로 해석된 계정과목·거래처 필터는 hidden 으로 보존(수동 재조회 대비) ?>
    <?php if (! empty($filters['account_id'])): ?>
        <input type="hidden" name="account_id" value="<?= (int) $filters['account_id'] ?>">
    <?php endif; ?>
    <?php if (! empty($filters['partner_id'])): ?>
        <input type="hidden" name="partner_id" value="<?= (int) $filters['partner_id'] ?>">
    <?php endif; ?>
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
    <div>
        <label class="muted" style="display:block; font-size:12px;">거래내용</label>
        <input type="text" name="keyword" value="<?= esc($filters['keyword'] ?? '') ?>" placeholder="검색어">
    </div>
    <div>
        <label class="muted" style="display:block; font-size:12px;">최소금액</label>
        <input type="number" name="amount_min" min="0" style="width:110px;" value="<?= esc($filters['amount_min'] ?? '') ?>">
    </div>
    <div>
        <label class="muted" style="display:block; font-size:12px;">최대금액</label>
        <input type="number" name="amount_max" min="0" style="width:110px;" value="<?= esc($filters['amount_max'] ?? '') ?>">
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
