(function() {
    var sidebar = document.getElementById('sidebar');
    var toggleBtn = document.getElementById('sidebarToggle');
    var overlay = document.getElementById('sidebarOverlay');
    var mainContent = document.querySelector('.main-content');
    var STORAGE_KEY = 'super_sidebar_collapsed';
    var sidebarNav = sidebar ? sidebar.querySelector('.sidebar-nav') : null;

    function isMobile(){ return window.innerWidth < 992; }
    function preventBgScroll(e){ if (sidebarNav && sidebarNav.contains(e.target)) return; e.preventDefault(); }
    function openMobileSidebar(){ document.documentElement.classList.add('sidebar-open');document.body.classList.add('sidebar-open');sidebar&&sidebar.classList.add('open');overlay&&overlay.classList.add('show');mainContent&&mainContent.classList.add('blurred');document.addEventListener('touchmove',preventBgScroll,{passive:false}); }
    function closeMobileSidebar(){ document.removeEventListener('touchmove',preventBgScroll);sidebar&&sidebar.classList.remove('open');overlay&&overlay.classList.remove('show');mainContent&&mainContent.classList.remove('blurred');document.documentElement.classList.remove('sidebar-open');document.body.classList.remove('sidebar-open'); }
    if (sidebar && !isMobile() && localStorage.getItem(STORAGE_KEY)==='1') sidebar.classList.add('collapsed');
    if(toggleBtn) toggleBtn.addEventListener('click',function(){if(isMobile()){sidebar&&sidebar.classList.contains('open')?closeMobileSidebar():openMobileSidebar();}else if(sidebar){sidebar.classList.toggle('collapsed');localStorage.setItem(STORAGE_KEY,sidebar.classList.contains('collapsed')?'1':'0');}});
    if(overlay) overlay.addEventListener('click',closeMobileSidebar);
    var resizeTimer;window.addEventListener('resize',function(){clearTimeout(resizeTimer);resizeTimer=setTimeout(function(){if(isMobile()){sidebar&&sidebar.classList.remove('collapsed')}else{closeMobileSidebar();if(sidebar&&localStorage.getItem(STORAGE_KEY)==='1')sidebar.classList.add('collapsed')}},150)});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&isMobile()&&sidebar&&sidebar.classList.contains('open'))closeMobileSidebar()});

    if(sidebarNav&&!sidebarNav.querySelector('a[href$="/super/settings/bank-accounts"]')){var labels=sidebarNav.querySelectorAll('.sidebar-section-label'),settingsLabel=null;for(var i=0;i<labels.length;i++){var labelText=(labels[i].textContent||'').trim().toLowerCase();if(labelText==='configuración'||labelText==='settings'){settingsLabel=labels[i];break}}if(settingsLabel){var generalItem=settingsLabel.nextElementSibling;while(generalItem&&!generalItem.classList.contains('nav-item'))generalItem=generalItem.nextElementSibling;if(generalItem){var currentPath=window.location.pathname.replace(/\/$/,'');var bankItem=document.createElement('div');bankItem.className='nav-item';bankItem.innerHTML='<a class="nav-link'+(currentPath==='/super/settings/bank-accounts'?' active':'')+'" href="/super/settings/bank-accounts" data-title="Cuentas bancarias"><i class="bi bi-bank"></i><span>Cuentas bancarias</span></a>';generalItem.insertAdjacentElement('afterend',bankItem)}}}

    var exact={
      'Super Admin':'Superadministrador','Toggle sidebar':'Mostrar u ocultar menú','Language':'Idioma','Profile menu':'Menú de perfil',
      'Theme Customizer':'Personalizar apariencia','Primary Color':'Color principal','Custom Color':'Color personalizado','Reset to default':'Restablecer predeterminado','Customize theme color':'Personalizar color del tema','Close customizer':'Cerrar personalizador','Pick a custom color':'Elegir un color personalizado','Hex color value':'Valor hexadecimal del color',
      'Green':'Verde','Indigo':'Índigo','Blue':'Azul','Cyan':'Cian','Teal':'Verde azulado','Purple':'Morado','Pink':'Rosa','Red':'Rojo','Orange':'Naranja','Amber':'Ámbar','Slate':'Pizarra','Gray':'Gris',
      'English':'Inglés','French':'Francés','Spanish':'Español','German':'Alemán','Portuguese':'Portugués','Hindi':'Hindi','Bengali':'Bengalí','Turkish':'Turco','Urdu':'Urdu','Arabic':'Árabe',
      'Billing payment proof awaiting verification':'Comprobante de pago de facturación pendiente de verificación','N/A':'No disponible',
      'Are you sure?':'¿Estás seguro?','Yes':'Sí','Cancel':'Cancelar','View':'Ver','pending':'pendiente','registration':'registro','payment':'pago'
    };
    var phrases=[
      [/^View (\d+) pending registrations?$/i,'Ver $1 registros pendientes'],
      [/^View (\d+) pending billing payments?$/i,'Ver $1 pagos de facturación pendientes'],
      [/^Billing payment proof awaiting verification — (.+)$/i,'Comprobante de pago pendiente de verificación — $1']
    ];
    function tr(s){var t=(s||'').trim();if(exact[t])return exact[t];for(var j=0;j<phrases.length;j++){if(phrases[j][0].test(t))return t.replace(phrases[j][0],phrases[j][1])}return t}
    function node(n){if(n.nodeType===3){var o=n.nodeValue,t=o.trim(),v=tr(t);if(t&&v!==t)n.nodeValue=o.replace(t,v);return}if(!(n instanceof Element)||['SCRIPT','STYLE','CODE','PRE'].indexOf(n.tagName)!==-1)return;['aria-label','title','placeholder'].forEach(function(a){var v=n.getAttribute(a);if(v){var nv=tr(v);if(nv!==v)n.setAttribute(a,nv)}});Array.prototype.forEach.call(n.childNodes,function(c){if(c.nodeType===3)node(c)})}
    function scan(root){if(!root)return;if(root.nodeType===3)return node(root);if(root instanceof Element){node(root);root.querySelectorAll('*').forEach(node)}}
    function startSpanishGuard(){scan(document.body);var obs=new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(scan);if(m.type==='characterData')node(m.target);if(m.type==='attributes')node(m.target)})});obs.observe(document.body,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['aria-label','title','placeholder']});}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',startSpanishGuard,{once:true});else startSpanishGuard();
})();

function swalConfirm(e, opts) {
    e.preventDefault();
    var form = e.target.closest('form') || e.target;
    var config = Object.assign({title:'¿Estás seguro?',text:'',icon:'warning',showCancelButton:true,confirmButtonText:'Sí',cancelButtonText:'Cancelar',confirmButtonColor:'#6366f1',cancelButtonColor:'#64748b',reverseButtons:true}, opts);
    Swal.fire(config).then(function(result){if(result.isConfirmed)form.submit();});
    return false;
}

var themeToggle=document.getElementById('themeToggle');if(themeToggle)themeToggle.addEventListener('click',function(){document.body.classList.toggle('dark-mode');localStorage.setItem('super_theme',document.body.classList.contains('dark-mode')?'dark':'light');});
window.addEventListener('load',function(){var loader=document.getElementById('pageLoader');if(loader)loader.classList.add('hide');});