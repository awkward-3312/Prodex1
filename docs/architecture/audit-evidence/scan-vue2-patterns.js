const fs=require('fs'),path=require('path');
const root='/Users/betzabe/Documents/GitHub/Prodex1/resources/src';
function walk(d,o=[]){for(const f of fs.readdirSync(d,{withFileTypes:true})){const p=path.join(d,f.name);if(f.isDirectory())walk(p,o);else o.push(p);}return o;}
const files=walk(root).filter(f=>/\.(vue|js)$/.test(f));
const count=(s,re)=>(s.match(re)||[]).length;
const pats={
 'Vue.use':/\bVue\.use\(/g,'Vue.component':/\bVue\.component\(/g,'Vue.directive':/\bVue\.directive\(/g,'Vue.filter':/\bVue\.filter\(/g,'Vue.mixin':/\bVue\.mixin\(/g,'Vue.prototype':/\bVue\.prototype\./g,
 'Vue.set/delete':/\bVue\.(set|delete)\(/g,'$set/$delete':/\.\$(set|delete)\(/g,'$listeners':/\$listeners/g,'$scopedSlots':/\$scopedSlots/g,'$children':/\$children/g,
 '$on/$off/$once':/\.\$(on|off|once)\(/g,'.native':/\.native\b/g,'.sync':/\.sync\b/g,'slot-scope':/slot-scope=/g,'slot="name"(old syntax)':/\sslot="[^"]+"/g,'v-slot/#':/(v-slot|<template\s+#)/g,
 'beforeDestroy':/\bbeforeDestroy\b/g,'destroyed':/\bdestroyed\s*[(:]/g,'Fire (event bus)':/\bFire\.\$(on|off|emit|once)/g,'$root':/\$root\b/g,'render(h)':/\brender\s*\(\s*h\b|render:\s*h\s*=>|\bh\(/g,
 'filters (|pipe in tpl)':/\{\{[^}]*\|\s*[a-zA-Z]+[^}|]*\}\}/g,'filters:{':/\bfilters\s*:\s*\{/g,'$refs':/\$refs\b/g,'$nextTick':/\$nextTick/g,'$forceUpdate':/\$forceUpdate/g,'$attrs':/\$attrs/g,'$parent':/\$parent\b/g,'$el':/this\.\$el\b/g,
 'v-model on component (value/input)':/model\s*:\s*\{/g,'functional':/\bfunctional\b/g,'$store':/\$store\b/g,'mapState/mapGetters/mapActions/mapMutations':/\b(mapState|mapGetters|mapActions|mapMutations)\b/g,
 '$route/$router':/\$(route|router)\b/g,'$t':/\$t\(/g,'$tc':/\$tc\(/g,'$d':/\$d\(/g,'$n':/\$n\(/g,'i18n (this.$i18n)':/\$i18n/g,'v-html':/v-html/g,'mixins:':/\bmixins\s*:/g,'extends:':/\bextends\s*:/g,
 'vue-meta metaInfo':/\bmetaInfo\b/g,'transition-group/transition':/<transition/g,'keep-alive':/<keep-alive/g,'inline-template':/inline-template/g,'v-on:keyup.<keycode num>':/@key(up|down|press)\.\d+/g,'config.keyCodes':/keyCodes/g,'v-bind.prop/.sync':/\.prop\b/g,
 'Vue.extend':/Vue\.extend\(/g,'new Vue(':/new Vue\(/g,'$mount':/\.\$mount\(/g,'$destroy':/\$destroy\(/g,'Vue.observable':/Vue\.observable/g,'Vue.config':/Vue\.config\./g,'this.$bvModal':/\$bvModal/g,'this.$bvToast':/\$bvToast/g,'$swal':/\$swal/g,'v-b-*directive':/\sv-b-[a-z-]+/g,
 'this.$children/$vnode':/\$vnode/g,'$slots':/\$slots/g,'window.Fire':/window\.Fire/g,'localStorage':/localStorage/g,'require(':/\brequire\(/g,'require.context':/require\.context/g,
};
const totals={},perFile={};
for(const p of Object.keys(pats))totals[p]={n:0,files:0};
const info=[];
for(const f of files){const s=fs.readFileSync(f,'utf8');const rel=path.relative(root,f);const loc=s.split('\n').length;
 const row={f:rel,loc};
 for(const [k,re] of Object.entries(pats)){const c=count(s,re);if(c){totals[k].n+=c;totals[k].files++;row[k]=c;}}
 // bootstrap-vue tags
 const bt=s.match(/<b-[a-z0-9-]+/g)||[];row.bTags=bt.length;
 row.methods=0;
 const ms=s.match(/\bmethods\s*:\s*\{/);
 row.watch=count(s,/\bwatch\s*:\s*\{/g)?count(s.slice(s.search(/\bwatch\s*:\s*\{/)),/^\s{4,6}['"]?[\w.$'"]+['"]?\s*(\(|:\s*(function|\{|async|\())/gm):0;
 row.api=count(s,/\baxios\.(get|post|put|delete|patch)|\.\$http|\baxios\(/g);
 row.imports=count(s,/^\s*import\s/gm);
 row.storeUse=(row['$store']||0)+(row['mapState/mapGetters/mapActions/mapMutations']||0);
 row.fireUse=(row['Fire (event bus)']||0);
 row.tpl=count(s,/<[a-z][\w-]*[\s>]/g);
 info.push(row);}
fs.writeFileSync(process.argv[2]+'/scan.json',JSON.stringify({totals,info},null,1));
console.log(JSON.stringify(totals,null,0));
