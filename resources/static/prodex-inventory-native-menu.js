(function(){
  'use strict';
  if(window.__prodexInventoryNativeMenuInstalled)return;
  window.__prodexInventoryNativeMenuInstalled=true;
  function apply(){
    var mappings=[['.px-iv-menu-missing a','/app/inventory/missing'],['.px-iv-menu-stock a','/app/inventory/location-stock']];
    mappings.forEach(function(entry){
      Array.prototype.forEach.call(document.querySelectorAll(entry[0]),function(link){
        if(link.getAttribute('data-prodex-native-inventory')==='1')return;
        link.setAttribute('href',entry[1]);link.setAttribute('data-prodex-native-inventory','1');
        link.addEventListener('click',function(event){
          if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
          var root=document.getElementById('app');var vm=root&&root.__vue__;var router=vm&&vm.$router;
          if(router){event.preventDefault();router.push(entry[1]).catch(function(){});}
        });
      });
    });
  }
  var observer=new MutationObserver(function(){window.requestAnimationFrame(apply);});
  if(document.body)observer.observe(document.body,{childList:true,subtree:true});
  document.addEventListener('DOMContentLoaded',apply,{once:true});
  window.addEventListener('load',apply,{once:true});
  apply();
})();
