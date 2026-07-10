<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'AICassa 운영자') ?></title>
    <style>
        :root { --primary:#7c3aed; --primary-dark:#6d28d9; --border:#e2e8f0; --muted:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic',sans-serif; color:#1e293b; background:#f8fafc; }
        header { background:var(--primary); color:#fff; padding:12px 24px; display:flex; align-items:center; justify-content:space-between; }
        header .brand { font-weight:700; font-size:18px; color:#fff; text-decoration:none; }
        header .user { font-size:13px; color:#ede9fe; }
        header .user a { color:#fff; margin-left:12px; }
        main { max-width:1000px; margin:24px auto; padding:0 24px; }
        h1 { font-size:22px; margin:0 0 4px; }
        .page-desc { color:var(--muted); font-size:13px; margin:0 0 18px; }
        .btn { display:inline-block; background:var(--primary); color:#fff; padding:8px 16px; border:none; border-radius:6px; text-decoration:none; font-size:14px; cursor:pointer; }
        .btn:hover { background:var(--primary-dark); }
        .btn.secondary { background:#fff; color:var(--primary); border:1px solid var(--primary); }
        .toolbar { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
        .flash { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .flash.ok { background:#ede9fe; color:#5b21b6; }
        .flash.err { background:#fee2e2; color:#991b1b; }
        table.tbl { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
        table.tbl th, table.tbl td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--border); font-size:14px; vertical-align:top; }
        table.tbl th { background:#f1f5f9; color:var(--muted); font-weight:600; }
        table.tbl td a { color:var(--primary); text-decoration:none; margin-right:10px; }
        .cat { display:inline-block; font-size:12px; color:#5b21b6; background:#f5f3ff; border-radius:4px; padding:2px 8px; }
        form.card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:24px; }
        form.card label { display:block; font-size:13px; color:var(--muted); margin:4px 0; }
        input[type=text], input[type=number], input[type=password], textarea {
            width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; font-family:inherit; }
        textarea { min-height:120px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
        .muted { color:var(--muted); font-size:13px; }
        .field { margin-bottom:18px; }
        .field .desc { color:var(--muted); font-size:12px; margin:4px 0 0; }
        .unit { color:var(--muted); font-size:13px; margin-left:6px; }
        .actions { margin-top:20px; display:flex; gap:8px; }
    </style>
</head>
<body>
    <header>
        <a href="/operator/tax-parameters" class="brand">🛠️ AICassa 운영자</a>
        <div class="user">
            👤 <?= esc($operatorId ?? '운영자') ?>
            <a href="/operator/logout">로그아웃</a>
        </div>
    </header>

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
</body>
</html>
