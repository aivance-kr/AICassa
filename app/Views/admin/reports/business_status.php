<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid = $business['id'];
    $months   = $statement['months'];
    $quarters = $statement['quarters'];
    $total    = $statement['total'];
    $money    = static fn (int $n): string => number_format($n);
    // 버킷 → 6개 셀(수입/비용/자산 금액·부가세)
    $cells = static function (array $b) use ($money): string {
        return '<td class="num">' . $money($b['income']) . '</td>'
             . '<td class="num muted">' . $money($b['income_vat']) . '</td>'
             . '<td class="num">' . $money($b['expense']) . '</td>'
             . '<td class="num muted">' . $money($b['expense_vat']) . '</td>'
             . '<td class="num">' . $money($b['asset']) . '</td>'
             . '<td class="num muted">' . $money($b['asset_vat']) . '</td>';
    };
?>
<h1>영업현황표 <span class="muted">— <?= (int) $year ?>년</span></h1>
<p class="page-desc">장부 데이터를 월별·분기별·연간으로 집계한 표와 차트입니다. 수입·비용 추이를 한눈에 확인합니다.</p>

<div class="toolbar">
    <form method="get" style="display:flex; gap:8px; align-items:center;">
        <label class="muted" style="font-size:13px;">귀속연도</label>
        <select name="fiscal_year" onchange="this.form.submit()">
            <?php if ($years === []): ?>
                <option value="<?= (int) $year ?>"><?= (int) $year ?></option>
            <?php endif; ?>
            <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<style>
    table.stmt { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; font-size:13px; }
    table.stmt th, table.stmt td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:center; }
    table.stmt th { background:#f1f5f9; color:var(--muted); font-weight:600; }
    table.stmt td.num { text-align:right; font-variant-numeric:tabular-nums; }
    table.stmt tr.qsum td { background:#f8fafc; font-weight:600; }
    table.stmt tr.total td { background:#ecfdf5; font-weight:700; }
</style>

<div style="overflow-x:auto; margin-bottom:20px;">
<table class="stmt">
    <thead>
        <tr>
            <th rowspan="2">기간</th>
            <th colspan="2">수입</th>
            <th colspan="2">비용</th>
            <th colspan="2">사업용 자산 증감</th>
        </tr>
        <tr>
            <th>금액</th><th>부가세</th><th>금액</th><th>부가세</th><th>금액</th><th>부가세</th>
        </tr>
    </thead>
    <tbody>
        <?php for ($q = 1; $q <= 4; $q++): ?>
            <?php for ($m = ($q - 1) * 3 + 1; $m <= $q * 3; $m++): ?>
                <tr>
                    <td><?= $m ?>월</td>
                    <?= $cells($months[$m]) ?>
                </tr>
            <?php endfor; ?>
            <tr class="qsum">
                <td><?= $q ?>분기 합계</td>
                <?= $cells($quarters[$q]) ?>
            </tr>
        <?php endfor; ?>
        <tr class="total">
            <td>연간 합계</td>
            <?= $cells($total) ?>
        </tr>
    </tbody>
</table>
</div>

<div style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:16px;">
    <canvas id="chart" height="90"></canvas>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const MONTHS = <?= json_encode(array_map(static fn ($m) => $m . '월', range(1, 12))) ?>;
const INCOME = <?= json_encode(array_values(array_map(static fn ($m) => $m['income'], $months))) ?>;
const EXPENSE = <?= json_encode(array_values(array_map(static fn ($m) => $m['expense'], $months))) ?>;
new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: {
        labels: MONTHS,
        datasets: [
            { label: '수입', data: INCOME, backgroundColor: '#2563EB' },
            { label: '비용', data: EXPENSE, backgroundColor: '#93C5FD' },
        ],
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { ticks: { callback: v => Number(v).toLocaleString() } } },
    },
});
</script>
<?= $this->endSection() ?>
