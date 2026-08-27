/**
 * POS keyboard shortcuts + Honduras fiscal checkout helpers.
 * Receipt rendering itself is global (utils/sarInvoiceBridge.js), so POS and
 * Sales reprints always use the exact same fiscal renderer.
 */

export const POS_SHORTCUTS_STORAGE_KEY = "pos_keyboard_shortcuts_enabled";
const POS_AUX_STYLE_ID = "prodex-pos-aux-modal-styles";

export function posShortcutsEnabled() {
  try {
    const v = localStorage.getItem(POS_SHORTCUTS_STORAGE_KEY);
    return v === null ? true : v === "1";
  } catch (e) { return true; }
}

export function setPosShortcutsEnabled(value) {
  try { localStorage.setItem(POS_SHORTCUTS_STORAGE_KEY, value ? "1" : "0"); } catch (e) {}
}

export const POS_SHORTCUTS = [
  { id:"search", keys:"F2", descriptionKey:"Shortcut_Focus_Search", descriptionFallback:"Enfocar búsqueda de productos", match:e=>e.key==="F2", action:vm=>{ const el=document.querySelector(".pos-shell-search-input")||document.querySelector(".search-input"); if(el&&el.focus){el.focus();if(el.select)el.select();} } },
  { id:"payment", keys:"F4", descriptionKey:"Shortcut_Open_Payment", descriptionFallback:"Abrir ventana de pago", match:e=>e.key==="F4", action:vm=>{ if(vm.openModernPaymentModal&&vm.details&&vm.details.length)vm.openModernPaymentModal(); } },
  { id:"hold", keys:"F6", descriptionKey:"Shortcut_Hold_Sale", descriptionFallback:"Poner venta en espera (borrador)", match:e=>e.key==="F6", action:vm=>{ if(vm.Submit_Draft)vm.Submit_Draft(); } },
  { id:"recall", keys:"F7", descriptionKey:"Shortcut_Recall_Sale", descriptionFallback:"Recuperar ventas en espera", match:e=>e.key==="F7", action:vm=>{ if(vm.loadDraftSale)vm.loadDraftSale(); } },
  { id:"customer", keys:"F8", descriptionKey:"Shortcut_Quick_Customer", descriptionFallback:"Agregar cliente rápidamente", match:e=>e.key==="F8", action:vm=>{ if(vm.Quick_Add_Client)vm.Quick_Add_Client(); } },
  { id:"print", keys:"F9", descriptionKey:"Shortcut_Print_Receipt", descriptionFallback:"Imprimir último recibo", match:e=>e.key==="F9", action:vm=>{ if(vm.print_last_receipt)vm.print_last_receipt();else if(vm.print_pos)vm.print_pos(); } },
  { id:"clear", keys:"Esc", descriptionKey:"Shortcut_Clear_Cart", descriptionFallback:"Vaciar carrito (con confirmación)", match:e=>e.key==="Escape", action:vm=>{ if(!vm.details||!vm.details.length)return;if(vm.confirmClearCart)vm.confirmClearCart();else if(vm.Reset_Pos)vm.Reset_Pos(); } },
  { id:"inc", keys:"Ctrl + ArrowUp", descriptionKey:"Shortcut_Increase_Last", descriptionFallback:"Aumentar cantidad del último artículo", match:e=>e.ctrlKey&&e.key==="ArrowUp", action:vm=>{ const last=vm.details&&vm.details[vm.details.length-1];if(last&&vm.increment)vm.increment(last.detail_id); } },
  { id:"dec", keys:"Ctrl + ArrowDown", descriptionKey:"Shortcut_Decrease_Last", descriptionFallback:"Disminuir cantidad del último artículo", match:e=>e.ctrlKey&&e.key==="ArrowDown", action:vm=>{ const last=vm.details&&vm.details[vm.details.length-1];if(last&&vm.decrement)vm.decrement(last,last.detail_id); } },
  { id:"remove", keys:"Ctrl + Delete", descriptionKey:"Shortcut_Remove_Last", descriptionFallback:"Eliminar el último artículo del carrito", match:e=>e.ctrlKey&&e.key==="Delete", action:vm=>{ const last=vm.details&&vm.details[vm.details.length-1];if(last&&vm.delete_Product_Detail)vm.delete_Product_Detail(last.detail_id); } },
  { id:"help", keys:"Shift + ?", descriptionKey:"Shortcut_Show_Help", descriptionFallback:"Mostrar ayuda de atajos", match:e=>e.shiftKey&&(e.key==="?"||e.key==="/"), action:vm=>{ if(vm.$bvModal&&vm.$bvModal.show)vm.$bvModal.show("pos-keyboard-shortcuts-help"); } },
];

function isTypingTarget(target) {
  if (!target) return false;
  const tag=(target.tagName||"").toUpperCase();
  return tag==="INPUT"||tag==="TEXTAREA"||tag==="SELECT"||target.isContentEditable;
}

function escapeHtml(value) {
  return String(value==null?"":value).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/'/g,"&#039;");
}

function formValue(id) {
  try { const el=document.getElementById(id); return el ? String(el.value||"").trim() : ""; } catch(e){ return ""; }
}

function ensurePosAuxiliaryStyles() {
  if (typeof document === "undefined" || document.getElementById(POS_AUX_STYLE_ID)) return;
  const style=document.createElement("style");
  style.id=POS_AUX_STYLE_ID;
  style.textContent=`
    #OpenRegisterModal___BV_modal_content,
    #CloseRegisterModal___BV_modal_content,
    #Quick_Add_Customer___BV_modal_content {
      border: 0 !important;
      border-radius: 14px !important;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(31,31,44,.16) !important;
      font-family: Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      color: #1f1f2c;
    }
    #OpenRegisterModal___BV_modal_header,
    #CloseRegisterModal___BV_modal_header,
    #Quick_Add_Customer___BV_modal_header {
      padding: 18px 20px 14px !important;
      border-bottom: 1px solid #ececf2 !important;
      background: #fff !important;
      align-items: center !important;
    }
    #OpenRegisterModal___BV_modal_title,
    #CloseRegisterModal___BV_modal_title,
    #Quick_Add_Customer___BV_modal_title {
      margin: 0 !important;
      color: #1f1f2c !important;
      font-size: 16px !important;
      line-height: 1.3 !important;
      font-weight: 700 !important;
      letter-spacing: -.01em;
    }
    #OpenRegisterModal___BV_modal_header .close,
    #CloseRegisterModal___BV_modal_header .close,
    #Quick_Add_Customer___BV_modal_header .close {
      width: 32px;
      height: 32px;
      padding: 0 !important;
      margin: -4px -4px -4px auto !important;
      border-radius: 8px;
      color: #6b6b7d;
      opacity: 1;
      font-size: 22px;
      font-weight: 400;
      line-height: 30px;
      transition: background .12s ease,color .12s ease;
    }
    #OpenRegisterModal___BV_modal_header .close:hover,
    #CloseRegisterModal___BV_modal_header .close:hover,
    #Quick_Add_Customer___BV_modal_header .close:hover {
      background: #f5f3fd;
      color: #6f53d9;
    }
    #OpenRegisterModal___BV_modal_body,
    #CloseRegisterModal___BV_modal_body,
    #Quick_Add_Customer___BV_modal_body {
      padding: 18px 20px 20px !important;
      background: #fff !important;
    }
    #OpenRegisterModal .form-group,
    #CloseRegisterModal .form-group,
    #Quick_Add_Customer .form-group {
      margin-bottom: 14px !important;
    }
    #OpenRegisterModal label,
    #CloseRegisterModal label,
    #Quick_Add_Customer label,
    .prodex-sar-popup label {
      display: block;
      margin: 0 0 6px !important;
      color: #54546a !important;
      font-size: 11px !important;
      line-height: 1.35;
      font-weight: 700 !important;
      letter-spacing: .02em;
      text-transform: none;
    }
    #OpenRegisterModal .form-control,
    #OpenRegisterModal .custom-select,
    #CloseRegisterModal .form-control,
    #CloseRegisterModal .custom-select,
    #Quick_Add_Customer .form-control,
    #Quick_Add_Customer .custom-select,
    #Quick_Add_Customer .vs__dropdown-toggle,
    .prodex-sar-popup .swal2-input {
      min-height: 40px !important;
      margin: 0 !important;
      border: 1px solid #dedee8 !important;
      border-radius: 9px !important;
      background: #fff !important;
      color: #1f1f2c !important;
      font-family: inherit !important;
      font-size: 13px !important;
      box-shadow: none !important;
      transition: border-color .12s ease,box-shadow .12s ease,background .12s ease;
    }
    #OpenRegisterModal textarea.form-control,
    #CloseRegisterModal textarea.form-control,
    #Quick_Add_Customer textarea.form-control {
      min-height: 82px !important;
      resize: vertical;
    }
    #OpenRegisterModal .form-control:focus,
    #OpenRegisterModal .custom-select:focus,
    #CloseRegisterModal .form-control:focus,
    #CloseRegisterModal .custom-select:focus,
    #Quick_Add_Customer .form-control:focus,
    #Quick_Add_Customer .custom-select:focus,
    #Quick_Add_Customer .vs__dropdown-toggle:focus-within,
    .prodex-sar-popup .swal2-input:focus {
      border-color: #8b73e7 !important;
      box-shadow: 0 0 0 3px rgba(111,83,217,.10) !important;
      outline: 0 !important;
    }
    #OpenRegisterModal .text-right,
    #CloseRegisterModal .text-right,
    #Quick_Add_Customer .mt-3.col-md-12 {
      display: flex !important;
      justify-content: flex-end !important;
      align-items: center;
      gap: 8px;
      padding-top: 4px;
    }
    #OpenRegisterModal .btn,
    #CloseRegisterModal .btn,
    #Quick_Add_Customer .btn,
    .prodex-sar-popup .swal2-actions .swal2-styled {
      min-height: 38px !important;
      margin: 0 !important;
      padding: 8px 14px !important;
      border: 1px solid transparent !important;
      border-radius: 9px !important;
      box-shadow: none !important;
      font-family: inherit !important;
      font-size: 12px !important;
      line-height: 1.2 !important;
      font-weight: 700 !important;
      transition: transform .12s ease,background .12s ease,border-color .12s ease;
    }
    #OpenRegisterModal .btn-secondary,
    #CloseRegisterModal .btn-secondary,
    #Quick_Add_Customer .btn-secondary,
    .prodex-sar-popup .swal2-cancel {
      border-color: #dedee8 !important;
      background: #fff !important;
      color: #54546a !important;
    }
    #OpenRegisterModal .btn-success,
    #OpenRegisterModal .btn-primary,
    #CloseRegisterModal .btn-success,
    #CloseRegisterModal .btn-primary,
    #Quick_Add_Customer .btn-primary,
    .prodex-sar-popup .swal2-confirm {
      border-color: #6f53d9 !important;
      background: #6f53d9 !important;
      color: #fff !important;
    }
    .prodex-sar-popup .swal2-deny {
      border-color: #f3cccc !important;
      background: #fff5f5 !important;
      color: #a83232 !important;
    }
    #OpenRegisterModal .btn:hover:not(:disabled),
    #CloseRegisterModal .btn:hover:not(:disabled),
    #Quick_Add_Customer .btn:hover:not(:disabled),
    .prodex-sar-popup .swal2-actions .swal2-styled:hover {
      transform: translateY(-1px);
    }
    #OpenRegisterModal .btn:disabled,
    #CloseRegisterModal .btn:disabled,
    #Quick_Add_Customer .btn:disabled {
      opacity: .55;
      cursor: not-allowed;
    }
    #prodex-sar-sale-data-btn {
      height: 32px;
      padding: 0 10px;
      border: 1px solid #e6e6ec;
      border-radius: 8px;
      background: #fff;
      color: #54546a;
      font: 600 11px/1 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      cursor: pointer;
      white-space: nowrap;
      transition: border-color .12s ease,background .12s ease,color .12s ease,transform .12s ease;
    }
    #prodex-sar-sale-data-btn:hover {
      border-color: #cfc8ee;
      background: #f7f5fe;
      color: #6f53d9;
      transform: translateY(-1px);
    }
    #prodex-sar-sale-data-btn.is-configured {
      border-color: #b9ddc6;
      background: #f1faf4;
      color: #267447;
    }
    .prodex-sar-popup {
      width: min(520px, calc(100vw - 32px)) !important;
      padding: 0 0 18px !important;
      border-radius: 14px !important;
      overflow: hidden !important;
      box-shadow: 0 20px 50px rgba(31,31,44,.16) !important;
      font-family: Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif !important;
    }
    .prodex-sar-popup .swal2-title {
      margin: 0 !important;
      padding: 18px 20px 14px !important;
      border-bottom: 1px solid #ececf2;
      color: #1f1f2c !important;
      font-size: 16px !important;
      line-height: 1.3 !important;
      font-weight: 700 !important;
      text-align: left !important;
    }
    .prodex-sar-popup .swal2-html-container {
      margin: 0 !important;
      padding: 18px 20px 0 !important;
      overflow: visible !important;
      color: #54546a !important;
      font-size: 12px !important;
      text-align: left !important;
    }
    .prodex-sar-popup .swal2-input {
      width: 100% !important;
      padding: 8px 10px !important;
    }
    .prodex-sar-popup .prodex-sar-field + .prodex-sar-field { margin-top: 13px; }
    .prodex-sar-popup .prodex-sar-help {
      display: block;
      margin-top: 14px;
      padding: 10px 11px;
      border-radius: 8px;
      background: #f7f7fb;
      color: #6b6b7d;
      line-height: 1.45;
    }
    .prodex-sar-popup .swal2-actions {
      width: auto !important;
      margin: 18px 20px 0 !important;
      padding-top: 14px;
      border-top: 1px solid #ececf2;
      justify-content: flex-end !important;
      gap: 8px;
    }
    @media (max-width: 575px) {
      #OpenRegisterModal___BV_modal_outer_ .modal-dialog,
      #CloseRegisterModal___BV_modal_outer_ .modal-dialog,
      #Quick_Add_Customer___BV_modal_outer_ .modal-dialog { margin: 12px !important; }
      #OpenRegisterModal___BV_modal_body,
      #CloseRegisterModal___BV_modal_body,
      #Quick_Add_Customer___BV_modal_body { padding: 16px !important; }
      .prodex-sar-popup .swal2-title { padding: 16px !important; }
      .prodex-sar-popup .swal2-html-container { padding: 16px 16px 0 !important; }
      .prodex-sar-popup .swal2-actions { margin: 16px 16px 0 !important; }
    }
  `;
  document.head.appendChild(style);
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
      const value=Math.max(0,Number((this.sale&&this.sale.discount)||0));
      let discount=method==="1"?gross*(value/100):Math.min(value,gross);
      const afterManual=Math.max(gross-discount,0);
      const points=Math.min(Math.max(0,Number(this.discount_from_points||0)),afterManual);
      discount+=points;
      const promo=Math.min(Math.max(0,Number(this.promotionDiscount||0)),Math.max(gross-discount,0));
      const afterDiscount=Math.max(gross-discount-promo,0);
      const ratio=gross>0?afterDiscount/gross:0;

      if(this.sale){
        // Tax is already inside each product subtotal. Keep the effective amount
        // only for reporting/backward compatibility; never add a second 15%.
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
        button.classList.toggle("is-configured",active);
      } catch(e) {}
    },

    ensureSarSaleButton() {
      try {
        if(typeof document==="undefined"||!this.isHondurasLineTaxMode())return;
        ensurePosAuxiliaryStyles();
        if(document.getElementById("prodex-sar-sale-data-btn")){this.updateSarSaleButton();return;}
        const anchor=document.querySelector(".pos-cust-trigger")||document.querySelector(".pos-shell-register-status")||document.querySelector(".pos-shell-header");
        if(!anchor||!anchor.parentNode)return;
        const button=document.createElement("button");
        button.id="prodex-sar-sale-data-btn";
        button.type="button";
        button.textContent="Datos fiscales";
        button.title="Datos fiscales o de exoneración de esta venta";
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
      ensurePosAuxiliaryStyles();

      this.$swal({
        title:"Datos fiscales de esta venta",
        customClass:{ popup:"prodex-sar-popup" },
        buttonsStyling:false,
        html:
          '<div class="prodex-sar-fields">'+
          '<div class="prodex-sar-field"><label for="sar-sale-exempt-po">No. orden de compra exenta</label><input id="sar-sale-exempt-po" class="swal2-input" value="'+escapeHtml(current.exempt_purchase_order_number)+'"></div>'+
          '<div class="prodex-sar-field"><label for="sar-sale-registry">No. registro SAG/SAR</label><input id="sar-sale-registry" class="swal2-input" value="'+escapeHtml(current.sar_registry_number)+'"></div>'+
          '<div class="prodex-sar-field"><label for="sar-sale-exoneration">No. registro exonerado</label><input id="sar-sale-exoneration" class="swal2-input" value="'+escapeHtml(current.exoneration_registry_number)+'"></div>'+
          '<div class="prodex-sar-field"><label for="sar-sale-card">No. carnet/documento exonerado</label><input id="sar-sale-card" class="swal2-input" value="'+escapeHtml(current.exonerated_card_number)+'"></div>'+
          '<small class="prodex-sar-help">Déjalos en blanco cuando la operación no sea exenta o exonerada. Estos datos quedan asociados a la factura emitida.</small></div>',
        showCancelButton:true,
        showDenyButton:this.hasSarSaleData(),
        confirmButtonText:"Guardar",
        cancelButtonText:"Cancelar",
        denyButtonText:"Limpiar",
        focusConfirm:false,
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
  },

  mounted() {
    ensurePosAuxiliaryStyles();
    if(this.sarFiscalSaleData===undefined)this.$set(this,"sarFiscalSaleData",{});

    this._posShortcutsHandler=e=>{
      if(!posShortcutsEnabled())return;
      try{if(document.body&&document.body.classList.contains("modal-open"))return;}catch(e2){}
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
    try{window.addEventListener("keydown",this._posShortcutsHandler,true);}catch(e){}

    try{
      if(typeof this.CalculTotal==="function"){
        this._originalCalculTotal=this.CalculTotal.bind(this);
        this.CalculTotal=(...args)=>this.isHondurasLineTaxMode()?this.calculateHondurasLineTotals():this._originalCalculTotal(...args);
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

        this._sarResponseInterceptor=axios.interceptors.response.use(response=>{
          try{
            const url=response&&response.config?String(response.config.url||""):"";
            if(url.indexOf("pos/create_pos")!==-1&&response.data&&response.data.success===true){
              this.$set(this,"sarFiscalSaleData",{});
              this.updateSarSaleButton();
            }
          }catch(e){}
          return response;
        },error=>Promise.reject(error));
      }
    }catch(e){this._sarRequestInterceptor=null;this._sarResponseInterceptor=null;}
  },

  beforeDestroy() {
    try{if(this._posShortcutsHandler)window.removeEventListener("keydown",this._posShortcutsHandler,true);}catch(e){}
    try{if(this._sarUiTimer)clearInterval(this._sarUiTimer);}catch(e){}
    try{const b=document.getElementById("prodex-sar-sale-data-btn");if(b&&b.parentNode)b.parentNode.removeChild(b);}catch(e){}
    try{if(this._sarRequestInterceptor!==null&&this._sarRequestInterceptor!==undefined)axios.interceptors.request.eject(this._sarRequestInterceptor);}catch(e){}
    try{if(this._sarResponseInterceptor!==null&&this._sarResponseInterceptor!==undefined)axios.interceptors.response.eject(this._sarResponseInterceptor);}catch(e){}
  },
};
