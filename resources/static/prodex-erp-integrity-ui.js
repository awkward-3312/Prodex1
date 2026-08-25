(function () {
  'use strict';
  if (window.__prodexErpIntegrityUiInstalled) return;
  window.__prodexErpIntegrityUiInstalled = true;

  var state = { notifications: [], unread: 0, categories: {}, loaded: false };
  var timer = null;

  function esc(value) { return String(value == null ? '' : value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
  function style() {
    if (document.getElementById('prodex-erp-integrity-style')) return;
    var s=document.createElement('style'); s.id='prodex-erp-integrity-style';
    s.textContent=['#notif-dd .dropdown-menu{min-width:350px!important;max-width:min(410px,calc(100vw - 24px))!important}', '.px-notification-title{padding:13px 14px 10px;font-size:13px;font-weight:800;color:#202939;border-bottom:1px solid #eef1f4}', '.px-notification-section{padding:9px 14px 6px;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#98a2b3;border-top:1px solid #eef1f4}', '.px-unified-notification{display:block;width:100%;border:0;border-top:1px solid #f1f3f5;background:#fff;padding:11px 14px;text-align:left;cursor:pointer}', '.px-unified-notification:hover{background:#f8fafc}.px-unified-notification.unread{background:#f2fbfd}', '.px-unified-notification strong{display:block;font-size:12px;color:#202939}.px-unified-notification span{display:block;margin-top:3px;font-size:11px;line-height:1.4;color:#667085}', '.px-unified-empty{padding:20px 14px;text-align:center;color:#7a8494;font-size:12px}', '.main-header #notif-dd .badge,.vertical-top-nav #notif-dd .badge{min-width:18px!important;width:auto!important;padding:2px 5px!important}', 'body.dark-theme .px-unified-notification{background:#1f2030;color:#e5e7eb;border-color:#34364a}body.dark-theme .px-unified-notification strong,body.dark-theme .px-notification-title{color:#f4f4f5}'].join('');
    document.head.appendChild(s);
  }

  function fetchNotifications() {
    if (!window.axios) return;
    window.axios.get('/api/notification-center',{baseURL:'',meta:{skipErrorRedirect:true,skipInitialLoader:true}}).then(function(response){
      var data=response&&response.data?response.data:{}; state.notifications=Array.isArray(data.notifications)?data.notifications:[]; state.unread=Number(data.unread||0); state.categories=data.categories||{}; state.loaded=true; render();
    }).catch(function(error){ if(error&&error.response&&[401,403].indexOf(error.response.status)!==-1){state.notifications=[];state.unread=0;state.loaded=true;render();} });
  }

  function stockAlertCount(menu) {
    if(!menu)return 0; var total=0;
    Array.prototype.forEach.call(menu.querySelectorAll('.notification-item'),function(item){ if(item.closest&&item.closest('#px-unified-notifications'))return; var m=String(item.textContent||'').match(/\d+/); if(m)total+=Number(m[0]||0); });
    return total;
  }

  function updateBadge(menu) {
    var root=document.getElementById('notif-dd'); if(!root)return; var toggle=root.querySelector('button.dropdown-toggle,.dropdown-toggle'); if(!toggle)return; var badge=toggle.querySelector('.badge'); var total=stockAlertCount(menu)+state.unread;
    if(total<=0){if(badge)badge.style.display='none';return;} if(!badge){badge=document.createElement('span');badge.className='badge badge-primary';toggle.insertBefore(badge,toggle.firstChild);} badge.textContent=total>99?'99+':String(total);badge.style.display='flex';
  }

  function render() {
    var root=document.getElementById('notif-dd'); if(!root)return; var menu=root.querySelector('.dropdown-menu'); if(!menu){updateBadge(null);return;}
    var old=menu.querySelector('#px-unified-notifications'); if(old)old.remove();
    var wrap=document.createElement('div'); wrap.id='px-unified-notifications'; var html='<div class="px-notification-title">Notificaciones</div>';
    var categories={}; state.notifications.forEach(function(n){var key=n.category||'system';(categories[key]||(categories[key]=[])).push(n);});
    Object.keys(categories).forEach(function(key){ html+='<div class="px-notification-section">'+esc(state.categories[key]||key)+'</div>'; categories[key].slice(0,10).forEach(function(n){html+='<button type="button" class="px-unified-notification '+(n.unread?'unread':'')+'" data-px-action="'+esc(n.action||'')+'" data-px-read="'+esc(n.read_endpoint||'')+'"><strong>'+esc(n.title||'Notificación')+'</strong><span>'+esc(n.message||'')+'</span></button>';}); });
    if(!state.notifications.length&&stockAlertCount(menu)<=0)html+='<div class="px-unified-empty">No tienes notificaciones nuevas.</div>';
    wrap.innerHTML=html; menu.insertBefore(wrap,menu.firstChild);
    Array.prototype.forEach.call(wrap.querySelectorAll('[data-px-action]'),function(button){button.addEventListener('click',function(){var action=button.getAttribute('data-px-action');var read=button.getAttribute('data-px-read');var go=function(){if(action)window.location.href=action;};if(read&&window.axios)window.axios.post(read,{}, {baseURL:'',meta:{skipErrorRedirect:true,skipInitialLoader:true}}).then(go).catch(go);else go();});});
    updateBadge(menu);
  }

  function install(){style();fetchNotifications();if(timer)clearInterval(timer);timer=setInterval(fetchNotifications,30000);document.addEventListener('click',function(e){if(e.target&&e.target.closest&&e.target.closest('#notif-dd'))setTimeout(render,60);},true);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);else install();
})();
