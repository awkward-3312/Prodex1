/**
 * POS Keyboard Shortcuts Mixin
 * --------------------------------------------------------------------
 * Adds optional keyboard shortcuts to the POS screen WITHOUT modifying
 * any existing logic. It also bridges the SAR fiscal payload returned by
 * the POS invoice endpoint into the existing thermal receipt.
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
  { id:"search", keys:"F2", descriptionKey:"Shortcut_Focus_Search", descriptionFallback:"Focus product search", match:e=>e.key==="F2", action:vm=>{ const el=document.querySelector(".pos-shell-search-input")||document.querySelector(".search-input"); if(el&&typeof el.focus==="function"){el.focus();if(typeof el.select==="function")el.select();} } },
  { id:"payment", keys:"F4", descriptionKey:"Shortcut_Open_Payment", descriptionFallback:"Open payment modal", match:e=>e.key==="F4", action:vm=>{ if(typeof vm.openModernPaymentModal==="function"&&vm.details&&vm.details.length)vm.openModernPaymentModal(); } },
  { id:"hold", keys:"F6", descriptionKey:"Shortcut_Hold_Sale", descriptionFallback:"Hold sale (draft)", match:e=>e.key==="F6", action:vm=>{ if(typeof vm.Submit_Draft==="function")vm.Submit_Draft(); } },
  { id:"recall", keys:"F7", descriptionKey:"Shortcut_Recall_Sale", descriptionFallback:"Recall held sales", match:e=>e.key==="F7", action:vm=>{ if(typeof vm.loadDraftSale==="function")vm.loadDraftSale(); } },
  { id:"customer", keys:"F8", descriptionKey:"Shortcut_Quick_Customer", descriptionFallback:"Quick add customer", match:e=>e.key==="F8", action:vm=>{ if(typeof vm.Quick_Add_Client==="function")vm.Quick_Add_Client(); } },
  { id:"print", keys:"F9", descriptionKey:"Shortcut_Print_Receipt", descriptionFallback:"Print last receipt", match:e=>e.key==="F9", action:vm=>{ if(typeof vm.print_last_receipt==="function")vm.print_last_receipt();else if(typeof vm.print_pos==="function")vm.print_pos(); } },
  { id:"clear", keys:"Esc", descriptionKey:"Shortcut_Clear_Cart", descriptionFallback:"Clear cart (with confirmation)", match:e=>e.key==="Escape", action:vm=>{ if(!vm.details||!vm.details.length)return;if(typeof vm.confirmClearCart==="function")vm.confirmClearCart();else if(typeof vm.Reset_Pos==="function")vm.Reset_Pos(); } },
  { id:"inc", keys:"Ctrl + ArrowUp", descriptionKey:"Shortcut_Increase_Last", descriptionFallback:"Increase quantity of last item in cart", match:e=>e.ctrlKey&&e.key==="ArrowUp", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.increment==="function")vm.increment(last.detail_id); } },
  { id:"dec", keys:"Ctrl + ArrowDown", descriptionKey:"Shortcut_Decrease_Last", descriptionFallback:"Decrease quantity of last item in cart", match:e=>e.ctrlKey&&e.key==="ArrowDown", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.decrement==="function")vm.decrement(last,last.detail_id); } },
  { id:"remove", keys:"Ctrl + Delete", descriptionKey:"Shortcut_Remove_Last", descriptionFallback:"Remove last item from cart", match:e=>e.ctrlKey&&e.key==="Delete", action:vm=>{ if(!vm.details||!vm.details.length)return;const last=vm.details[vm.details.length-1];if(last&&typeof vm.delete_Product_Detail==="function")vm.delete_Product_Detail(last.detail_id); } },
  { id:"help", keys:"Shift + ?", descriptionKey:"Shortcut_Show_Help", descriptionFallback:"Show this shortcuts help", match:e=>e.shiftKey&&(e.key==="?"||e.key==="/"), action:vm=>{ if(vm.$bvModal&&typeof vm.$bvModal.show==="function")vm.$bvModal.show("pos-keyboard-shortcuts-help"); } },
];

function isTypingTarget(target) {
  if (!target) return false;
  const tag=(target.tagName||"").toUpperCase();
  return tag==="INPUT"||tag==="TEXTAREA"||tag==="SELECT"||target.isContentEditable;
}

function escapeHtml(value) {
  return String(value==null?"":value).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/'/g,"&#039;");
}

function fiscalRangeNumber(value, fiscalNumber) {
  if (value===null||value===undefined||value==="") return "";
  const raw=String(value).trim();
  if (raw.indexOf("-")!==-1) return raw;
  const parts=String(fiscalNumber||"").split("-");
  const prefix=parts.length>=4?parts.slice(0,3).join("-")+"-":"";
  return prefix+raw.replace(/\D+/g,"").padStart(8,"0");
}

export default {
  methods: {
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
        const legalName=issuer.trade_name||issuer.legal_name||"";
        const issuerAddress=issuer.point_of_issue_address||issuer.head_office_address||"";
        const rangeStart=fiscalRangeNumber(fiscal.range_start,fiscal.fiscal_number);
        const rangeEnd=fiscalRangeNumber(fiscal.range_end,fiscal.fiscal_number);
        const isVoided=String(fiscal.status||"").toLowerCase()==="voided";

        // SAR receipts use a slightly smaller type size than the generic POS
        // receipt so the mandatory fiscal data remains readable on 58/80mm rolls.
        root.classList.add("sar-fiscal-receipt");
        Array.prototype.forEach.call(root.querySelectorAll("p,span,td,th,small"),el=>{
          if(!el.style.fontSize)el.style.fontSize="11px";
          if(!el.style.lineHeight)el.style.lineHeight="1.35";
        });

        // Remove only the duplicated generic business identity from layout 1.
        // Reference/date/seller/customer/warehouse remain in their original area.
        const info=root.querySelector(".info");
        if(info){
          const logo=info.querySelector(".invoice_logo");
          if(logo)logo.style.display="none";
          const p=info.querySelector("p");
          if(p){
            Array.prototype.forEach.call(p.querySelectorAll("span"),span=>{
              const text=String(span.textContent||"").trim().toLowerCase();
              const company=String((this.invoice_pos.setting&&this.invoice_pos.setting.CompanyName)||"").trim().toLowerCase();
              if((company&&text===company)||text.indexOf("address :")===0||text.indexOf("adress :")===0||text.indexOf("email :")===0||text.indexOf("phone :")===0||text.indexOf("dirección :")===0||text.indexOf("direccion :")===0||text.indexOf("correo :")===0||text.indexOf("teléfono :")===0||text.indexOf("telefono :")===0){span.style.display="none";}
            });
          }
        }

        // Honduras tenant: use the fiscal name for the tax row instead of the
        // stock application's generic "Order Tax" wording.
        Array.prototype.forEach.call(root.querySelectorAll("td.total"),td=>{
          const text=String(td.textContent||"").trim().toLowerCase();
          if(text==="impuesto de orden"||text==="order tax"){
            const rate=this.invoice_pos&&this.invoice_pos.sale?Number(this.invoice_pos.sale.tax_rate||0):0;
            td.textContent=rate?`ISV ${rate.toFixed(0)}%`:"ISV";
          }
        });

        const block=document.createElement("div");
        block.className="sar-fiscal-pos-block";
        block.setAttribute("data-fiscal-number",String(fiscal.fiscal_number));
        block.style.cssText="text-align:center;font-size:9.5px;line-height:1.3;margin:0 0 7px;padding:0 3px 7px;border-bottom:1px dashed #333;word-break:break-word;text-transform:none;";
        block.innerHTML=
          (legalName?`<div style="font-size:11px;font-weight:700;">${escapeHtml(legalName)}</div>`:"")+
          (issuer.rtn?`<div><strong>RTN:</strong> ${escapeHtml(issuer.rtn)}</div>`:"")+
          (issuerAddress?`<div>${escapeHtml(issuerAddress)}</div>`:"")+
          (issuer.phone?`<div>Tel: ${escapeHtml(issuer.phone)}</div>`:"")+
          (issuer.email?`<div>Email: ${escapeHtml(issuer.email)}</div>`:"")+
          `<div style="font-size:11.5px;font-weight:800;margin-top:5px;">FACTURA</div>`+
          (isVoided?`<div style="font-size:12px;font-weight:800;border:2px solid #000;padding:2px 5px;margin:3px auto;display:inline-block;">ANULADA</div>`:"")+
          `<div style="font-size:11px;font-weight:800;">${escapeHtml(fiscal.fiscal_number)}</div>`+
          (fiscal.cai?`<div style="margin-top:3px;"><strong>CAI:</strong> ${escapeHtml(fiscal.cai)}</div>`:"")+
          ((rangeStart||rangeEnd)?`<div><strong>Rango autorizado:</strong><br>${escapeHtml(rangeStart)}<br>al<br>${escapeHtml(rangeEnd)}</div>`:"")+
          (fiscal.deadline?`<div style="margin-top:2px;"><strong>Fecha de vencimiento del CAI / límite de emisión:</strong> ${escapeHtml(fiscal.deadline)}</div>`:"")+
          `<div style="margin-top:4px;"><strong>Cliente:</strong> ${escapeHtml(customer.name||"Consumidor final")}</div>`+
          (customer.rtn?`<div><strong>RTN cliente:</strong> ${escapeHtml(customer.rtn)}</div>`:"")+
          (fiscal.total_in_words?`<div style="margin-top:4px;font-weight:600;">${escapeHtml(fiscal.total_in_words)}</div>`:"")+
          (isVoided&&fiscal.void_reason?`<div style="margin-top:3px;"><strong>Motivo:</strong> ${escapeHtml(fiscal.void_reason)}</div>`:"");

        const container=root.firstElementChild||root;
        container.insertBefore(block,container.firstChild);
      } catch(e) {}
    },
  },

  mounted() {
    this._posShortcutsHandler=null;
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
      if(typeof axios!=="undefined"&&axios.interceptors&&axios.interceptors.response){
        this._sarReceiptInterceptor=axios.interceptors.response.use(response=>{
          try{
            const url=response&&response.config?String(response.config.url||""):"";
            const data=response&&response.data?response.data:null;
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
    }catch(e){this._sarReceiptInterceptor=null;}
  },

  beforeDestroy() {
    try{if(this._posShortcutsHandler){window.removeEventListener("keydown",this._posShortcutsHandler,true);this._posShortcutsHandler=null;}}catch(e){}
    try{if(this._sarReceiptInterceptor!==null&&this._sarReceiptInterceptor!==undefined&&typeof axios!=="undefined"){axios.interceptors.response.eject(this._sarReceiptInterceptor);this._sarReceiptInterceptor=null;}}catch(e){}
  },
};
