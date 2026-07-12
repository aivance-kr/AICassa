<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'AICassa 간편장부') ?></title>
    <style>
        :root { --primary:#2563EB; --primary-dark:#1D4ED8; --secondary:#3B82F6; --border:#e2e8f0; --muted:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic',sans-serif; color:#1e293b; background:#f8fafc; }
        header { background:var(--primary); color:#fff; padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
        header .brand { font-weight:700; font-size:18px; color:#fff; text-decoration:none; }
        header nav a { color:#dbeafe; text-decoration:none; margin-left:16px; font-size:14px; }
        header nav a:hover { color:#fff; }
        header .user { font-size:13px; color:#dbeafe; }
        header .user a { color:#fff; margin-left:12px; }
        /* 사업장 하위 공통 서브탭 */
        .subnav { background:#fff; border-bottom:1px solid var(--border); padding:0 24px; display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
        .subnav .ctx { font-weight:600; font-size:14px; color:var(--muted); margin-right:12px; padding:12px 0; }
        .subnav a { padding:12px 14px; font-size:14px; color:#334155; text-decoration:none; border-bottom:2px solid transparent; }
        .subnav a:hover { color:var(--primary); }
        .subnav a.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
        main { max-width:1100px; margin:24px auto; padding:0 24px; }
        h1 { font-size:22px; margin:0 0 4px; }
        .page-desc { color:var(--muted); font-size:13px; margin:0 0 18px; }
        .btn { display:inline-block; background:var(--primary); color:#fff; padding:8px 16px; border:none; border-radius:6px; text-decoration:none; font-size:14px; cursor:pointer; }
        .btn:hover { background:var(--primary-dark); }
        .btn.secondary { background:#fff; color:var(--primary); border:1px solid var(--primary); }
        .btn.danger { background:#dc2626; }
        .toolbar { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
        .flash { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .flash.ok { background:#dbeafe; color:#1e40af; }
        .flash.err { background:#fee2e2; color:#991b1b; }
        table.tbl { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
        table.tbl th, table.tbl td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--border); font-size:14px; }
        table.tbl th { background:#f1f5f9; color:var(--muted); font-weight:600; }
        form.card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:24px; max-width:560px; }
        form.card label { display:block; font-size:13px; color:var(--muted); margin:12px 0 4px; }
        form.card input[type=text], form.card input[type=file], form.card input[type=date], form.card input[type=number], form.card select {
            width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; }
        form.card .actions { margin-top:20px; display:flex; gap:8px; }
        .checkbox { display:flex; align-items:center; gap:8px; margin-top:12px; }
        .checkbox input { width:auto; }
        .muted { color:var(--muted); font-size:13px; }
        .actions-cell a { margin-right:10px; text-decoration:none; color:var(--primary); font-size:13px; }
        .actions-cell a.del { color:#dc2626; }
    </style>
</head>
<body>
    <header>
        <a href="/admin/businesses" class="brand">📗 AICassa 간편장부</a>
        <nav>
            <a href="/admin/businesses">사업장 목록</a>
            <a href="/admin/tax-qa">세무 Q&A</a>
        </nav>
        <div class="user">
            <?php $u = $authUser ?? null; ?>
            👤 <?= esc($u?->email ?? $u?->username ?? '사용자') ?>
            <a href="/logout">로그아웃</a>
        </div>
    </header>

    <?php
        // 사업장 하위 화면(단일 $business 컨텍스트)에서는 공통 서브탭을 항상 표시한다.
        $ctxBusiness = (isset($business) && is_array($business) && isset($business['id'])) ? $business : null;
    ?>
    <?php if ($ctxBusiness !== null): ?>
        <?php
            $bid = (int) $ctxBusiness['id'];
            $uri = uri_string();
        ?>
        <div class="subnav">
            <span class="ctx"><?= esc($ctxBusiness['name']) ?></span>
            <a href="/admin/businesses/<?= $bid ?>/ledger" class="<?= str_contains($uri, '/ledger') ? 'active' : '' ?>">장부</a>
            <a href="/admin/businesses/<?= $bid ?>/partners" class="<?= str_contains($uri, '/partners') ? 'active' : '' ?>">거래처</a>
            <a href="/admin/businesses/<?= $bid ?>/assets" class="<?= str_contains($uri, '/assets') ? 'active' : '' ?>">자산대장</a>
            <a href="/admin/businesses/<?= $bid ?>/reports/business-status" class="<?= str_contains($uri, '/reports/business-status') ? 'active' : '' ?>">영업현황표</a>
            <a href="/admin/businesses/<?= $bid ?>/reports/tax-forms" class="<?= str_contains($uri, '/reports/tax-forms') ? 'active' : '' ?>">신고서식</a>
        </div>
    <?php endif; ?>

    <main>
        <?php if (session()->getFlashdata('message')): ?>
            <div class="flash ok"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="flash err"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        <?php $errs = session()->getFlashdata('errors'); ?>
        <?php if (is_array($errs) && $errs !== []): ?>
            <div class="flash err">
                <?php foreach ($errs as $e): ?>
                    <div><?= esc($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
