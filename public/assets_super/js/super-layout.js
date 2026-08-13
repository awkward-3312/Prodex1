(function() {
    var sidebar = document.getElementById('sidebar');
    var toggleBtn = document.getElementById('sidebarToggle');
    var overlay = document.getElementById('sidebarOverlay');
    var mainContent = document.querySelector('.main-content');
    var STORAGE_KEY = 'super_sidebar_collapsed';

    var sidebarNav = sidebar.querySelector('.sidebar-nav');

    function isMobile() {
        return window.innerWidth < 992;
    }

    function preventBgScroll(e) {
        if (sidebarNav && sidebarNav.contains(e.target)) {
            return;
        }
        e.preventDefault();
    }

    function openMobileSidebar() {
        document.documentElement.classList.add('sidebar-open');
        document.body.classList.add('sidebar-open');
        sidebar.classList.add('open');
        overlay.classList.add('show');
        mainContent.classList.add('blurred');
        document.addEventListener('touchmove', preventBgScroll, { passive: false });
    }

    function closeMobileSidebar() {
        document.removeEventListener('touchmove', preventBgScroll);
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        mainContent.classList.remove('blurred');
        document.documentElement.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-open');
    }

    if (!isMobile() && localStorage.getItem(STORAGE_KEY) === '1') {
        sidebar.classList.add('collapsed');
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (isMobile()) {
                if (sidebar.classList.contains('open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem(STORAGE_KEY, sidebar.classList.contains('collapsed') ? '1' : '0');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function() {
            closeMobileSidebar();
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (isMobile()) {
                sidebar.classList.remove('collapsed');
            } else {
                closeMobileSidebar();
                if (localStorage.getItem(STORAGE_KEY) === '1') {
                    sidebar.classList.add('collapsed');
                }
            }
        }, 150);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isMobile() && sidebar.classList.contains('open')) {
            closeMobileSidebar();
        }
    });
})();

function swalConfirm(e, opts) {
    e.preventDefault();
    var form = e.target.closest('form') || e.target;
    var config = Object.assign({
        title: 'Are you sure?',
        text: '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
    }, opts);
    Swal.fire(config).then(function(result) {
        if (result.isConfirmed) {
            form.submit();
        }
    });
    return false;
}

document.getElementById('themeToggle').addEventListener('click', function() {
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('super_theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
});

window.addEventListener('load', function() {
    var loader = document.getElementById('pageLoader');
    if (loader) loader.classList.add('hide');
});
