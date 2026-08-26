(function () {
  'use strict';
  if (window.__prodexCashRegisterReportUiInstalled) return;
  window.__prodexCashRegisterReportUiInstalled = true;

  function isReport() {
    return window.location.pathname === '/app/reports/cash-registers';
  }

  function ensureStyle() {
    if (document.getElementById('prodex-cash-register-report-style')) return;
    var style = document.createElement('style');
    style.id = 'prodex-cash-register-report-style';
    style.textContent = [
      'body.prodex-cash-register-report-page .main-content{max-width:none!important;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper{border-radius:14px!important;overflow:hidden;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper>.card-body{padding:18px!important;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper .row.align-items-end{display:grid!important;grid-template-columns:repeat(4,minmax(190px,1fr));gap:14px;margin:0!important;align-items:end!important;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper .row.align-items-end>[class*=col-]{max-width:none!important;width:auto!important;flex:none!important;padding:0!important;margin:0!important;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper label{font-size:12px;font-weight:700;color:#475569;margin-bottom:6px!important;}',
      'body.prodex-cash-register-report-page .main-content .card.wrapper .form-control,body.prodex-cash-register-report-page .main-content .card.wrapper .custom-select,body.prodex-cash-register-report-page .main-content .card.wrapper .btn{min-height:42px;border-radius:9px;}',
      'body.prodex-cash-register-report-page .native-report-note{margin-top:14px!important;margin-bottom:14px!important;}',
      'body.prodex-cash-register-report-page .vgt-wrap{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;}',
      'body.prodex-cash-register-report-page .vgt-inner-wrap{box-shadow:none!important;}',
      'body.prodex-cash-register-report-page .vgt-responsive{overflow-x:auto!important;scrollbar-gutter:stable;}',
      'body.prodex-cash-register-report-page table.vgt-table{min-width:1420px!important;margin-top:0!important;}',
      'body.prodex-cash-register-report-page .vgt-global-search{padding:14px 16px!important;background:#fff!important;border-bottom:1px solid #e2e8f0!important;}',
      'body.prodex-cash-register-report-page .vgt-wrap__footer{padding:12px 16px!important;background:#fff!important;border-top:1px solid #e2e8f0!important;}',
      'body.prodex-cash-register-report-page .vgt-table th{white-space:nowrap;font-size:11px!important;}',
      'body.prodex-cash-register-report-page .vgt-table td{white-space:nowrap;vertical-align:middle!important;}',
      '@media(max-width:1400px){body.prodex-cash-register-report-page .main-content .card.wrapper .row.align-items-end{grid-template-columns:repeat(3,minmax(180px,1fr));}}',
      '@media(max-width:980px){body.prodex-cash-register-report-page .main-content .card.wrapper .row.align-items-end{grid-template-columns:repeat(2,minmax(160px,1fr));}}',
      '@media(max-width:640px){body.prodex-cash-register-report-page .main-content .card.wrapper .row.align-items-end{grid-template-columns:1fr;}}'
    ].join('');
    document.head.appendChild(style);
  }

  function apply() {
    ensureStyle();
    document.body.classList.toggle('prodex-cash-register-report-page', isReport());
  }

  apply();
  window.addEventListener('popstate', function(){ setTimeout(apply, 0); });
  document.addEventListener('click', function(){ setTimeout(apply, 60); }, true);
  setInterval(apply, 1000);
})();
