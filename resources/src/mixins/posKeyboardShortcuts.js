/**
 * POS keyboard shortcuts + Honduras fiscal bridge.
 * Keeps the large POS component stable while centralizing the SAR-specific
 * receipt augmentation and line-tax behavior needed by Honduras tenants.
 */

export const POS_SHORTCUTS_STORAGE_KEY = "pos_keyboard_shortcuts_enabled";

export function posShortcutsEnabled() {
  try {
    const v = localStorage.getItem(POS_SHORTCUTS_STORAGE_KEY);
    if (v === null) return true;
    return v === "1";
  } catch (e) { return true; }
}

export function setPosShortcutsEnabled(value) {
  try { localStorage.setItem(POS_SHORTCUTS_STORAGE_KEY, value ? "1" : "0"); } catch (e) {}
}

export const POS_SHORTCUTS = [
  { id:"search", keys:"F2", descriptionKey:"Shortcut_Focus_Search", descriptionFallback:"Enfocar búsqueda de productos", match:e=>e.key==="F2", action:vm=>{ const el=document.querySelector(".pos-shell-search-input")||document.querySelector(".search-input"); if(el&&typeof el.focus==="function"){el.focus();if(typeof el.select==="function")el.select();} } },
  { id:"payment", keys:"F4", descriptionKey:"Shortcut_Open_Payment", descriptionFallback:"Abrir ventana de pago", match:e=>e.key==="F4", action:vm=>{ if(typeof vm.openModernPaymentModal==="function"&&vm.details&&vm.details.length)vm.openModernPaymentModal(); } },
  { id:"hold", keys:"F6", descriptionKey:"Shortcut_Hold_Sale", descriptionFallback:"Poner venta en espera (borrador)", match:e=>e.key==="F6", action:vm=>{ if(typeof vm.Submit_Draft==="function")vm.Submit_Draft(); } },
  { id:"recall", keys:"F7", descriptionKey:"Shortcut_Recall_Sale", descriptionFallback:"Recuperar ventas en espera", match:e=>e.key==="F7", action:vm=>{ if(typeof vm.loadDraftSale==="function")vm.loadDraftSale(); } },
  { id:"customer", keys:"F8", descriptionKey:"Shortcut_Quick_Customer", descriptionFallback:"Agregar cliente rápidamente", match:e=>e.key==="F8", action:vm=>{ if(typeof vm.Quick_Add_Client==="function")vm.Quick_Add_Client(); } },
  { id:"print", keys:"F9", descriptionKey:"Shortcut_Print_Receipt", descriptionFallback:"Imprimir último recibo", match:e=>e.key==="F9", action:vm=>{ if(typeof vm.print_last_receipt==="function")vm.print_last_receipt();else if(typeof vm.print_pos==="function")vm.print_pos(); } },
  { id:"clear", keys:"Esc", descriptionKey:"Shortcut_Clear_Cart", descriptionFallback:"Vaciar carrito (con confirmación)", match:e=>e.key==="Escape", action:vm=>{ if(!vm.details||!vm.details.length)return;if(typeof vm.confirmClearCart==="function")vm.confirmClearCart();else if(typeof vm.Reset_Pos==="function")vm.Reset_Pos(); } },
  { id:"inc", keys:"Ctrl + ArrowUp", descriptionKey:"Shortcut_Increase_Last", descriptionFallback:"Aumentar cantidad del último artículo", match:e=>e.ctrlKey&&e.key==="ArrowUp", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.increment==="function")vm.increment(last.detail_id); } },
  { id:"dec", keys:"Ctrl + ArrowDown", descriptionKey:"Shortcut_Decrease_Last", descriptionFallback:"Disminuir cantidad del último artículo", match:e=>e.ctrlKey&&e.key==="ArrowDown", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.decrement==="function")vm.decrement(last,last.detail_id); } },
  { id:"remove", keys:"Ctrl + Delete", descriptionKey:"Shortcut_Remove_Last", descriptionFallback:"Eliminar el último artículo del carrito", match:e=>e.ctrlKey&&e.key==="Delete", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.delete_Product_Detail==="function")vm.delete_Product_Detail(last.detail_id); } },
  { id:"help", keys:"Shift + ?", descriptionKey:"Shortcut_Show_Help", descriptionFallback:"Mostrar ayuda de atajos", match:e=>e.shiftKey&&(e.key==="?"||e.key==="/"), action:vm=>{ if(vm.$bvModal&&typeof vm.$bvModal.show==="function")vm.$bvModal.show("pos-keyboard-shortcuts-help"); } },
];

function isTypingTarget(target) {
  if (!target) return false;
  const tag=(target.tagName||"").toUpperCase();
  return tag==="INPUT"||tag==="TEXTAREA"||tag==="SELECT"||target.isContentEditable;
}

function escapeHtml(value) {
  return String(value==null?"":value).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/'/g,"&#039;");
}

function money(value) {
  const n=Number(value||0);
  return (Number.isFinite(n)?n:0).toFixed(2);
}

function fiscalRangeNumber(value, fiscalNumber) {
  if (value===null||value===undefined||value==="") return "";
  const raw=String(value).trim();
  if (raw.indexOf("-")!==-1) return raw;
  const parts=String(fiscalNumber||"").split("-");
  const prefix=parts.length>=4?parts.slice(0,3).join("-")+"-":"";
  return prefix+raw.replace(/\D+/g,"").padStart(8,"0");
}

function boolSetting(settings,key,fallback=true){
  if(!settings||settings[key]===undefined||settings[key]===null)return fallback;
  return settings[key]===true||settings[key]===1||settings[key]==="1";
}

function formValue(id) {
  try { const el=document.getElementById(id); return el ? String(el.value||"").trim() : ""; } catch(e){ return ""; }
}

export default {
  methods: {
    isHondurasLineTaxMode() {
      return String(this.taxPolicyCountryCode||"").toUpperCase()==="HN" && !!(this.taxConfig&&this.taxConfig.supports_line_tax);
    },

    calculateHondurasLineTotals() {
      const decimals=Number(this.priceDecimals||2);
      let gross=0;
      let rawTax=0;
      (this.details||[]).forEach(d=>{
        const qty=Number(d.quantity||0);
        const unitTax=Number(d.taxe||0);
        const net=Number(d.Net_price||0);
        d.subtotal=parseFloat((qty*net+qty*unitTax).toFixed(decimals));
        gross+=Number(d.subtotal||0);
        rawTax+=qty*unitTax;
      });
      gross=parseFloat(gross.toFixed(decimals));
      this.total=gross;

      const method=String((this.sale&&this.sale.discount_Method)||"2");
      const value=Number((this.sale&&this.sale.discount)||0);
      let discount=method==="1"?gross*(value/100):Math.min(value,gross);
      discount=Math.max(0,discount);
      const afterManual=Math.max(gross-discount,0);
      const points=Math.min(Number(this.discount_from_points||0),afterManual);
      discount+=points;
      const promo=Math.min(Number(this.promotionDiscount||0),Math.max(gross-discount,0));
      const afterDiscount=Math.max(gross-discount-promo,0);
      const ratio=gross>0?afterDiscount/gross:0;

      if(this.sale){
        // Honduras line tax is already included in the product totals. Store only the
        // effective tax amount for reporting; do not add a second order-level 15%.
        this.sale.TaxNet=parseFloat((rawTax*ratio).toFixed(decimals));
      }
      this.GrandTotal=parseFloat((afterDiscount+Number((this.sale&&this.sale.shipping)||0)).toFixed(decimals));
      try{this._cd_queue_broadcast&&this._cd_queue_broadcast();}catch(e){}
      return this.GrandTotal;
    },

    hasSarSaleData() {
      const data=this.sarFiscalSaleData||{};
      return Object.keys(data).some(k=>String(data[k]||"").trim()!=="");
    },

    updateSarSaleButton() {
      try {
        const button=document.getElementById("prodex-sar-sale-data-btn");
        if(!button)return;
        const active=this.hasSarSaleData();
        button.textContent=active?"Fiscal configurado":"Datos fiscales";
        button.style.borderColor=active?"#16a34a":"#d1d5db";
        button.style.color=active?"#166534":"#374151";
        button.style.background=active?"#f0fdf4":"#ffffff";
      } catch(e) {}
    },

    ensureSarSaleButton() {
      try {
        if(typeof document==="undefined"||!this.isHondurasLineTaxMode())return;
        if(document.getElementById("prodex-sar-sale-data-btn")){this.updateSarSaleButton();return;}
        const anchor=document.querySelector(".pos-cust-trigger")||document.querySelector(".pos-shell-register-status")||document.querySelector(".pos-shell-header");
        if(!anchor||!anchor.parentNode)return;
        const button=document.createElement("button");
        button.id="prodex-sar-sale-data-btn";
        button.type="button";
        button.textContent="Datos fiscales";
        button.title="Datos fiscales o de exoneración de esta venta";
        button.style.cssText="height:32px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;";
        button.addEventListener("click",()=>this.configureSarSaleData());
        if(anchor.nextSibling)anchor.parentNode.insertBefore(button,anchor.nextSibling);else anchor.parentNode.appendChild(button);
        this.updateSarSaleButton();
      } catch(e) {}
    },

    configureSarSaleData() {
      const current=Object.assign({
        exempt_purchase_order_number:"",
        sar_registry_number:"",
        exoneration_registry_number:"",
        exonerated_card_number:""
      },this.sarFiscalSaleData||{});

      if(!this.$swal)return;
      this.$swal({
        title:"Datos fiscales de esta venta",
        html:
          '<div style="text-align:left;font-size:12px;">'+
          '<label style="display:block;margin:8px 0 3px;">No. orden de compra exenta</label><input id="sar-sale-exempt-po" class="swal2-input" style="margin:0;width:100%;" value="'+escapeHtml(current.exempt_purchase_order_number)+'">'+
          '<label style="display:block;margin:8px 0 3px;">No. registro SAG/SAR</label><input id="sar-sale-registry" class="swal2-input" style="margin:0;width:100%;" value="'+escapeHtml(current.sar_registry_number)+'">'+
          '<label style="display:block;margin:8px 0 3px;">No. registro exonerado</label><input id="sar-sale-exoneration" class="swal2-input" style="margin:0;width:100%;" value="'+escapeHtml(current.exoneration_registry_number)+'">'+
          '<label style="display:block;margin:8px 0 3px;">No. carnet/documento exonerado</label><input id="sar-sale-card" class="swal2-input" style="margin:0;width:100%;" value="'+escapeHtml(current.exonerated_card_number)+'">'+
          '<small style="display:block;margin-top:8px;color:#6b7280;">Déjalos en blanco cuando la operación no sea exenta o exonerada. Estos valores se congelarán en la factura emitida.</small></div>',
        showCancelButton:true,
        showDenyButton:this.hasSarSaleData(),
        confirmButtonText:"Guardar",
        cancelButtonText:"Cancelar",
        denyButtonText:"Limpiar",
        preConfirm:()=>({
          exempt_purchase_order_number:formValue("sar-sale-exempt-po"),
          sar_registry_number:formValue("sar-sale-registry"),
          exoneration_registry_number:formValue("sar-sale-exoneration"),
          exonerated_card_number:formValue("sar-sale-card")
        })
      }).then(result=>{
        if(result.isDenied){
          this.$set(this,"sarFiscalSaleData",{});
          this.updateSarSaleButton();
          return;
        }
        if(result.value){
          const clean={};
          Object.keys(result.value).forEach(k=>{if(String(result.value[k]||"").trim()!=="")clean[k]=String(result.value[k]).trim();});
          this.$set(this,"sarFiscalSaleData",clean);
          this.updateSarSaleButton();
        }
      });
    },

    renderSarFiscalReceipt() {
      try {
        if(typeof document==="undefined")return;
        const root=document.getElementById("invoice-POS");
        if(!root)return;
        const fiscal=this.invoice_pos&&this.invoice_pos.sar_fiscal;
        const old=root.querySelector(".sar-fiscal-pos-block");
        if(!fiscal||!fiscal.fiscal_number){if(old&&old.parentNode)old.parentNode.removeChild(old);return;}
        if(old&&old.getAttribute("data-fiscal-number")===String(fiscal.fiscal_number))return;
        if(old&&old.parentNode)old.parentNode.removeChild(old);

        const issuer=fiscal.issuer||{};
        const customer=fiscal.customer||{};
        const snap=fiscal.sale||{};
        const totals=snap.fiscal_totals||{};
        const settings=issuer.invoice_settings||{};
        const legalName=issuer.trade_name||issuer.legal_name||"";
        const issuerAddress=issuer.point_of_issue_address||issuer.head_office_address||"";
        const rangeStart=fiscalRangeNumber(fiscal.range_start,fiscal.fiscal_number);
        const rangeEnd=fiscalRangeNumber(fiscal.range_end,fiscal.fiscal_number);
        const isVoided=String(fiscal.status||"").toLowerCase()==="voided";
        const title=String(settings.document_title||"FACTURA");
        const saleType=String(settings.sale_type_label||"");

        root.classList.add("sar-fiscal-receipt");
        const info=root.querySelector(".info");
        if(info){
          const logo=info.querySelector(".invoice_logo");
          if(logo&&!boolSetting(settings,"show_logo",true))logo.style.display="none";
        }

        const fiscalRows=[];
        const pushRow=(label,val,strong=false)=>{ if(val===undefined||val===null||val==="")return; fiscalRows.push(`<div style="display:flex;justify-content:space-between;gap:8px;${strong?'font-weight:800;':''}"><span>${escapeHtml(label)}</span><span style="white-space:nowrap;">L ${money(val)}</span></div>`); };
        pushRow("Descuentos y rebajas",totals.discount_total||0);
        pushRow("Subtotal",totals.subtotal||0);
        pushRow("Importe exonerado",totals.exonerated_amount||0);
        pushRow("Importe exento",totals.exempt_amount||0);
        if(Number(totals.zero_rate_amount||0)>0)pushRow("Importe tasa cero",totals.zero_rate_amount);
        pushRow("Importe gravado 15%",totals.taxable_15_amount||0);
        pushRow("Importe gravado 18%",totals.taxable_18_amount||0);
        pushRow("ISV 15%",totals.tax_15_amount||0);
        pushRow("ISV 18%",totals.tax_18_amount||0);
        if(Number(totals.other_taxable_amount||0)>0)pushRow("Otros importes gravados",totals.other_taxable_amount);
        if(Number(totals.other_tax_amount||0)>0)pushRow("Otros impuestos",totals.other_tax_amount);
        pushRow("TOTAL",totals.grand_total!==undefined?totals.grand_total:(snap.grand_total||0),true);

        const clientExtra=[];
        if(customer.rtn)clientExtra.push(`<div><strong>RTN:</strong> ${escapeHtml(customer.rtn)}</div>`);
        else if(customer.identification_number)clientExtra.push(`<div><strong>${escapeHtml(customer.identification_type||"Identificación")}:</strong> ${escapeHtml(customer.identification_number)}</div>`);
        if(boolSetting(settings,"show_customer_address",true)&&customer.address)clientExtra.push(`<div>${escapeHtml(customer.address)}</div>`);
        if(customer.sar_registry_number)clientExtra.push(`<div><strong>Registro SAG/SAR:</strong> ${escapeHtml(customer.sar_registry_number)}</div>`);
        if(customer.exempt_purchase_order_number)clientExtra.push(`<div><strong>Orden de compra exenta:</strong> ${escapeHtml(customer.exempt_purchase_order_number)}</div>`);
        if(customer.exoneration_registry_number)clientExtra.push(`<div><strong>Registro exonerado:</strong> ${escapeHtml(customer.exoneration_registry_number)}</div>`);
        if(customer.exonerated_card_number)clientExtra.push(`<div><strong>Carnet exonerado:</strong> ${escapeHtml(customer.exonerated_card_number)}</div>`);

        const block=document.createElement("div");
        block.className="sar-fiscal-pos-block";
        block.setAttribute("data-fiscal-number",String(fiscal.fiscal_number));
        block.style.cssText="font-size:9.5px;line-height:1.3;margin:0 0 8px;padding:0 3px 8px;border-bottom:1px dashed #333;word-break:break-word;text-transform:none;color:#111;";
        block.innerHTML=
          `<div style="text-align:center;">`+
          (legalName?`<div style="font-size:12px;font-weight:800;">${escapeHtml(legalName)}</div>`:"")+
          (issuer.legal_name&&issuer.trade_name?`<div>${escapeHtml(issuer.legal_name)}</div>`:"")+
          (issuer.rtn?`<div><strong>RTN:</strong> ${escapeHtml(issuer.rtn)}</div>`:"")+
          (issuerAddress?`<div>${escapeHtml(issuerAddress)}</div>`:"")+
          (issuer.phone?`<div>Tel: ${escapeHtml(issuer.phone)}</div>`:"")+
          (issuer.email?`<div>${escapeHtml(issuer.email)}</div>`:"")+
          (settings.website?`<div>${escapeHtml(settings.website)}</div>`:"")+
          `<div style="font-size:12px;font-weight:900;margin-top:5px;">${escapeHtml(title)}${saleType?" "+escapeHtml(saleType):""}</div>`+
          (isVoided?`<div style="font-size:12px;font-weight:900;border:2px solid #000;padding:2px 5px;margin:3px auto;display:inline-block;">ANULADA</div>`:"")+
          `<div style="font-size:11px;font-weight:800;">${escapeHtml(fiscal.fiscal_number)}</div>`+
          (fiscal.cai?`<div style="margin-top:3px;"><strong>CAI:</strong> ${escapeHtml(fiscal.cai)}</div>`:"")+
          ((rangeStart||rangeEnd)?`<div><strong>Rango autorizado:</strong><br>${escapeHtml(rangeStart)} al ${escapeHtml(rangeEnd)}</div>`:"")+
          (fiscal.deadline?`<div><strong>Fecha límite de emisión:</strong> ${escapeHtml(fiscal.deadline)}</div>`:"")+
          `</div>`+
          `<div style="border-top:1px dashed #333;margin-top:5px;padding-top:4px;"><strong>Cliente:</strong> ${escapeHtml(customer.name||"Consumidor final")}${clientExtra.join("")}</div>`+
          (boolSetting(settings,"show_internal_reference",true)&&snap.internal_reference?`<div><strong>Referencia:</strong> ${escapeHtml(snap.internal_reference)}</div>`:"")+
          (boolSetting(settings,"show_warehouse",true)&&snap.warehouse_name?`<div><strong>Almacén:</strong> ${escapeHtml(snap.warehouse_name)}</div>`:"")+
          `<div style="border-top:1px dashed #333;margin-top:5px;padding-top:4px;">${fiscalRows.join("")}</div>`+
          (boolSetting(settings,"show_total_in_words",true)&&fiscal.total_in_words?`<div style="text-align:center;margin-top:5px;font-weight:700;">${escapeHtml(fiscal.total_in_words)}</div>`:"")+
          `<div style="text-align:center;margin-top:5px;">${escapeHtml(settings.original_label||"Original: Cliente")}<br>${escapeHtml(settings.copy_label||"Copia: Obligado Tributario Emisor")}</div>`+
          (settings.footer_message?`<div style="text-align:center;margin-top:5px;">${escapeHtml(settings.footer_message)}</div>`:"")+
          (isVoided&&fiscal.void_reason?`<div style="margin-top:3px;"><strong>Motivo:</strong> ${escapeHtml(fiscal.void_reason)}</div>`:"");

        const container=root.firstElementChild||root;
        container.insertBefore(block,container.firstChild);
      } catch(e) {}
    },
  },

  mounted() {
    this._posShortcutsHandler=null;
    if(this.sarFiscalSaleData===undefined)this.$set(this,"sarFiscalSaleData",{});

    const handler=e=>{
      if(!posShortcutsEnabled())return;
      try{if(typeof document!=="undefined"&&document.body&&document.body.classList.contains("modal-open"))return;}catch(e2){}
      const fromInput=isTypingTarget(e.target);
      const isFunctionKey=/^F[0-9]{1,2}$/.test(e.key)||e.key==="Escape";
      if(fromInput&&!isFunctionKey)return;
      for(const shortcut of POS_SHORTCUTS){
        if(shortcut.match(e)){
          e.preventDefault();e.stopPropagation();
          try{shortcut.action(this);}catch(err){console.warn("[POS shortcut] action failed:",shortcut.id,err);}
          return;
        }
      }
    };
    this._posShortcutsHandler=handler;
    try{window.addEventListener("keydown",handler,true);}catch(e){}

    try{
      if(typeof this.CalculTotal==="function"){
        this._originalCalculTotal=this.CalculTotal.bind(this);
        this.CalculTotal=(...args)=>{
          if(this.isHondurasLineTaxMode())return this.calculateHondurasLineTotals();
          return this._originalCalculTotal(...args);
        };
        setTimeout(()=>{try{if(this.details&&this.details.length)this.CalculTotal();}catch(e){}},0);
      }
    }catch(e){}

    this._sarUiTimer=setInterval(()=>{try{this.ensureSarSaleButton();}catch(e){}},1000);
    setTimeout(()=>this.ensureSarSaleButton(),400);

    try{
      if(typeof axios!=="undefined"&&axios.interceptors){
        this._sarRequestInterceptor=axios.interceptors.request.use(config=>{
          try{
            const url=String(config&&config.url||"");
            if(url.indexOf("pos/create_pos")!==-1&&this.hasSarSaleData()){
              const payload=typeof config.data==="string"?JSON.parse(config.data):Object.assign({},config.data||{});
              payload.fiscal_exemption_data=Object.assign({},this.sarFiscalSaleData);
              config.data=payload;
            }
          }catch(e){}
          return config;
        },error=>Promise.reject(error));
      }
      if(typeof axios!=="undefined"&&axios.interceptors&&axios.interceptors.response){
        this._sarReceiptInterceptor=axios.interceptors.response.use(response=>{
          try{
            const url=response&&response.config?String(response.config.url||""):"";
            const data=response&&response.data?response.data:null;
            if(url.indexOf("pos/create_pos")!==-1&&data&&data.success===true){
              this.$set(this,"sarFiscalSaleData",{});
              this.updateSarSaleButton();
            }
            if(url.indexOf("sales_print_invoice/")!==-1&&data&&data.sar_fiscal){
              const fiscal=data.sar_fiscal;
              const issuer=fiscal.issuer||{};
              data.setting=data.setting||{};
              data.setting.CompanyName=issuer.trade_name||issuer.legal_name||data.setting.CompanyName;
              data.setting.CompanyAdress=issuer.point_of_issue_address||issuer.head_office_address||data.setting.CompanyAdress;
              data.setting.CompanyPhone=issuer.phone||data.setting.CompanyPhone;
              data.setting.email=issuer.email||data.setting.email;
              if(this.invoice_pos)this.$set(this.invoice_pos,"sar_fiscal",fiscal);
              this.$nextTick(()=>this.renderSarFiscalReceipt());
              setTimeout(()=>this.renderSarFiscalReceipt(),650);
            }else if(url.indexOf("sales_print_invoice/")!==-1&&this.invoice_pos){
              this.$set(this.invoice_pos,"sar_fiscal",null);
            }
          }catch(e){}
          return response;
        },error=>Promise.reject(error));
      }
    }catch(e){this._sarReceiptInterceptor=null;this._sarRequestInterceptor=null;}
  },

  beforeDestroy() {
    try{if(this._posShortcutsHandler){window.removeEventListener("keydown",this._posShortcutsHandler,true);this._posShortcutsHandler=null;}}catch(e){}
    try{if(this._sarUiTimer){clearInterval(this._sarUiTimer);this._sarUiTimer=null;}}catch(e){}
    try{const b=document.getElementById("prodex-sar-sale-data-btn");if(b&&b.parentNode)b.parentNode.removeChild(b);}catch(e){}
    try{if(this._sarReceiptInterceptor!==null&&this._sarReceiptInterceptor!==undefined&&typeof axios!=="undefined"){axios.interceptors.response.eject(this._sarReceiptInterceptor);this._sarReceiptInterceptor=null;}}catch(e){}
    try{if(this._sarRequestInterceptor!==null&&this._sarRequestInterceptor!==undefined&&typeof axios!=="undefined"){axios.interceptors.request.eject(this._sarRequestInterceptor);this._sarRequestInterceptor=null;}}catch(e){}
  },
};
