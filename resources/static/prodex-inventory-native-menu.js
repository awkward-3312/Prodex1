(function(){
  'use strict';
  if(window.__prodexInventoryNativeMenuInstalled)return;
  window.__prodexInventoryNativeMenuInstalled=true;

  function routerFor(link){
    var candidates=[
      link&&link.closest?link.closest('.vertical-sidebar-wrapper'):null,
      document.querySelector('.vertical-sidebar'),
      document.querySelector('.vertical-sidebar-wrapper'),
      document.getElementById('app')
    ];

    for(var i=0;i<candidates.length;i++){
      var el=candidates[i];
      var vm=el&&el.__vue__;
      if(vm&&vm.$router)return vm.$router;
      if(vm&&vm.$root&&vm.$root.$router)return vm.$root.$router;
    }

    return null;
  }

  function apply(){
    var mappings=[
      ['.px-iv-menu-missing a','/app/inventory/missing'],
      ['.px-iv-menu-stock a','/app/inventory/location-stock']
    ];

    mappings.forEach(function(entry){
      Array.prototype.forEach.call(document.querySelectorAll(entry[0]),function(link){
        if(link.getAttribute('data-prodex-native-inventory')==='1')return;
        link.setAttribute('href',entry[1]);
        link.setAttribute('data-prodex-native-inventory','1');

        link.addEventListener('click',function(event){
          if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;

          var router=routerFor(link);
          if(!router)return;

          event.preventDefault();
          event.stopPropagation();
          router.push(entry[1]).catch(function(){});
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
