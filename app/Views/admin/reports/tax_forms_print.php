<?php
    $money       = static fn (int $n): string => number_format($n);
    $methodLabel = static fn (?string $v): string => match ($v) {
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
        default             => '-',
    };
?><!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <title>종합소득세 신고서식 — <?= esc($business['name']) ?> <?= (int) $year ?>년</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Malgun Gothic', '맑은 고딕', sans-serif; color: #111; margin: 24px; font-size: 13px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 24px 0 8px; border-bottom: 2px solid #333; padding-bottom: 4px; }
        .meta { color: #555; font-size: 12px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #999; padding: 6px 8px; }
        th { background: #f0f0f0; text-align: left; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.total th, tr.total td { background: #eaeaea; font-weight: 700; }
        .toolbar { margin-bottom: 16px; }
        .btn { display: inline-block; padding: 8px 14px; background: #0F6E56; color: #fff; text-decoration: none; border-radius: 6px; border: 0; cursor: pointer; font-size: 13px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none; }
            h2 { page-break-after: avoid; }
            table { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">인쇄 / PDF로 저장</button>
    </div>

    <h1>종합소득세 신고서식</h1>
    <div class="meta">
        상호: <?= esc($business['name']) ?>
        <?php if (($business['biz_reg_no'] ?? '') !== ''): ?> · 사업자등록번호: <?= esc($business['biz_reg_no']) ?><?php endif; ?>
        · 귀속연도: <?= (int) $year ?>년
        · 서식버전: <?= esc($formVersion['version']) ?>
    </div>

    <!-- ① 소득금액계산서 -->
    <h2>① 간편장부 소득금액계산서</h2>
    <table>
        <tr><th style="width:60%;">총수입금액</th><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
        <tr><th>필요경비</th><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
        <tr class="total"><th>소득금액 (총수입금액 − 필요경비)</th><td class="num"><?= $money($statement['income_amount']) ?></td></tr>
    </table>

    <!-- ② 총수입금액 및 필요경비명세서 -->
    <h2>② 총수입금액 및 필요경비명세서</h2>
    <div class="grid2">
        <div>
            <table>
                <thead><tr><th>수입 계정</th><th class="num">금액</th></tr></thead>
                <tbody>
                <?php foreach ($statement['revenue_by_account'] as $name => $amt): ?>
                    <tr><td><?= esc($name) ?></td><td class="num"><?= $money($amt) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td>계</td><td class="num"><?= $money($statement['total_revenue']) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div>
            <table>
                <thead><tr><th>필요경비 항목</th><th class="num">금액</th></tr></thead>
                <tbody>
                <tr><td>매출원가(상품)</td><td class="num"><?= $money($statement['goods_cogs']) ?></td></tr>
                <?php if ($statement['materials_cost'] !== 0): ?>
                    <tr><td>재료비</td><td class="num"><?= $money($statement['materials_cost']) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($statement['expense_by_account'] as $name => $amt): ?>
                    <?php if ($name === '상품매입' || $name === '재료매입') {
                        continue;
                    } ?>
                    <tr><td><?= esc($name) ?></td><td class="num"><?= $money($amt) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td>계</td><td class="num"><?= $money($statement['necessary_expense']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ③ 감가상각비 조정명세서 -->
    <h2>③ 감가상각비 조정명세서</h2>
    <?php if ($depreciation === []): ?>
        <p>해당 연도 감가상각 대상 자산이 없습니다.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>자산명</th><th>상각방법</th><th class="num">취득금액</th><th class="num">당기 감가상각비</th><th class="num">감가상각누계액</th><th class="num">기말 장부가액</th></tr>
            </thead>
            <tbody>
            <?php foreach ($depreciation as $d): ?>
                <tr>
                    <td><?= esc($d['name']) ?></td>
                    <td><?= esc($methodLabel($d['method'])) ?></td>
                    <td class="num"><?= $money($d['acquisition_cost']) ?></td>
                    <td class="num"><?= $money($d['depreciation']) ?></td>
                    <td class="num"><?= $money($d['accumulated']) ?></td>
                    <td class="num"><?= $money($d['book_value']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
