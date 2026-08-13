<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Platform Update</title>
    <link rel="stylesheet" href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets_super/css/update.css') }}?v={{ time() }}">
</head>
<body class="update-body">
    <div class="update-shell">
        <header class="update-header">
            <div class="update-header-icon">
                <i class="bi bi-cloud-arrow-down"></i>
            </div>
            <div>
                <h1 class="update-title">Platform Update</h1>
                <p class="update-subtitle">Run pending migrations for the central database and every active tenant.</p>
            </div>
        </header>

        <section class="update-card" id="updateIntroCard" @if($lockActive) hidden @endif>
            <div class="update-card-body">
                <h2 class="update-card-title"><i class="bi bi-info-circle"></i> Before you continue</h2>
                <ul class="update-tips">
                    <li>Back up the central database (Settings → Backup) before starting.</li>
                    <li>Once started, the platform enters maintenance mode for all visitors and tenants until the run finishes.</li>
                    <li>The page must stay open until all targets complete — do not close or refresh.</li>
                </ul>
                <div class="update-summary">
                    <div class="update-summary-item">
                        <span class="update-summary-label">Targets</span>
                        <span class="update-summary-value" id="summaryTotal">{{ count($targets) }}</span>
                    </div>
                    <div class="update-summary-item">
                        <span class="update-summary-label">Central</span>
                        <span class="update-summary-value">1</span>
                    </div>
                    <div class="update-summary-item">
                        <span class="update-summary-label">Tenants</span>
                        <span class="update-summary-value">{{ max(0, count($targets) - 1) }}</span>
                    </div>
                </div>
                <button type="button" class="update-btn update-btn-primary" id="startBtn">
                    <i class="bi bi-play-fill"></i> Start Update
                </button>
            </div>
        </section>

        <section class="update-card update-card--progress" id="updateProgressCard" @if(!$lockActive) hidden @endif>
            <div class="update-card-body">
                <div class="update-progress-head">
                    <div>
                        <h2 class="update-card-title" id="progressTitle"><i class="bi bi-arrow-repeat spin"></i> Running update…</h2>
                        <p class="update-card-sub" id="progressSub">Maintenance mode is active. Please keep this window open.</p>
                    </div>
                    <div class="update-progress-pct" id="progressPct">0%</div>
                </div>

                <div class="update-progressbar">
                    <div class="update-progressbar-fill" id="progressBar" style="width: 0%"></div>
                </div>

                <div class="update-counters">
                    <span class="update-counter update-counter--ok"><i class="bi bi-check-circle"></i> <span id="counterOk">0</span> done</span>
                    <span class="update-counter update-counter--warn"><i class="bi bi-hourglass-split"></i> <span id="counterRun">0</span> running</span>
                    <span class="update-counter update-counter--fail"><i class="bi bi-exclamation-triangle"></i> <span id="counterFail">0</span> failed</span>
                    <span class="update-counter update-counter--muted"><i class="bi bi-clock"></i> <span id="counterPend">{{ count($targets) }}</span> pending</span>
                </div>

                <ul class="update-list" id="targetList">
                    @foreach($targets as $t)
                    <li class="update-item" data-key="{{ $t['key'] }}" data-type="{{ $t['type'] }}" data-id="{{ $t['id'] }}">
                        <div class="update-item-icon">
                            @if($t['type'] === 'central')
                                <i class="bi bi-hdd-network"></i>
                            @else
                                <i class="bi bi-building"></i>
                            @endif
                        </div>
                        <div class="update-item-body">
                            <div class="update-item-label">{{ $t['label'] }}</div>
                            <div class="update-item-meta">
                                @if($t['type'] === 'central')
                                    <span class="update-chip update-chip--neutral">central</span>
                                @else
                                    <span class="update-chip update-chip--tenant">tenant</span>
                                    <code class="update-item-id">{{ $t['id'] }}</code>
                                @endif
                            </div>
                            <div class="update-item-output" hidden></div>
                        </div>
                        <div class="update-item-status">
                            <span class="update-status update-status--pending">
                                <i class="bi bi-clock"></i> Pending
                            </span>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
        </section>

        <section class="update-card update-card--done" id="updateDoneCard" hidden>
            <div class="update-card-body text-center">
                <div class="update-done-icon"><i class="bi bi-check-lg"></i></div>
                <h2 class="update-card-title" id="doneTitle">Update completed</h2>
                <p class="update-card-sub" id="doneSub">All targets processed successfully. You may now resume normal operations.</p>
                <div class="update-done-actions">
                    <a href="/super" class="update-btn update-btn-primary"><i class="bi bi-house-door"></i> Back to dashboard</a>
                    <button type="button" class="update-btn update-btn-ghost" id="reloadBtn"><i class="bi bi-arrow-clockwise"></i> Re-run</button>
                </div>
            </div>
        </section>

        <footer class="update-footer">
            <span>&copy; {{ date('Y') }} Platform update · Super admin only</span>
        </footer>
    </div>

    <script>
        window.PlatformUpdate = {
            csrfToken: @json(csrf_token()),
            lockActive: @json($lockActive),
            targets: @json($targets),
            endpoints: {
                start:      @json(route('platform.update.start')),
                runCentral: @json(route('platform.update.run-central')),
                runTenant:  @json(route('platform.update.run-tenant', ['tenantId' => '__ID__'])),
                finish:     @json(route('platform.update.finish')),
            },
        };
    </script>
    <script src="{{ asset('assets_super/js/update.js') }}?v={{ time() }}"></script>
</body>
</html>
