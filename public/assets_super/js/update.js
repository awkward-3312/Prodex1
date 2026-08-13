(function () {
    'use strict';

    var data = window.PlatformUpdate || {};
    var introCard    = document.getElementById('updateIntroCard');
    var progressCard = document.getElementById('updateProgressCard');
    var doneCard     = document.getElementById('updateDoneCard');
    var startBtn     = document.getElementById('startBtn');
    var reloadBtn    = document.getElementById('reloadBtn');

    var progressBar = document.getElementById('progressBar');
    var progressPct = document.getElementById('progressPct');
    var progressTitle = document.getElementById('progressTitle');
    var progressSub   = document.getElementById('progressSub');
    var counterOk   = document.getElementById('counterOk');
    var counterRun  = document.getElementById('counterRun');
    var counterFail = document.getElementById('counterFail');
    var counterPend = document.getElementById('counterPend');

    var targets = Array.isArray(data.targets) ? data.targets.slice() : [];
    var stats   = { ok: 0, fail: 0, skip: 0, running: 0, pending: targets.length };

    function postJson(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': data.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
        }).then(function (res) {
            return res.json().then(function (body) {
                return { status: res.status, body: body || {} };
            }).catch(function () {
                return { status: res.status, body: {} };
            });
        });
    }

    function findItem(key) {
        return document.querySelector('.update-item[data-key="' + CSS.escape(key) + '"]');
    }

    function setItemStatus(el, state, text, output) {
        if (!el) return;
        el.classList.remove('is-running', 'is-ok', 'is-failed', 'is-skip');
        var iconHtml, chipClass;
        if (state === 'running') {
            el.classList.add('is-running');
            iconHtml = '<i class="bi bi-arrow-repeat spin"></i> ' + text;
            chipClass = 'update-status--running';
        } else if (state === 'ok') {
            el.classList.add('is-ok');
            iconHtml = '<i class="bi bi-check-lg"></i> ' + text;
            chipClass = 'update-status--ok';
        } else if (state === 'skip') {
            el.classList.add('is-skip');
            iconHtml = '<i class="bi bi-slash-circle"></i> ' + text;
            chipClass = 'update-status--skip';
        } else if (state === 'failed') {
            el.classList.add('is-failed');
            iconHtml = '<i class="bi bi-x-circle"></i> ' + text;
            chipClass = 'update-status--fail';
        } else {
            iconHtml = '<i class="bi bi-clock"></i> ' + text;
            chipClass = 'update-status--pending';
        }

        var badge = el.querySelector('.update-item-status');
        if (badge) {
            badge.innerHTML = '<span class="update-status ' + chipClass + '">' + iconHtml + '</span>';
        }

        if (output !== undefined && output !== null && output !== '') {
            var out = el.querySelector('.update-item-output');
            if (out) {
                out.hidden = false;
                out.textContent = String(output);
            }
        }
    }

    function renderCounters() {
        counterOk.textContent   = stats.ok;
        counterRun.textContent  = stats.running;
        counterFail.textContent = stats.fail;
        counterPend.textContent = stats.pending;

        var done = stats.ok + stats.fail + stats.skip;
        var total = targets.length || 1;
        var pct = Math.round((done / total) * 100);
        progressBar.style.width = pct + '%';
        progressPct.textContent = pct + '%';
    }

    function onDone() {
        if (progressTitle) {
            if (stats.fail > 0) {
                progressTitle.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Completed with warnings';
            } else {
                progressTitle.innerHTML = '<i class="bi bi-check-circle-fill"></i> Update complete';
            }
        }

        postJson(data.endpoints.finish).finally(function () {
            progressCard.hidden = true;
            doneCard.hidden = false;
            var doneTitle = document.getElementById('doneTitle');
            var doneSub   = document.getElementById('doneSub');
            if (stats.fail > 0) {
                doneCard.classList.add('is-warning');
                if (doneTitle) doneTitle.textContent = 'Update finished with ' + stats.fail + ' error(s)';
                if (doneSub) doneSub.textContent = 'Some tenants failed — check the per-tenant logs above and storage/logs for details.';
            } else if (stats.skip > 0) {
                if (doneTitle) doneTitle.textContent = 'Update complete';
                if (doneSub) doneSub.textContent = stats.skip + ' tenant(s) were skipped (pending/failed state). All eligible targets updated successfully.';
            }
        });
    }

    function runOne(index) {
        if (index >= targets.length) {
            onDone();
            return;
        }

        var target = targets[index];
        var el = findItem(target.key);
        stats.running = 1;
        stats.pending = Math.max(0, stats.pending - 1);
        setItemStatus(el, 'running', 'Running…');
        renderCounters();

        var url = target.type === 'central'
            ? data.endpoints.runCentral
            : data.endpoints.runTenant.replace('__ID__', encodeURIComponent(target.id));

        postJson(url).then(function (resp) {
            stats.running = 0;
            var body = resp.body || {};
            if (body.ok) {
                stats.ok += 1;
                setItemStatus(el, 'ok', 'Done', body.output);
            } else if (body.skipped) {
                stats.skip += 1;
                setItemStatus(el, 'skip', 'Skipped', body.error);
            } else {
                stats.fail += 1;
                setItemStatus(el, 'failed', 'Failed', body.error || ('HTTP ' + resp.status));
            }
        }).catch(function (err) {
            stats.running = 0;
            stats.fail += 1;
            setItemStatus(el, 'failed', 'Failed', (err && err.message) || 'Network error');
        }).finally(function () {
            renderCounters();
            // Small breather between targets to keep UI responsive
            setTimeout(function () { runOne(index + 1); }, 120);
        });
    }

    function beginRun() {
        if (!targets.length) { onDone(); return; }
        introCard.hidden = true;
        progressCard.hidden = false;
        doneCard.hidden = true;
        stats = { ok: 0, fail: 0, skip: 0, running: 0, pending: targets.length };
        // Reset any prior per-item visuals
        targets.forEach(function (t) {
            var el = findItem(t.key);
            setItemStatus(el, 'pending', 'Pending');
            var out = el && el.querySelector('.update-item-output');
            if (out) { out.hidden = true; out.textContent = ''; }
        });
        renderCounters();
        runOne(0);
    }

    if (startBtn) {
        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;
            startBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting…';
            postJson(data.endpoints.start).then(function (resp) {
                if (resp.body && Array.isArray(resp.body.targets)) {
                    targets = resp.body.targets;
                }
                beginRun();
            }).catch(function () {
                startBtn.disabled = false;
                startBtn.innerHTML = '<i class="bi bi-play-fill"></i> Start Update';
                alert('Failed to start update. Please try again.');
            });
        });
    }

    if (reloadBtn) {
        reloadBtn.addEventListener('click', function () {
            window.location.reload();
        });
    }

    // If we landed on the page with maintenance already engaged (e.g. reloaded mid-run),
    // show the progress card so the admin can re-run remaining targets.
    if (data.lockActive) {
        if (introCard) introCard.hidden = true;
        if (progressCard) progressCard.hidden = false;
        if (progressSub) {
            progressSub.textContent = 'Previous run detected. Press Re-run to retry all targets.';
        }
        // Add a re-run button into the progress card
        if (!document.getElementById('rerunInlineBtn')) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'rerunInlineBtn';
            btn.className = 'update-btn update-btn-primary';
            btn.innerHTML = '<i class="bi bi-play-fill"></i> Re-run update';
            btn.style.marginTop = '0.5rem';
            btn.addEventListener('click', beginRun);
            progressCard.querySelector('.update-card-body').appendChild(btn);
        }
    }

    // Warn on leaving the page during an active run.
    window.addEventListener('beforeunload', function (e) {
        if (stats.running > 0) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });
})();
