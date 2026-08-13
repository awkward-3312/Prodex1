document.addEventListener('DOMContentLoaded', function() {
    var data = window.GeneralSettingsData || {};

    // ── Tab switching ─────────────────────────────────
    var tabs   = Array.prototype.slice.call(document.querySelectorAll('.settings-nav-item[data-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.settings-panel[data-panel]'));
    var titleEl = document.getElementById('settingsActionTitle');
    var subEl   = document.getElementById('settingsActionSub');

    function activateTab(name, opts) {
        opts = opts || {};
        var matched = false;
        tabs.forEach(function(t) {
            var active = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) matched = true;
        });
        panels.forEach(function(p) {
            var active = p.getAttribute('data-panel') === name;
            p.classList.toggle('is-active', active);
            if (active) {
                if (titleEl && p.dataset.title) titleEl.textContent = p.dataset.title;
                if (subEl   && p.dataset.desc)  subEl.textContent  = p.dataset.desc;
            }
        });
        if (!matched) return;
        if (opts.updateHash !== false) {
            history.replaceState(null, '', '#tab=' + name);
        }
    }

    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            activateTab(this.getAttribute('data-tab'));
        });
        t.addEventListener('keydown', function(e) {
            var idx = tabs.indexOf(this);
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                e.preventDefault();
                tabs[(idx + 1) % tabs.length].focus();
                tabs[(idx + 1) % tabs.length].click();
            } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                e.preventDefault();
                tabs[(idx - 1 + tabs.length) % tabs.length].focus();
                tabs[(idx - 1 + tabs.length) % tabs.length].click();
            }
        });
    });

    // Determine starting tab: URL hash → default (general)
    var initial = null;
    var hashMatch = (location.hash || '').match(/tab=([\w-]+)/);
    if (hashMatch) initial = hashMatch[1];
    // If validation errors exist, jump to the first panel containing an .is-invalid input
    var invalidEl = document.querySelector('.settings-panel .is-invalid');
    if (invalidEl) {
        var p = invalidEl.closest('.settings-panel');
        if (p && p.dataset.panel) initial = p.dataset.panel;
    }
    if (initial && tabs.some(function(t) { return t.getAttribute('data-tab') === initial; })) {
        activateTab(initial, { updateHash: false });
    }

    // ── Logo upload ───────────────────────────────────
    var logoInput = document.getElementById('logoInput');
    var logoPreview = document.getElementById('logoPreview');
    var logoPreviewImg = document.getElementById('logoPreviewImg');
    var logoPlaceholder = document.getElementById('logoPlaceholder');

    if (logoPlaceholder && logoInput) {
        logoPlaceholder.addEventListener('click', function() { logoInput.click(); });
    }

    if (logoInput) {
        logoInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    if (logoPreviewImg) logoPreviewImg.src = e.target.result;
                    if (logoPreview)    logoPreview.style.display = '';
                    if (logoPlaceholder) logoPlaceholder.style.display = 'none';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    var removeLogoBtn = document.getElementById('removeLogoBtn');
    if (removeLogoBtn) {
        removeLogoBtn.addEventListener('click', function() {
            if (confirm(data.removeLogoConfirm)) {
                document.getElementById('removeLogoForm').submit();
            }
        });
    }

    // ── Favicon upload ────────────────────────────────
    var faviconInput = document.getElementById('faviconInput');
    var faviconPreview = document.getElementById('faviconPreview');
    var faviconPreviewImg = document.getElementById('faviconPreviewImg');
    var faviconPlaceholder = document.getElementById('faviconPlaceholder');

    if (faviconPlaceholder && faviconInput) {
        faviconPlaceholder.addEventListener('click', function() { faviconInput.click(); });
    }

    if (faviconInput) {
        faviconInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    if (faviconPreviewImg) faviconPreviewImg.src = e.target.result;
                    if (faviconPreview)    faviconPreview.style.display = '';
                    if (faviconPlaceholder) faviconPlaceholder.style.display = 'none';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    var removeFaviconBtn = document.getElementById('removeFaviconBtn');
    if (removeFaviconBtn) {
        removeFaviconBtn.addEventListener('click', function() {
            if (confirm(data.removeFaviconConfirm)) {
                document.getElementById('removeFaviconForm').submit();
            }
        });
    }

    // ── Tenant logo / favicon upload (defaults for new tenants) ──
    function wireUpload(inputId, previewId, previewImgId, placeholderId, removeBtnId, removeFormId, removeConfirmKey) {
        var input       = document.getElementById(inputId);
        var preview     = document.getElementById(previewId);
        var previewImg  = document.getElementById(previewImgId);
        var placeholder = document.getElementById(placeholderId);
        var removeBtn   = document.getElementById(removeBtnId);

        if (placeholder && input) {
            placeholder.addEventListener('click', function() { input.click(); });
        }
        if (input) {
            input.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        if (previewImg)  previewImg.src = e.target.result;
                        if (preview)     preview.style.display = '';
                        if (placeholder) placeholder.style.display = 'none';
                    };
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                if (confirm(data[removeConfirmKey])) {
                    var f = document.getElementById(removeFormId);
                    if (f) f.submit();
                }
            });
        }
    }

    wireUpload('tenantLogoInput', 'tenantLogoPreview', 'tenantLogoPreviewImg', 'tenantLogoPlaceholder',
               'removeTenantLogoBtn', 'removeTenantLogoForm', 'removeTenantLogoConfirm');
    wireUpload('tenantFaviconInput', 'tenantFaviconPreview', 'tenantFaviconPreviewImg', 'tenantFaviconPlaceholder',
               'removeTenantFaviconBtn', 'removeTenantFaviconForm', 'removeTenantFaviconConfirm');

    // ── Hosting mode toggle ──────────────────────────
    document.querySelectorAll('input[name="hosting_mode"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.hosting-mode-option').forEach(function(opt) {
                opt.classList.remove('active');
            });
            this.closest('.hosting-mode-option').classList.add('active');
        });
    });

    // ── Clear cache (submits its own form) ───────────
    var clearCacheBtn = document.getElementById('clearCacheBtn');
    if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', function() {
            document.getElementById('clearCacheForm').submit();
        });
    }

    // ── Backup (central DB) ──────────────────────────
    var backupBody = document.getElementById('backupTableBody');
    var createBackupBtn = document.getElementById('createBackupBtn');

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function renderBackups(list) {
        if (!backupBody) return;
        if (!list || !list.length) {
            backupBody.innerHTML =
                '<tr class="backup-empty-row"><td colspan="4" class="text-center text-muted py-4">' +
                '<i class="bi bi-inbox d-block fs-xl mb-2"></i>' + escHtml(data.backupEmpty || 'No backups yet.') +
                '</td></tr>';
            return;
        }
        var rows = list.map(function(b) {
            var name = escHtml(b.name);
            var dlUrl = (data.routes && data.routes.backupDownloadTpl)
                ? data.routes.backupDownloadTpl.replace('__FILENAME__', encodeURIComponent(b.name))
                : '#';
            return '<tr data-name="' + name + '">' +
                '<td><code class="backup-file-name">' + name + '</code></td>' +
                '<td>' + escHtml(b.size) + '</td>' +
                '<td>' + escHtml(b.date) + '</td>' +
                '<td class="text-end">' +
                    '<a href="' + dlUrl + '" class="btn btn-sm btn-outline" title="' + escHtml(data.backupDownload || 'Download') + '">' +
                        '<i class="bi bi-download"></i>' +
                    '</a> ' +
                    '<button type="button" class="btn btn-sm btn-outline-danger js-delete-backup" data-name="' + name + '" title="' + escHtml(data.backupDelete || 'Delete') + '">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }).join('');
        backupBody.innerHTML = rows;
    }

    function setCreating(isLoading) {
        if (!createBackupBtn) return;
        var label = createBackupBtn.querySelector('.btn-label');
        if (isLoading) {
            createBackupBtn.disabled = true;
            createBackupBtn.setAttribute('aria-busy', 'true');
            if (label) label.textContent = data.backupCreating || 'Creating…';
        } else {
            createBackupBtn.disabled = false;
            createBackupBtn.removeAttribute('aria-busy');
            if (label) label.textContent = createBackupBtn.getAttribute('data-label-default') || label.textContent;
        }
    }

    function hasSwal() { return typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function'; }

    function swalError(message) {
        if (hasSwal()) {
            Swal.fire({
                icon: 'error',
                title: data.backupFailed || 'Error',
                text: message || '',
                confirmButtonColor: '#ef4444',
            });
        } else {
            alert(message || 'Error');
        }
    }

    function swalToast(message) {
        if (hasSwal()) {
            Swal.fire({
                icon: 'success',
                title: message || '',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: true,
            });
        }
    }

    if (createBackupBtn) {
        var defLabel = createBackupBtn.querySelector('.btn-label');
        if (defLabel) createBackupBtn.setAttribute('data-label-default', defLabel.textContent);

        createBackupBtn.addEventListener('click', function() {
            if (!data.routes || !data.routes.backupStore) return;
            setCreating(true);
            fetch(data.routes.backupStore, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': data.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
            .then(function(r) { return r.json().then(function(j){ j.__ok = r.ok; return j; }); })
            .then(function(res) {
                if (!res.__ok || !res.success) {
                    swalError((res && (res.error || res.message)) || (data.backupFailed || 'Backup failed.'));
                } else {
                    renderBackups(res.backups || []);
                    swalToast(res.message || data.backupCreated || 'Backup created.');
                }
            })
            .catch(function(err) { swalError(err && err.message ? err.message : (data.backupFailed || 'Backup failed.')); })
            .finally(function() { setCreating(false); });
        });
    }

    function performBackupDelete(name, btn) {
        if (!data.routes || !data.routes.backupDestroyTpl) return;
        var url = data.routes.backupDestroyTpl.replace('__FILENAME__', encodeURIComponent(name));
        btn.disabled = true;
        fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': data.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
        .then(function(r) { return r.json().then(function(j){ j.__ok = r.ok; return j; }); })
        .then(function(res) {
            if (!res.__ok || !res.success) {
                swalError((res && (res.error || res.message)) || 'Delete failed.');
                btn.disabled = false;
            } else {
                renderBackups(res.backups || []);
                swalToast(res.message || data.backupDeleted || 'Backup deleted.');
            }
        })
        .catch(function(err) {
            swalError(err && err.message ? err.message : 'Delete failed.');
            btn.disabled = false;
        });
    }

    if (backupBody) {
        backupBody.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-delete-backup');
            if (!btn) return;
            var name = btn.getAttribute('data-name');
            if (!name) return;

            if (hasSwal()) {
                Swal.fire({
                    title: data.backupDeleteTitle || (data.backupDelete || 'Delete backup?'),
                    text: (data.backupDeleteConfirm || 'Delete this backup?') + (name ? '\n\n' + name : ''),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: data.backupYesDelete || (data.backupDelete || 'Yes, delete'),
                    confirmButtonColor: '#ef4444',
                    cancelButtonText: data.cancelText || 'Cancel',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true,
                }).then(function(result) {
                    if (result.isConfirmed) performBackupDelete(name, btn);
                });
            } else {
                if (confirm(data.backupDeleteConfirm || 'Delete this backup?')) {
                    performBackupDelete(name, btn);
                }
            }
        });
    }
});
