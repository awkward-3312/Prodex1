<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stocky | Installation Complete</title>
    <link rel="icon" href="/assets_setup/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #1f2937;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            animation: rise 0.6s cubic-bezier(.2,.7,.2,1);
        }
        @keyframes rise {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .card-header {
            padding: 40px 40px 24px;
            text-align: center;
            background: linear-gradient(180deg, #ecfdf5 0%, #ffffff 100%);
            border-bottom: 1px solid #f3f4f6;
        }
        .check-circle {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.5);
            animation: pop 0.5s cubic-bezier(.2,.7,.2,1.4) 0.2s both;
        }
        @keyframes pop {
            0%   { transform: scale(0); }
            100% { transform: scale(1); }
        }
        .check-circle svg {
            width: 44px;
            height: 44px;
            stroke: #ffffff;
            stroke-width: 3.5;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
            color: #111827;
            letter-spacing: -0.02em;
        }
        .subtitle {
            margin: 0;
            font-size: 15px;
            color: #6b7280;
        }
        .card-body { padding: 28px 40px 32px; }
        .section-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #9ca3af;
            margin: 0 0 12px;
        }
        .credential-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .credential-info { min-width: 0; flex: 1; }
        .credential-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }
        .credential-value {
            display: block;
            font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            word-break: break-all;
        }
        .copy-btn {
            flex-shrink: 0;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .copy-btn:hover { border-color: #667eea; color: #667eea; background: #f5f7ff; }
        .copy-btn.copied { color: #059669; border-color: #10b981; background: #ecfdf5; }
        .copy-btn svg { width: 14px; height: 14px; }
        .notice {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            margin: 18px 0 22px;
            font-size: 13px;
            color: #92400e;
            line-height: 1.5;
        }
        .notice svg { flex-shrink: 0; width: 18px; height: 18px; color: #d97706; margin-top: 1px; }
        .login-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 10px 20px -8px rgba(102, 126, 234, 0.6);
        }
        .login-btn:hover { transform: translateY(-1px); box-shadow: 0 15px 25px -8px rgba(102, 126, 234, 0.7); }
        .login-btn svg { width: 16px; height: 16px; }
        .url-line {
            text-align: center;
            margin-top: 14px;
            font-size: 12px;
            color: #9ca3af;
        }
        .url-line a { color: #667eea; text-decoration: none; font-weight: 500; }
        .url-line a:hover { text-decoration: underline; }
        @media (max-width: 520px) {
            .card-header { padding: 32px 24px 20px; }
            .card-body   { padding: 24px 24px 28px; }
            h1 { font-size: 22px; }
            .credential-box { flex-direction: column; align-items: stretch; }
            .copy-btn { justify-content: center; }
        }
    </style>
</head>
<body>
<main class="card" role="main">
    <header class="card-header">
        <div class="check-circle" aria-hidden="true">
            <svg viewBox="0 0 24 24"><polyline points="4 12.5 10 18.5 20 6.5"></polyline></svg>
        </div>
        <h1>Installation Successful</h1>
        <p class="subtitle">Stocky SaaS is ready. Use the credentials below to sign in as super admin.</p>
    </header>

    <section class="card-body">
        <p class="section-title">Super Admin Access</p>

        <div class="credential-box">
            <div class="credential-info">
                <span class="credential-label">Login URL</span>
                <span class="credential-value" id="val-url">{{ $superAdminLoginUrl }}</span>
            </div>
            <button type="button" class="copy-btn" data-copy-target="val-url" aria-label="Copy login URL">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span>Copy</span>
            </button>
        </div>

        <div class="credential-box">
            <div class="credential-info">
                <span class="credential-label">Email</span>
                <span class="credential-value" id="val-email">{{ $superAdminEmail }}</span>
            </div>
            <button type="button" class="copy-btn" data-copy-target="val-email" aria-label="Copy email">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span>Copy</span>
            </button>
        </div>

        <div class="credential-box">
            <div class="credential-info">
                <span class="credential-label">Password</span>
                <span class="credential-value" id="val-password">{{ $superAdminPassword }}</span>
            </div>
            <button type="button" class="copy-btn" data-copy-target="val-password" aria-label="Copy password">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span>Copy</span>
            </button>
        </div>

        <div class="notice" role="note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
            <span>For security, sign in and change the super admin password right after your first login.</span>
        </div>

        <a href="{{ $superAdminLoginUrl }}" class="login-btn">
            <span>Go to Super Admin Login</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>

        <p class="url-line">Or go back to <a href="/">the homepage</a>.</p>
    </section>
</main>

<script>
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-copy-target');
            var el = document.getElementById(targetId);
            if (!el) return;
            var text = el.textContent.trim();
            var done = function () {
                var label = btn.querySelector('span');
                var original = label.textContent;
                btn.classList.add('copied');
                label.textContent = 'Copied';
                setTimeout(function () {
                    btn.classList.remove('copied');
                    label.textContent = original;
                }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text, done);
                });
            } else {
                fallbackCopy(text, done);
            }
        });
    });
    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        cb();
    }
</script>
</body>
</html>
