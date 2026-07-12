<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인 — AICassa 간편장부</title>
    <style>
        :root { --primary:#2563EB; --primary-dark:#1D4ED8; --border:#e2e8f0; --muted:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,'Malgun Gothic',sans-serif; color:#1e293b; background:#f8fafc; }
        header { background:var(--primary); color:#fff; padding:14px 24px; }
        header .brand { font-weight:700; font-size:18px; }
        .wrap { max-width:420px; margin:64px auto; padding:0 20px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:32px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .card h1 { font-size:20px; margin:0 0 4px; }
        .card .sub { color:var(--muted); font-size:13px; margin:0 0 20px; }
        label { display:block; font-size:13px; color:var(--muted); margin:14px 0 4px; }
        input[type=email], input[type=password] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px; }
        .checkbox { display:flex; align-items:center; gap:8px; margin-top:14px; font-size:14px; }
        .btn { width:100%; margin-top:20px; background:var(--primary); color:#fff; padding:11px; border:none; border-radius:8px; font-size:15px; cursor:pointer; }
        .btn:hover { background:var(--primary-dark); }
        .alert { padding:11px 14px; border-radius:8px; font-size:14px; margin-bottom:16px; }
        .alert.err { background:#fee2e2; color:#991b1b; }
        .alert.ok { background:#dbeafe; color:#1e40af; }
        .links { text-align:center; margin-top:16px; font-size:13px; color:var(--muted); }
        .links a { color:var(--primary); text-decoration:none; }
    </style>
</head>
<body>
    <header>
        <div class="brand">📗 AICassa 간편장부</div>
    </header>

    <div class="wrap">
        <div class="card">
            <h1>로그인</h1>
            <p class="sub">간편장부 웹 ERP에 오신 것을 환영합니다.</p>

            <?php if (session('error') !== null): ?>
                <div class="alert err"><?= esc(session('error')) ?></div>
            <?php elseif (session('errors') !== null): ?>
                <div class="alert err">
                    <?php if (is_array(session('errors'))): ?>
                        <?php foreach (session('errors') as $error): ?>
                            <div><?= esc($error) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?= esc(session('errors')) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (session('message') !== null): ?>
                <div class="alert ok"><?= esc(session('message')) ?></div>
            <?php endif; ?>

            <form action="<?= url_to('login') ?>" method="post">
                <?= csrf_field() ?>

                <label for="email">이메일</label>
                <input type="email" id="email" name="email" inputmode="email" autocomplete="email"
                    value="<?= old('email') ?>" placeholder="you@example.com" required>

                <label for="password">비밀번호</label>
                <input type="password" id="password" name="password" autocomplete="current-password"
                    placeholder="비밀번호" required>

                <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="remember" <?= old('remember') ? 'checked' : '' ?>>
                        로그인 상태 유지
                    </label>
                <?php endif; ?>

                <button type="submit" class="btn">로그인</button>

                <?php if (setting('Auth.allowRegistration')): ?>
                    <p class="links">계정이 없으신가요? <a href="<?= url_to('register') ?>">회원가입</a></p>
                <?php endif; ?>
            </form>
        </div>
    </div>
</body>
</html>
