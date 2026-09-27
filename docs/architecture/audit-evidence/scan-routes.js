const fs=require('fs');const parser=require('/Users/betzabe/Documents/GitHub/Prodex1/node_modules/@babel/parser');
const traverse=require('/Users/betzabe/Documents/GitHub/Prodex1/node_modules/@babel/traverse').default;
function analyze(file){
const src=fs.readFileSync(file,'utf8');
const ast=parser.parse(src,{sourceType:'module',plugins:['dynamicImport','optionalChaining','nullishCoalescingOperator']});
const st={records:0,withComponent:0,lazy:0,eager:0,redirect:0,beforeEnter:0,meta:0,named:0,props:0,catchAll:0,alias:0,children:0,dynParams:0,optionalParams:0,regexParams:0,wildcardPath:[],pathOptional:[],metaKeys:{}, redirectFn:0,eagerNames:[]};
const key=(o,n)=>o.properties.find(p=>p.key&&(p.key.name===n||p.key.value===n));
traverse(ast,{ObjectExpression(p){const o=p.node;const pk=key(o,'path');if(!pk||pk.value.type!=='StringLiteral'&&pk.value.type!=='TemplateLiteral')return;
 const path=pk.value.value;st.records++;
 const c=key(o,'component');if(c){st.withComponent++;const v=c.value;const s=src.slice(v.start,v.end);if(/import\(/.test(s)&&(v.type==='ArrowFunctionExpression'||v.type==='FunctionExpression'))st.lazy++;else {st.eager++;st.eagerNames.push(s.slice(0,50));}}
 if(key(o,'redirect')){st.redirect++;const r=key(o,'redirect').value;if(r.type.includes('Function'))st.redirectFn++;}
 if(key(o,'beforeEnter'))st.beforeEnter++;
 const m=key(o,'meta');if(m){st.meta++;if(m.value.type==='ObjectExpression')m.value.properties.forEach(pp=>{const k=pp.key&&(pp.key.name||pp.key.value);st.metaKeys[k]=(st.metaKeys[k]||0)+1});}
 if(key(o,'name'))st.named++;if(key(o,'props'))st.props++;if(key(o,'alias'))st.alias++;if(key(o,'children'))st.children++;
 if(path==='*'||/\*/.test(path))st.wildcardPath.push(path);
 if(/:[A-Za-z_]+\?/.test(path))st.pathOptional.push(path);
 if(/:[A-Za-z_]+\(/.test(path))st.regexParams++;
 if(/:/.test(path))st.dynParams++;
}});
return st;}
for(const f of process.argv.slice(2)){console.log(f);const s=analyze(f);console.log(JSON.stringify(s,null,1));}
