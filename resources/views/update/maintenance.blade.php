<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Actualizando el sistema | PRODEX</title>
    <link rel="stylesheet" href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}">
    <style>
        :root { --bg:#0f172a;--panel:#111827;--border:rgba(255,255,255,.08);--muted:rgba(255,255,255,.62);--text:#f8fafc;--accent:#6366f1; }
        *{box-sizing:border-box} html,body{height:100%} body{margin:0;background:radial-gradient(1100px 560px at 15% 0%,rgba(99,102,241,.22),transparent 60%),radial-gradient(900px 480px at 85% 100%,rgba(14,165,233,.16),transparent 60%),var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;align-items:center;justify-content:center;padding:1.5rem}.m-shell{max-width:520px;width:100%;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01));border:1px solid var(--border);border-radius:20px;padding:2.2rem 2rem;text-align:center;backdrop-filter:blur(10px);box-shadow:0 20px 60px rgba(0,0,0,.35)}.m-icon{width:72px;height:72px;border-radius:50%;margin:0 auto 1.1rem;display:grid;place-items:center;background:linear-gradient(135deg,rgba(99,102,241,.25),rgba(99,102,241,.05));border:1px solid rgba(99,102,241,.4);color:#a5b4fc;font-size:1.9rem;position:relative}.m-icon::after{content:'';position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(99,102,241,.35);border-top-color:transparent;animation:spin 1.6s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.m-title{font-size:1.5rem;font-weight:700;margin:0 0 .55rem;letter-spacing:-.01em}.m-sub{color:var(--muted);font-size:.95rem;line-height:1.55;margin:0 0 1.6rem}.m-dots{display:inline-flex;gap:6px;margin-bottom:1.3rem}.m-dots span{width:8px;height:8px;border-radius:50%;background:rgba(165,180,252,.9);animation:pulse 1.3s infinite ease-in-out}.m-dots span:nth-child(2){animation-delay:.2s}.m-dots span:nth-child(3){animation-delay:.4s}@keyframes pulse{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}.m-meta{display:flex;justify-content:center;gap:1rem;font-size:.8rem;color:var(--muted);border-top:1px solid var(--border);padding-top:1rem;margin-top:.4rem;flex-wrap:wrap}.m-meta i{margin-right:.3rem;color:#a5b4fc}
    </style>
</head>
<body>
    <main class="m-shell" role="status" aria-live="polite">
        <div class="m-icon"><i class="bi bi-tools"></i></div>
        <h1 class="m-title">Actualizando el sistema</h1>
        <p class="m-sub">Estamos aplicando una nueva versión de la aplicación. El servicio se reanudará automáticamente en unos minutos. Mantén esta pestaña abierta y vuelve a intentarlo en breve.</p>
        <div class="m-dots" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="m-meta">
            <span><i class="bi bi-shield-lock"></i> Modo de mantenimiento</span>
            @if(!empty($info['started_at']))
                <span><i class="bi bi-clock-history"></i> Iniciado {{ $info['started_at'] }}</span>
            @endif
        </div>
    </main>
    <script>setTimeout(function(){window.location.reload();},20000);</script>
</body>
</html>
