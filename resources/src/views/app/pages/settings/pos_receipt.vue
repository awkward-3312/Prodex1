<template>
  <div class="px-next pxcfg">
    <px-page-header
      :title="$t('POS_Receipt')"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: $t('POS_Receipt') }]"
    />
    <div v-if="isLoading" class="pxcfg__pad"><px-skeleton variant="lines" :rows="10" /></div>

    <validation-observer ref="Submit_Pos_Settings" v-if="!isLoading">
      <form @submit.prevent="Submit_Pos_Settings">
        <px-card :title="$t('POS_Receipt')" class="pxcfg__card">
          <px-alert tone="info" class="pxcfg__alert">
            POS receipt configuration – choose a layout and toggle what appears on the printed receipt.
          </px-alert>

          <div class="pxcfg__grid">
            <px-field label="POS receipt layout">
              <template #default>
                <div class="pxcfg__seg pxcfg__seg--wrap">
                  <px-button
                    v-for="opt in [
                      { value: 1, text: 'Layout 1 - Standard' },
                      { value: 2, text: 'Layout 2 - Compact' },
                      { value: 3, text: 'Layout 3 - Detailed' },
                      { value: 4, text: 'Layout 4 - Bilingual (AR+EN)' },
                      { value: 5, text: 'Layout 5 - Minimal' }
                    ]"
                    :key="opt.value" size="sm"
                    :variant="Number(pos_settings.receipt_layout) === opt.value ? 'primary' : 'subtle'"
                    @click="pos_settings.receipt_layout = opt.value">{{ opt.text }}</px-button>
                </div>
              </template>
            </px-field>
            <px-field :label="$t('POS_receipt_layout_default')">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="pos_settings.receipt_layout" :reduce="o => o.value" :clearable="false"
                  :options="[
                    { label: $t('Layout_1_Standard'), value: 1 },
                    { label: $t('Layout_2_Compact'), value: 2 },
                    { label: $t('Layout_3_Detailed'), value: 3 },
                    { label: $t('Layout_4_Bilingual'), value: 4 },
                    { label: $t('Layout_5_Minimal'), value: 5 }
                  ]" />
              </template>
            </px-field>
          </div>

          <div class="pxcfg__preview">
            <div class="pxcfg__preview-head">
              <h4 class="pxcfg__subhead">Receipt preview</h4>
              <px-button size="sm" variant="secondary" icon="receipt" @click="printPosDemo">Print demo receipt</px-button>
            </div>
            <div class="pos-receipt-demo" id="pos-receipt-demo">
                        <!-- Layout 1 demo (Standard) -->
                        <div v-if="currentReceiptLayout === 1" class="receipt-layout-1">
                          <div class="info text-center mb-2">
                            <div class="invoice_logo mb-1" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle">LOGO</div>
                            </div>
                            <div v-show="pos_settings.show_store_name !== 0">Demo Store</div>
                            <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                            <small v-show="pos_settings.show_address">123 Demo Street</small><br v-show="pos_settings.show_address">
                            <small v-show="pos_settings.show_phone">+123 456 789</small><br v-show="pos_settings.show_phone">
                            <small v-show="pos_settings.show_email">demo@example.com</small>
                            <div class="mt-2">
                              <small v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</small><br>
                              <small v-show="pos_settings.show_seller !== 0">Seller: John Doe</small><br>
                              <small v-show="pos_settings.show_customer">Customer: Jane Smith</small><br>
                              <small v-show="pos_settings.show_Warehouse">Warehouse: Main Store</small>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td colspan="3">
                                  Demo Product A<br>
                                  <small>2 x 10.00</small>
                                  <br v-show="pos_settings.show_product_discount !== 0">
                                  <small v-show="pos_settings.show_product_discount !== 0" style="color:#888;font-style:italic;">Discount: -2.00</small>
                                </td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr>
                                <td colspan="3">
                                  Demo Product B<br>
                                  <small>1 x 5.00</small>
                                </td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">20.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <barcode
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                            ></barcode>
                          </div>
                        </div>

                        <!-- Layout 2 demo (Compact) -->
                        <div v-else-if="currentReceiptLayout === 2" class="receipt-layout-2">
                          <div class="info text-center mb-2">
                            <div class="demo-logo-circle small mb-1" v-show="pos_settings.show_logo !== 0">
                              LOGO
                            </div>
                            <div v-show="pos_settings.show_store_name !== 0">Demo Store</div>
                            <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                            <small v-show="pos_settings.show_address">123 Demo Street</small><br v-show="pos_settings.show_address">
                            <small v-show="pos_settings.show_phone">+123 456 789</small><br v-show="pos_settings.show_phone">
                            <small v-show="pos_settings.show_email">demo@example.com</small>
                            <div class="mt-1">
                              <small v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</small><br>
                              <small v-show="pos_settings.show_seller !== 0">Seller: John Doe</small><br>
                              <small v-show="pos_settings.show_customer">Customer: Jane Smith</small><br>
                              <small v-show="pos_settings.show_Warehouse">Warehouse: Main Store</small>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <thead>
                              <tr>
                                <th style="text-align:left;">Item</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Price</th>
                                <th style="text-align:right;">Total</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Demo A</td>
                                <td style="text-align:center;">2</td>
                                <td style="text-align:right;">10.00</td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_product_discount !== 0">
                                <td colspan="4" style="color:#888;font-style:italic;font-size:10px;padding-left:8px;">Discount: -2.00</td>
                              </tr>
                              <tr>
                                <td>Demo B</td>
                                <td style="text-align:center;">1</td>
                                <td style="text-align:right;">5.00</td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td class="total">Tax</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td class="total">Discount</td>
                                <td style="text-align:right;" class="total">0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td class="total">Shipping</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">20.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <barcode
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                            ></barcode>
                          </div>
                        </div>

                        <!-- Layout 3 demo (Detailed) -->
                        <div v-else-if="currentReceiptLayout === 3" class="receipt-layout-3">
                          <div class="info mb-2">
                            <div class="d-flex justify-content-between">
                              <div>
                                <strong v-show="pos_settings.show_store_name !== 0">Demo Store</strong><br>
                                <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                                <small v-show="pos_settings.show_address">123 Demo Street</small><br>
                                <small v-show="pos_settings.show_phone">+123 456 789</small>
                              </div>
                              <div class="demo-logo-rect" v-show="pos_settings.show_logo !== 0">LOGO</div>
                            </div>
                            <div class="mt-2" style="font-size:11px;">
                              <div v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</div>
                              <div v-show="pos_settings.show_seller !== 0">Seller: John Doe</div>
                              <div v-show="pos_settings.show_customer">Customer: Jane Smith</div>
                              <div v-show="pos_settings.show_Warehouse">Warehouse: Main Store</div>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td>
                                  <strong>Demo Product A</strong><br>
                                  <small>2 x 10.00</small>
                                  <br v-show="pos_settings.show_product_discount !== 0">
                                  <small v-show="pos_settings.show_product_discount !== 0" style="color:#888;font-style:italic;">Discount: -2.00</small>
                                </td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr>
                                <td>
                                  <strong>Demo Product B</strong><br>
                                  <small>1 x 5.00</small>
                                </td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td class="total">Tax</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td class="total">Discount</td>
                                <td style="text-align:right;" class="total">0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td class="total">Shipping</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">26.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">25.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <barcode
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                            ></barcode>
                          </div>
                        </div>

                        <!-- Layout 4 demo (Bilingual AR+EN) -->
                        <div v-else-if="currentReceiptLayout === 4" class="receipt-layout-4">
                          <div class="info text-center mb-2">
                            <div class="invoice_logo mb-1" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle">LOGO</div>
                            </div>
                            <div>
                              <strong style="font-size:13px;">متجر تجريبي</strong><br>
                              <strong style="font-size:12px;">Demo Store</strong>
                            </div>
                            <div style="font-size:10px;margin-top:2px;">123 Demo Street</div>
                            <div style="font-size:10px;">+123 456 789</div>
                            <div v-show="pos_settings.show_email" style="font-size:10px;">demo@example.com</div>
                            <div v-if="setting.vat_number" style="font-size:11px;font-weight:bold;margin-top:4px;">
                              الرقم الضريبي / TRN : {{setting.vat_number}}
                            </div>
                            <div class="mt-2 mb-2" style="border-top:1px dashed #000;border-bottom:1px dashed #000;padding:4px 0;">
                              <strong>فاتورة ضريبية مبسطة</strong><br>
                              <strong>Simplified Tax Invoice</strong>
                            </div>
                          </div>
                          <div style="font-size:10px;">
                            <div v-show="pos_settings.show_reference !== 0" style="display:flex;justify-content:space-between;">
                              <span>Invoice No</span>
                              <span>REF-12345</span>
                              <span>رقم الفاتورة</span>
                            </div>
                            <div v-show="pos_settings.show_date !== 0" style="display:flex;justify-content:space-between;">
                              <span>Date</span>
                              <span>2025-12-10 12:34</span>
                              <span>تاريخ</span>
                            </div>
                            <div v-show="pos_settings.show_seller !== 0" style="display:flex;justify-content:space-between;">
                              <span>Seller</span>
                              <span>John Doe</span>
                              <span>البائع</span>
                            </div>
                            <div v-show="pos_settings.show_customer" style="display:flex;justify-content:space-between;">
                              <span>Customer</span>
                              <span>Jane Smith</span>
                              <span>العميل</span>
                            </div>
                            <div v-show="pos_settings.show_Warehouse" style="display:flex;justify-content:space-between;">
                              <span>Warehouse</span>
                              <span>Main Store</span>
                              <span>المستودع</span>
                            </div>
                          </div>
                          <table style="width:100%;margin-top:8px;font-size:10px;border-top:1px dashed #000;">
                            <thead>
                              <tr>
                                <th style="text-align:left;padding:4px 0;">Product<br>المنتج</th>
                                <th style="text-align:center;padding:4px 0;">Qty<br>كمية</th>
                                <th style="text-align:center;padding:4px 0;">Rate<br>معدل</th>
                                <th style="text-align:right;padding:4px 0;">Amount<br>مجموع</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Demo Product A</td>
                                <td style="text-align:center">2</td>
                                <td style="text-align:center">10.00</td>
                                <td style="text-align:right">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_product_discount !== 0" style="border-bottom:1px dashed #eee;">
                                <td colspan="4" style="color:#888;font-style:italic;font-size:9px;padding:0 0 2px 4px;">Discount / تخفيض: -2.00</td>
                              </tr>
                              <tr style="border-bottom:1px dashed #eee;">
                                <td>Demo Product B</td>
                                <td style="text-align:center">1</td>
                                <td style="text-align:center">5.00</td>
                                <td style="text-align:right">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;border-top:1px dashed #000;margin-top:4px;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr>
                                <td style="text-align:left" class="total">Sub Total</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">25.00</td>
                                <td style="text-align:right" class="total">المجموع الفرعي</td>
                              </tr>
                              <tr v-show="pos_settings.show_tax">
                                <td style="text-align:left" class="total">VAT @ Total</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">1.25</td>
                                <td style="text-align:right" class="total">قيمة الضريبة</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td style="text-align:left" class="total">Discount</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">0.00</td>
                                <td style="text-align:right" class="total">تخفيض</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td style="text-align:left" class="total">Shipping</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">1.25</td>
                                <td style="text-align:right" class="total">الشحن</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;font-weight:bold;border-top:1px dashed #000;border-bottom:1px dashed #000;margin-top:4px;padding:4px 0;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr>
                                <td style="text-align:left">Grand Total</td>
                                <td>:</td>
                                <td style="text-align:center">26.25</td>
                                <td style="text-align:right">المبلغ الإجمالي</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;margin-top:4px;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td style="text-align:left"><strong>Paid Amount</strong></td>
                                <td><strong>:</strong></td>
                                <td style="text-align:center">25.00</td>
                                <td style="text-align:right"><strong>المبلغ المدفوع</strong></td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td style="text-align:left"><strong>Balance</strong></td>
                                <td><strong>:</strong></td>
                                <td style="text-align:center">1.25</td>
                                <td style="text-align:right"><strong>الرصيد</strong></td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="font-size:10px;width:100%;margin-top:4px;" v-show="pos_settings.show_payments !== 0">
                            <thead>
                              <tr style="background:#eee;">
                                <th style="text-align:left;">Paid By / طريقة الدفع:</th>
                                <th style="text-align:center;">Amount / المبلغ:</th>
                                <th style="text-align:right;">Change / الباقي:</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td style="text-align:left;">Cash</td>
                                <td style="text-align:center;">25.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <barcode
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                            ></barcode>
                          </div>
                        </div>

                        <!-- Layout 5 demo (Minimal) -->
                        <div v-else class="receipt-layout-5">
                          <div class="info text-center mb-3">
                            <div class="invoice_logo mb-2" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle small">LOGO</div>
                            </div>
                            <div class="minimal-store-name" v-show="pos_settings.show_store_name !== 0">DEMO STORE</div>
                            <div class="minimal-contact" v-show="pos_settings.show_address || pos_settings.show_phone">
                              <span v-show="pos_settings.show_address">123 Demo Street</span>
                              <span v-show="pos_settings.show_address && pos_settings.show_phone"> &middot; </span>
                              <span v-show="pos_settings.show_phone">+123 456 789</span>
                            </div>
                            <div class="minimal-contact" v-show="pos_settings.show_email">demo@example.com</div>
                          </div>

                          <div class="minimal-divider"></div>

                          <div class="minimal-meta">
                            <div v-show="pos_settings.show_reference !== 0" class="minimal-meta-row">
                              <span>Ref</span><span>REF-12345</span>
                            </div>
                            <div v-show="pos_settings.show_date !== 0" class="minimal-meta-row">
                              <span>Date</span><span>2025-12-10 12:34</span>
                            </div>
                            <div v-show="pos_settings.show_seller !== 0" class="minimal-meta-row">
                              <span>Seller</span><span>John Doe</span>
                            </div>
                            <div v-show="pos_settings.show_customer" class="minimal-meta-row">
                              <span>Customer</span><span>Jane Smith</span>
                            </div>
                            <div v-show="pos_settings.show_Warehouse" class="minimal-meta-row">
                              <span>Warehouse</span><span>Main Store</span>
                            </div>
                          </div>

                          <div class="minimal-divider"></div>

                          <table class="minimal-items">
                            <tbody>
                              <tr>
                                <td>
                                  <div class="minimal-item-name">Demo Product A</div>
                                  <div class="minimal-item-qty">2 &times; 10.00</div>
                                  <div class="minimal-item-discount" v-show="pos_settings.show_product_discount !== 0">Discount &minus;2.00</div>
                                </td>
                                <td class="minimal-item-total">20.00</td>
                              </tr>
                              <tr>
                                <td>
                                  <div class="minimal-item-name">Demo Product B</div>
                                  <div class="minimal-item-qty">1 &times; 5.00</div>
                                </td>
                                <td class="minimal-item-total">5.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <div class="minimal-divider"></div>

                          <table class="minimal-totals">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td>Tax</td>
                                <td>1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td>Discount</td>
                                <td>0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td>Shipping</td>
                                <td>1.25</td>
                              </tr>
                              <tr class="minimal-grand">
                                <td>Total</td>
                                <td>25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td>Paid</td>
                                <td>20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td>Due</td>
                                <td>5.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <table class="minimal-payments" v-show="pos_settings.show_payments !== 0">
                            <thead>
                              <tr>
                                <th>Pay By</th>
                                <th>Amount</th>
                                <th>Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td>20.00</td>
                                <td>0.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <p class="minimal-note" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            {{ pos_settings.note_customer || 'Thank you for your purchase!' }}
                          </p>

                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>

                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <barcode
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                            ></barcode>
                          </div>
                        </div>
                      </div>
          </div>

          <validation-provider ref="noteProvider" name="note" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Note_to_customer') + ' *'" class="pxcfg__mt" :error="v.errors[0]">
              <template #default="{ id, invalid }">
                <px-textarea :id="id" v-model="pos_settings.note_customer" :rows="4" :placeholder="$t('Note_to_customer')" :invalid="invalid" @input="v.validate" />
              </template>
            </px-field>
          </validation-provider>

          <h4 class="pxcfg__subhead">Elementos del recibo</h4>
          <div class="pxcfg__toggles">
            <div v-for="tg in receiptToggles" :key="tg.key" class="pxcfg__toggle">
              <div class="pxcfg__toggle-title">{{ $t(tg.label) }}</div>
              <px-check type="switch" :modelValue="Number(pos_settings[tg.key]) === 1" @change="v => pos_settings[tg.key] = v ? 1 : 0" />
            </div>
          </div>

          <h4 class="pxcfg__subhead">{{ $t('Receipt_Settings') }}</h4>
          <div class="pxcfg__grid">
            <px-field :label="$t('Receipt_Paper_Size')">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="pos_settings.receipt_paper_size" :reduce="o => o.value" :clearable="false"
                  :options="[
                    { label: $t('Paper_58mm'), value: 58 },
                    { label: $t('Paper_80mm'), value: 80 },
                    { label: $t('Paper_88mm'), value: 88 }
                  ]" />
              </template>
            </px-field>
            <px-field :label="$t('Logo_Size')">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="logoSizeType" :reduce="o => o.value" :clearable="false"
                  :options="[
                    { label: $t('Small') + ' (40px)', value: 'small' },
                    { label: $t('Medium') + ' (60px)', value: 'medium' },
                    { label: $t('Large') + ' (80px)', value: 'large' },
                    { label: $t('Custom'), value: 'custom' }
                  ]" />
              </template>
            </px-field>
            <px-field v-if="logoSizeType === 'custom'" :label="$t('Custom_Logo_Size') + ' (px)'" :hint="$t('Logo_Size_Description')">
              <template #default="{ id }">
                <px-input :id="id" type="number" v-model="pos_settings.logo_size" placeholder="Enter size in pixels" min="20" max="200" />
              </template>
            </px-field>
          </div>

          <template #footer>
            <px-button variant="primary" icon="check" type="submit" @click="Submit_Pos_Settings">{{ $t('submit') }}</px-button>
          </template>
        </px-card>
      </form>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import VueBarcode from "vue-barcode";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  components: {
    barcode: VueBarcode,
    PxPageHeader, PxButton, PxCard, PxField, PxInput, PxTextarea, PxCheck, PxAlert, "vs-px": VsPx,
  },
  metaInfo: {
    title: "POS Receipt"
  },
  data() {
    return {
      isLoading: true,
      logoSizeType: 'medium', // Track the selected logo size type
      receiptToggles: [
        { key: 'show_logo', label: 'Show_Logo' },
        { key: 'show_store_name', label: 'Show_Store_Name' },
        { key: 'show_reference', label: 'Show_Reference' },
        { key: 'show_date', label: 'Show_Date' },
        { key: 'show_seller', label: 'Show_Seller' },
        { key: 'show_phone', label: 'Show_Phone' },
        { key: 'show_address', label: 'Show_Address' },
        { key: 'show_email', label: 'Show_Email' },
        { key: 'show_customer', label: 'Show_Customer' },
        { key: 'show_Warehouse', label: 'Show_Warehouse' },
        { key: 'show_tax', label: 'Show_Tax' },
        { key: 'show_discount', label: 'Show_Discount' },
        { key: 'show_product_discount', label: 'Show_Product_Discount' },
        { key: 'show_shipping', label: 'Show_Shipping' },
        { key: 'show_barcode', label: 'Show_barcode' },
        { key: 'show_note', label: 'Show_Note_to_customer' },
        { key: 'show_paid', label: 'Show_Paid_Line' },
        { key: 'show_due', label: 'Show_Due_Line' },
        { key: 'show_payments', label: 'Show_Payments_Table' },
        { key: 'show_zatca_qr', label: 'Show_ZATCA_QR' },
      ],
      setting: {
        vat_number: '',
      },
      pos_settings: {
        note_customer: "",
        show_logo: "",
        logo_size: 60,
        show_store_name: "",
        show_reference: "",
        show_date: "",
        show_seller: "",
        show_note: "",
        show_barcode: "",
        show_discount: "",
        show_product_discount: 1,
        show_tax: "",
        show_shipping: "",
        show_phone: "",
        show_email: "",
        show_address: "",
        show_customer: "",
        show_Warehouse: "",
        is_printable: "",
        products_per_page: "",
        receipt_layout: 1,
        receipt_paper_size: 80,
        show_paid: "",
        show_due: "",
        show_payments: "",
        show_zatca_qr: "",
        cash_drawer_auto_open: false,
        cash_drawer_printer_name: "",
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    // Normalize POS receipt layout selection (1, 2, 3, 4, or 5) for demo preview
    currentReceiptLayout() {
      const raw = this.pos_settings && this.pos_settings.receipt_layout != null
        ? this.pos_settings.receipt_layout
        : 1;
      const n = Number(raw) || 1;
      return [1, 2, 3, 4, 5].includes(n) ? n : 1;
    },
  },

  watch: {
    logoSizeType(newVal) {
      // Watch for changes to logoSizeType and update logo_size accordingly
      this.onLogoSizeTypeChange(newVal);
    }
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    // Handle logo size type change
    onLogoSizeTypeChange(value) {
      // value is already set to logoSizeType via v-model, but we use it to update logo_size
      // Update logo_size based on the selected type
      if (!this.pos_settings) return;
      const selectedValue = value || this.logoSizeType;
      if (selectedValue === 'small') {
        this.pos_settings.logo_size = 40;
      } else if (selectedValue === 'medium') {
        this.pos_settings.logo_size = 60;
      } else if (selectedValue === 'large') {
        this.pos_settings.logo_size = 80;
      }
      // If 'custom', don't change logo_size, let user input handle it
      // But ensure logo_size has a valid value if it's empty
      if (selectedValue === 'custom' && (!this.pos_settings.logo_size || this.pos_settings.logo_size === '')) {
        this.pos_settings.logo_size = 60; // Default to 60 if empty
      }
    },

    //------------- Submit Validation Pos Setting
    Submit_Pos_Settings() {
      this.$refs.Submit_Pos_Settings.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Pos_Settings();
        }
      });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //---------------------------------- Update_Pos_Settings ----------------\\
    Update_Pos_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .put("pos_settings/" + this.pos_settings.id, {
          note_customer: this.pos_settings.note_customer,
          show_logo: this.pos_settings.show_logo,
          logo_size: this.pos_settings.logo_size,
          show_store_name: this.pos_settings.show_store_name,
          show_reference: this.pos_settings.show_reference,
          show_date: this.pos_settings.show_date,
          show_seller: this.pos_settings.show_seller,
          show_note: this.pos_settings.show_note,
          show_barcode: this.pos_settings.show_barcode,
          show_discount: this.pos_settings.show_discount,
          show_product_discount: this.pos_settings.show_product_discount,
          show_tax: this.pos_settings.show_tax,
          show_shipping: this.pos_settings.show_shipping,
          show_phone: this.pos_settings.show_phone,
          show_email: this.pos_settings.show_email,
          show_address: this.pos_settings.show_address,
          show_customer: this.pos_settings.show_customer,
          show_Warehouse: this.pos_settings.show_Warehouse,
          is_printable: this.pos_settings.is_printable,
          receipt_paper_size: this.pos_settings.receipt_paper_size,
          show_paid: this.pos_settings.show_paid,
          show_due: this.pos_settings.show_due,
          show_payments: this.pos_settings.show_payments,
          show_zatca_qr: this.pos_settings.show_zatca_qr,
          receipt_layout: this.pos_settings.receipt_layout,
        })
        .then(response => {
          Fire.$emit("Event_Pos_Settings");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    // Print the live POS receipt demo using the same print CSS as real POS receipts
    printPosDemo() {
      try {
        const el = document.getElementById("pos-receipt-demo");
        if (!el) return;
        const divContents = el.innerHTML;
        const w = window.open("", "", "height=600,width=400");
        w.document.write('<html><head>');
        w.document.write('<link rel="stylesheet" href="/css/pos_print.css">');
        w.document.write("</head><body>");
        w.document.write(divContents);
        w.document.write("</body></html>");
        w.document.close();
        setTimeout(() => {
          w.print();
        }, 500);
      } catch (e) {
        // silently ignore print errors in settings preview
      }
    },

    //---------------------------------- Get_Vat_Number (from ZATCA tab) ----------------\\
    get_vat_number() {
      axios
        .get("get_Settings_data_api")
        .then(response => {
          if (response.data && response.data.settings) {
            this.setting.vat_number = response.data.settings.vat_number || '';
          }
        })
        .catch(() => {});
    },

    //---------------------------------- Get_pos_Settings ----------------\\
    get_pos_Settings() {
      axios
        .get("get_pos_Settings")
        .then(response => {
          this.pos_settings = {
            ...this.pos_settings,
            ...(response.data && response.data.pos_settings ? response.data.pos_settings : {}),
          };
          // Ensure logo_size has a default value if not present
          if (this.pos_settings.logo_size === undefined || this.pos_settings.logo_size === null || this.pos_settings.logo_size === '') {
            this.pos_settings.logo_size = 60;
          }
          // Set logoSizeType based on logo_size value
          const size = Number(this.pos_settings.logo_size);
          if (size === 40) {
            this.logoSizeType = 'small';
          } else if (size === 60) {
            this.logoSizeType = 'medium';
          } else if (size === 80) {
            this.logoSizeType = 'large';
          } else {
            this.logoSizeType = 'custom';
          }
          this.isLoading = false;
        })
        .catch(error => {
          this.isLoading = false;
        });
    },
  },

  created() {
    this.get_pos_Settings();
    this.get_vat_number();

    Fire.$on("Event_Pos_Settings", () => {
      this.get_pos_Settings();
      this.get_vat_number();
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.px-next.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .px-next.pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__alert { margin-bottom: var(--pxn-space-4); }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
@media (max-width: 640px) { .pxcfg__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__seg { display: flex; gap: var(--pxn-space-2); }
.pxcfg__seg--wrap { flex-wrap: wrap; }
.pxcfg__subhead { margin: var(--pxn-space-6) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__preview { margin-top: var(--pxn-space-5); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface-2); }
.pxcfg__preview-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--pxn-space-3); }
.pxcfg__preview-head .pxcfg__subhead { margin: 0; }
.pxcfg__toggles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-3); }
@media (max-width: 900px) { .pxcfg__toggles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__toggles { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__toggle { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcfg__toggle-title { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
</style>

<style scoped>
.pos-receipt-demo {
  /* Approximate 88mm receipt width at 96dpi: ~332px */
  width: 330px;
  max-width: 100%;
  margin: 0 auto;
  background: #ffffff;
  padding: 10px;
  border: 1px dashed #dee2e6;
  font-size: 11px;
}

.pos-receipt-demo .info {
  text-align: center;
}

.pos-receipt-demo .table_data {
  width: 100%;
}

/* Demo logo styles */
.demo-logo-circle {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: #e9ecef;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: bold;
  color: #6c757d;
}

.demo-logo-circle.small {
  width: 40px;
  height: 40px;
  font-size: 8px;
}

.demo-logo-rect {
  width: 60px;
  height: 40px;
  background: #e9ecef;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 8px;
  font-weight: bold;
  color: #6c757d;
  border-radius: 4px;
}

.demo-qr-box {
  width: 80px;
  height: 80px;
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  margin: 0 auto;
}

.zatca-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.zatca-qr-title {
  font-weight: 700;
  font-size: 10px;
  margin-bottom: 4px;
  letter-spacing: 1px;
  text-transform: uppercase;
}

/* Layout 3 specific styles */
.receipt-layout-3 .info {
  text-align: left;
}

.receipt-layout-3 .info .d-flex {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

/* Layout 5 specific styles (Minimal) */
.receipt-layout-5 {
  width: 240px;
  max-width: 100%;
  margin: 0 auto;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  font-size: 11px;
  line-height: 1.4;
  color: #111;
  letter-spacing: 0.2px;
}

.receipt-layout-5 .minimal-store-name {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  margin-top: 2px;
}

.receipt-layout-5 .minimal-contact {
  font-size: 10px;
  color: #555;
  margin-top: 2px;
}

.receipt-layout-5 .minimal-divider {
  border-top: 1px solid #111;
  margin: 6px 0;
}

.receipt-layout-5 .minimal-meta {
  font-size: 10px;
}

.receipt-layout-5 .minimal-meta-row {
  display: flex;
  justify-content: space-between;
  padding: 1px 0;
}

.receipt-layout-5 .minimal-meta-row span:first-child {
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
}

.receipt-layout-5 .minimal-items {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}

.receipt-layout-5 .minimal-items td {
  padding: 3px 0;
  vertical-align: top;
}

.receipt-layout-5 .minimal-item-name {
  font-weight: 500;
}

.receipt-layout-5 .minimal-item-qty {
  color: #777;
  font-size: 9px;
  margin-top: 1px;
}

.receipt-layout-5 .minimal-item-discount {
  color: #999;
  font-size: 9px;
  font-style: italic;
  margin-top: 1px;
  letter-spacing: 0.3px;
}

.receipt-layout-5 .minimal-item-total {
  text-align: right;
  white-space: nowrap;
}

.receipt-layout-5 .minimal-totals {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}

.receipt-layout-5 .minimal-totals td {
  padding: 2px 0;
}

.receipt-layout-5 .minimal-totals td:last-child {
  text-align: right;
}

.receipt-layout-5 .minimal-totals td:first-child {
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
}

.receipt-layout-5 .minimal-grand td {
  font-size: 12px !important;
  font-weight: 700;
  color: #111 !important;
  letter-spacing: 0.5px !important;
  padding-top: 6px;
  border-top: 1px solid #111;
  text-transform: none !important;
}

.receipt-layout-5 .minimal-payments {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
  margin-top: 6px;
}

.receipt-layout-5 .minimal-payments th {
  font-weight: 500;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
  padding: 3px 0;
  border-top: 1px solid #eee;
  border-bottom: 1px solid #eee;
}

.receipt-layout-5 .minimal-payments th:nth-child(2),
.receipt-layout-5 .minimal-payments td:nth-child(2) {
  text-align: center;
}

.receipt-layout-5 .minimal-payments th:nth-child(3),
.receipt-layout-5 .minimal-payments td:nth-child(3) {
  text-align: right;
}

.receipt-layout-5 .minimal-payments td {
  padding: 2px 0;
}

.receipt-layout-5 .minimal-note {
  text-align: center;
  font-size: 10px;
  color: #555;
  margin: 8px 0 0;
  font-style: italic;
}

/* Responsive styles for mobile */
@media (max-width: 768px) {
  /* Make layout radio buttons responsive */
  .form-group {
    width: 100%;
  }

  .btn-group-toggle.btn-group {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
  }

  .btn-group-toggle.btn-group .btn {
    flex: 1;
    min-width: 0;
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .pos-receipt-demo {
    width: 100%;
    padding: 12px;
    font-size: 10px;
  }

  .pos-receipt-demo .table_data {
    font-size: 10px !important;
  }

  .demo-logo-circle {
    width: 50px;
    height: 50px;
    font-size: 9px;
  }

  .demo-logo-circle.small {
    width: 35px;
    height: 35px;
    font-size: 7px;
  }

  .demo-logo-rect {
    width: 50px;
    height: 35px;
    font-size: 7px;
  }

  .demo-qr-box {
    width: 70px;
    height: 70px;
  }

  .zatca-qr-title {
    font-size: 9px;
  }

  /* Make tables horizontally scrollable on mobile if needed */
  .pos-receipt-demo {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .pos-receipt-demo .table_data {
    min-width: 100%;
  }

  .pos-receipt-demo .table_data td,
  .pos-receipt-demo .table_data th {
    white-space: nowrap;
    padding: 2px 4px;
  }

  /* Allow text wrapping for product names */
  .pos-receipt-demo .table_data td:first-child {
    white-space: normal;
    word-wrap: break-word;
  }

  /* Adjust layout 3 header for mobile */
  .receipt-layout-3 .info .d-flex {
    flex-direction: column;
    gap: 8px;
  }

  .receipt-layout-3 .demo-logo-rect {
    align-self: flex-end;
  }
}

@media (max-width: 480px) {
  /* Stack layout buttons vertically on small screens */
  .btn-group-toggle.btn-group {
    flex-direction: column;
  }

  .btn-group-toggle.btn-group .btn {
    width: 100%;
    margin-bottom: 4px;
    border-radius: 0.25rem !important;
  }

  .btn-group-toggle.btn-group .btn:first-child {
    border-top-left-radius: 0.25rem !important;
    border-top-right-radius: 0.25rem !important;
    border-bottom-left-radius: 0.25rem !important;
    border-bottom-right-radius: 0.25rem !important;
  }

  .btn-group-toggle.btn-group .btn:last-child {
    border-bottom-left-radius: 0.25rem !important;
    border-bottom-right-radius: 0.25rem !important;
    margin-bottom: 0;
  }

  .btn-group-toggle.btn-group .btn {
    font-size: 0.8rem;
    padding: 0.375rem 0.5rem;
    white-space: normal;
    word-wrap: break-word;
  }

  .pos-receipt-demo {
    padding: 8px;
    font-size: 9px;
  }

  .pos-receipt-demo .table_data {
    font-size: 9px !important;
  }

  .demo-logo-circle {
    width: 40px;
    height: 40px;
    font-size: 8px;
  }

  .demo-logo-circle.small {
    width: 30px;
    height: 30px;
    font-size: 6px;
  }

  .demo-logo-rect {
    width: 40px;
    height: 30px;
    font-size: 6px;
  }

  .demo-qr-box {
    width: 60px;
    height: 60px;
  }

  .zatca-qr-title {
    font-size: 8px;
  }

  /* Ensure text doesn't overflow */
  .pos-receipt-demo small {
    word-wrap: break-word;
    overflow-wrap: break-word;
  }

  /* Barcode container already handles sizing via max-width: 100% */
}

/* Ensure receipt preview card is responsive */
@media (max-width: 768px) {
  .pos-receipt-demo {
    margin: 0;
  }
}

/* Make sure tables don't break layout on very small screens */
@media (max-width: 360px) {
  .pos-receipt-demo {
    font-size: 8px;
    padding: 6px;
  }

  .pos-receipt-demo .table_data {
    font-size: 8px !important;
  }

  .pos-receipt-demo td,
  .pos-receipt-demo th {
    padding: 2px 4px;
  }
}
</style>


