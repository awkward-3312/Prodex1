<template>
  <div class="px-next pxsl">
    <px-page-header :title="$t('ListSales')" :breadcrumbs="[{ label: $t('Sales') }, { label: $t('ListSales') }]">
      <template #actions>
        <px-menu :items="exportMenu" align="end" @select="onExport">
          <template #trigger>
            <px-button variant="secondary" size="sm" icon="file-spreadsheet" trailing-icon="chevron-down">Exportar</px-button>
          </template>
        </px-menu>
        <!-- "Nueva venta" administrativa retirada: toda venta manual nueva se
             origina exclusivamente desde el POS (regla de negocio PRODEX). -->
        <px-button
          v-if="currentUserPermissions && currentUserPermissions.includes('Pos_view')"
          variant="primary" icon="shopping-cart" @click="$router.push('/app/pos')"
        >{{ $t('Go_to_POS') }}</px-button>
      </template>
    </px-page-header>

    <px-toolbar
      :search="search"
      :search-placeholder="$t('Search_this_table')"
      :filter-count="activeFilterCount"
      @update:search="onSearchInput"
      @open-filters="filtersOpen = !filtersOpen"
    />

    <div v-if="filtersOpen" class="pxsl__filters">
      <div class="pxsl__filters-grid">
        <px-field :label="$t('date')"><template #default="{ id }"><px-input :id="id" type="date" v-model="Filter_date" /></template></px-field>
        <px-field :label="$t('Reference')"><template #default="{ id }"><px-input :id="id" v-model="Filter_Ref" :placeholder="$t('Reference')" /></template></px-field>
        <px-field :label="$t('Customer')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Client" :reduce="o => o.value" :placeholder="$t('Choose_Customer')"
              :options="customers.map(c => ({ label: c.name, value: c.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('warehouse')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_warehouse" :reduce="o => o.value" :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
          </template>
        </px-field>
        <px-field :label="$t('Status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_status" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'completed', value: 'completed' }, { label: 'Pending', value: 'pending' }, { label: 'Ordered', value: 'ordered' }]" />
          </template>
        </px-field>
        <px-field :label="$t('PaymentStatus')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_Payment" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Paid', value: 'paid' }, { label: 'partial', value: 'partial' }, { label: 'UnPaid', value: 'unpaid' }]" />
          </template>
        </px-field>
        <px-field :label="$t('Shipping_status')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="Filter_shipping" :reduce="o => o.value" :placeholder="$t('Choose_Status')"
              :options="[{ label: 'Ordered', value: 'ordered' }, { label: 'Packed', value: 'packed' }, { label: 'Shipped', value: 'shipped' }, { label: 'Delivered', value: 'delivered' }, { label: 'Cancelled', value: 'cancelled' }]" />
          </template>
        </px-field>
      </div>
      <div class="pxsl__filters-act">
        <px-button size="sm" variant="primary" icon="filter" @click="applyFilters">{{ $t('Filter') }}</px-button>
        <px-button size="sm" variant="ghost" icon="x" @click="Reset_Filter">{{ $t('Reset') }}</px-button>
      </div>
    </div>

    <div v-if="isLoading" class="pxsl__pad">
      <px-skeleton variant="table" :rows="10" :columns="6" />
    </div>

    <template v-else>
      <transition name="pxsl-bulk">
        <div v-if="selectedIds.length" class="pxsl__bulk">
          <span><b class="pxn-num">{{ selectedIds.length }}</b> {{ $t('selected') }}</span>
          <div class="pxsl__bulk-act">
            <px-button v-if="currentUserPermissions.includes('Sales_delete')" size="sm" variant="danger" icon="trash-2" @click="delete_by_selected">{{ $t('Del') }}</px-button>
            <px-button size="sm" variant="ghost" @click="selectedIds = []">{{ $t('Cancel') }}</px-button>
          </div>
        </div>
      </transition>

      <div class="pxsl__tablewrap">
        <px-table
          v-if="sales.length"
          :columns="columns"
          :rows="sales"
          row-key="id"
          selectable
          :selected.sync="selectedIds"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-date="{ row }">{{ formatDisplayDate(row.date) }}</template>
          <template #cell-Ref="{ row }">
            <router-link class="pxsl__link" :to="'/app/sales/detail/' + row.id">{{ row.Ref }}</router-link>
            <lucide-icon v-if="row.sale_has_return == 'yes'" name="arrow-left" :size="13" class="pxsl__ret" />
          </template>
          <template #cell-statut="{ row }">
            <px-badge :tone="row.statut === 'completed' ? 'success' : (row.statut === 'pending' ? 'info' : 'warning')">
              {{ row.statut === 'completed' ? $t('complete') : (row.statut === 'pending' ? $t('Pending') : $t('Ordered')) }}
            </px-badge>
          </template>
          <template #cell-payment_status="{ row }">
            <px-badge :tone="row.payment_status === 'paid' ? 'success' : (row.payment_status === 'partial' ? 'info' : 'warning')">
              {{ row.payment_status === 'paid' ? $t('Paid') : (row.payment_status === 'partial' ? $t('partial') : $t('Unpaid')) }}
            </px-badge>
          </template>
          <template #cell-shipping_status="{ row }">
            <px-badge v-if="row.shipping_status" :tone="shipTone(row.shipping_status)">{{ shipLabel(row.shipping_status) }}</px-badge>
            <span v-else class="pxsl__muted">—</span>
          </template>
          <template #cell-GrandTotal="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.GrandTotal, 2) }}</span></template>
          <template #cell-paid_amount="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.paid_amount, 2) }}</span></template>
          <template #cell-due="{ row }"><span class="pxn-num">{{ formatPriceWithSymbol(currentUser.currency, row.due, 2) }}</span></template>
          <template #cell-documents="{ row }">
            <px-badge v-if="row.documents_count > 0" tone="info" icon="file">{{ row.documents_count }}</px-badge>
            <span v-else class="pxsl__muted">—</span>
          </template>
          <template #row-actions="{ row }">
            <px-kebab :items="rowActions(row)" @select="onRowAction(row, $event)" />
          </template>
        </px-table>

        <px-empty-state
          v-else
          icon="shopping-cart"
          :title="$t('No_sales_yet')"
          :description="$t('No_sales_desc')"
        >
          <px-button
            v-if="currentUserPermissions && currentUserPermissions.includes('Pos_view')"
            size="sm" variant="primary" icon="shopping-cart" @click="$router.push('/app/pos')"
          >{{ $t('Go_to_POS') }}</px-button>
        </px-empty-state>
      </div>

      <px-pagination
        v-if="sales.length"
        :page="serverParams.page"
        :per-page="Number(limit)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>


    <!-- Show payments -->
    <px-modal v-model="showPaymentOpen" :title="$t('ShowPayment')" size="lg">
      <div class="pxsl-tbl__wrap pxn-scroll">
        <table class="pxsl-tbl">
          <thead>
            <tr>
              <th>{{ $t('date') }}</th><th>{{ $t('Reference') }}</th><th class="is-right">{{ $t('Amount') }}</th>
              <th>{{ $t('PayeBy') }}</th><th class="is-right">{{ $t('Action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="payments.length <= 0"><td colspan="5" class="pxsl__empty">{{ $t('NodataAvailable') }}</td></tr>
            <tr v-for="payment in payments" :key="payment.id">
              <td>{{ payment.date }}</td>
              <td class="pxn-mono">{{ payment.Ref }}</td>
              <td class="is-right pxn-num">{{ formatPriceWithSymbol(currentUser.currency, payment.montant, 2) }}</td>
              <td>{{ payment.payment_method ? payment.payment_method.name : '---' }}</td>
              <td class="is-right">
                <div class="pxsl__rowbtns">
                  <px-button size="sm" variant="ghost" icon-only icon="printer" :title="$t('print')" @click="Payment_Sale_PDF(payment, payment.id)" />
                  <px-button v-if="currentUserPermissions.includes('payment_sales_edit')" size="sm" variant="ghost" icon-only icon="pencil" :title="$t('Edit')" @click="Edit_Payment(payment)" />
                  <px-button size="sm" variant="ghost" icon-only icon="mail" title="Email" @click="Send_Email_Payment(payment.id)" />
                  <px-button size="sm" variant="ghost" icon-only icon="message-square" title="SMS" @click="Payment_Sale_SMS(payment.id)" />
                  <px-button v-if="currentUserPermissions.includes('payment_sales_delete')" size="sm" variant="ghost" icon-only icon="x" :title="$t('Delete')" @click="Remove_Payment(payment.id)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <template #footer="{ close }">
        <span class="pxsl__grow" />
        <px-button variant="secondary" @click="close">{{ $t('Close') || 'Cerrar' }}</px-button>
      </template>
    </px-modal>

    <!-- Add / edit payment -->
    <validation-observer ref="Add_payment">
      <px-modal v-model="addPaymentOpen" :title="EditPaiementMode ? $t('EditPayment') : $t('AddPayment')" size="lg">
        <b-form @submit.prevent="Submit_Payment">
          <p class="pxsl__modaltitle">{{ client_name }}</p>
          <div class="pxsl__grid3">
            <validation-provider ref="pDateProvider" name="date" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('date')" required :error="v.errors[0]">
                <template #default="{ id }"><px-input :id="id" type="date" v-model="payment.date" @input="v.validate" /></template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Reference')">
              <template #default="{ id }"><px-input :id="id" v-model="payment.Ref" disabled /></template>
            </px-field>

            <validation-provider ref="pMethodProvider" name="Payment choice" :rules="{ required: true }" v-slot="v">
              <px-field :label="$t('Paymentchoice')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="payment.payment_method_id" :reduce="o => o.value"
                    :disabled="EditPaiementMode" :placeholder="$t('PleaseSelect')"
                    @input="val => { Selected_PaymentMethod(val); v.validate(val); }"
                    :options="payment_methods.map(m => ({ label: m.name, value: m.id }))" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="pRecvProvider" name="Received Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Received_Amount')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" v-model.number="payment.received_amount" :placeholder="$t('Received_Amount')"
                    :disabled="EditPaiementMode && (payment.payment_method_id == '1' || payment.payment_method_id == 1)"
                    @input="v.validate($event); Verified_Received_Amount(payment.received_amount)" />
                </template>
              </px-field>
            </validation-provider>

            <validation-provider ref="pAmtProvider" name="Amount" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
              <px-field :label="$t('Paying_Amount')" required :error="v.errors[0]">
                <template #default="{ id }">
                  <px-input :id="id" v-model.number="payment.montant" :placeholder="$t('Paying_Amount')"
                    :disabled="EditPaiementMode && (payment.payment_method_id == '1' || payment.payment_method_id == 1)"
                    @input="v.validate($event); Verified_paidAmount(payment.montant)" />
                </template>
              </px-field>
            </validation-provider>

            <px-field :label="$t('Change')">
              <template #default>
                <p class="pxsl__change pxn-num">{{ parseFloat(payment.received_amount - payment.montant).toFixed(priceDecimals) }}</p>
              </template>
            </px-field>

            <px-field :label="$t('Account')" class="pxsl__span2">
              <template #default="{ id }">
                <vs-px :input-id="id" v-model="payment.account_id" :reduce="o => o.value" :placeholder="$t('Choose_Account')"
                  :options="accounts.map(a => ({ label: a.account_name, value: a.id }))" />
              </template>
            </px-field>

            <px-field :label="$t('Note')" class="pxsl__span2">
              <template #default="{ id }"><px-textarea :id="id" v-model="payment.notes" :rows="3" /></template>
            </px-field>
          </div>

          <div class="pxsl__actionbar">
            <px-button variant="secondary" type="button" @click="addPaymentOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="paymentProcessing">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>

    <!-- Edit shipment -->
    <validation-observer ref="shipment_ref">
      <px-modal v-model="shipmentOpen" :title="$t('Edit')" size="md">
        <b-form @submit.prevent="Submit_Shipment">
          <validation-provider ref="shipStatusProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="shipment.status" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[
                    { label: $t('Ordered'), value: 'ordered' }, { label: $t('Packed'), value: 'packed' },
                    { label: $t('Shipped'), value: 'shipped' }, { label: $t('Delivered'), value: 'delivered' },
                    { label: $t('Cancelled'), value: 'cancelled' }
                  ]" />
              </template>
            </px-field>
          </validation-provider>

          <px-field :label="$t('delivered_to')" class="pxsl__gap">
            <template #default="{ id }"><px-input :id="id" v-model="shipment.delivered_to" :placeholder="$t('delivered_to')" /></template>
          </px-field>
          <px-field :label="$t('Adress')" class="pxsl__gap">
            <template #default="{ id }"><px-textarea :id="id" v-model="shipment.shipping_address" :rows="4" :placeholder="$t('Enter_Address')" /></template>
          </px-field>
          <px-field :label="$t('Please_provide_any_details')" class="pxsl__gap">
            <template #default="{ id }"><px-textarea :id="id" v-model="shipment.shipping_details" :rows="4" :placeholder="$t('Please_provide_any_details')" /></template>
          </px-field>

          <div class="pxsl__actionbar">
            <px-button variant="secondary" type="button" @click="shipmentOpen = false">{{ $t('Cancel') }}</px-button>
            <px-button variant="primary" type="submit" icon="check" :loading="Submit_Processing_shipment">{{ $t('submit') }}</px-button>
          </div>
        </b-form>
      </px-modal>
    </validation-observer>

    <!-- Modal Show Invoice POS-->
    <px-modal v-model="invoiceOpen" size="sm" :title="$t('Invoice_POS')">
      <div id="invoice-POS">
        <div style="max-width:400px;margin:0px auto">

          <div v-if="invoice_pos.sar_fiscal" class="sar-fiscal-receipt">
            <div class="sar-fiscal-title">FACTURA</div>
            <div v-if="invoice_pos.sar_fiscal.status === 'voided'" class="sar-fiscal-voided">ANULADA</div>
            <div><strong>{{ invoice_pos.sar_fiscal.fiscal_number }}</strong></div>
            <div><strong>CAI:</strong> {{ invoice_pos.sar_fiscal.cai }}</div>
            <div>
              <strong>Rango:</strong>
              {{ String(invoice_pos.sar_fiscal.range_start).padStart(8, '0') }} –
              {{ String(invoice_pos.sar_fiscal.range_end).padStart(8, '0') }}
            </div>
            <div><strong>Fecha límite:</strong> {{ invoice_pos.sar_fiscal.deadline }}</div>
            <div><strong>RTN emisor:</strong> {{ invoice_pos.sar_fiscal.issuer.rtn }}</div>
            <div><strong>Cliente:</strong> {{ invoice_pos.sar_fiscal.customer.name || 'Consumidor final' }}</div>
            <div v-if="invoice_pos.sar_fiscal.customer.rtn"><strong>RTN cliente:</strong> {{ invoice_pos.sar_fiscal.customer.rtn }}</div>
            <div class="sar-fiscal-words">{{ invoice_pos.sar_fiscal.total_in_words }}</div>
          </div>

          <!-- Layout 1 - Standard -->
          <div v-if="currentReceiptLayout === 1">
            <div class="info">
              <div class="invoice_logo text-center mb-2">
                <img
                  v-show="pos_settings.show_logo !== 0"
                  :src="$imgUrl('settings', invoice_pos.setting.logo)"
                  alt
                  :width="pos_settings.logo_size || 60"
                  :height="pos_settings.logo_size || 60"
                >
              </div>
              <p>
                <span v-show="pos_settings.show_store_name !== 0">
                  <strong>{{invoice_pos.setting.CompanyName}}</strong><br>
                </span>
                <span v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0">
                  {{$t('Reference')}} : {{invoice_pos.sale.Ref}}<br>
                </span>
                <span v-show="pos_settings.show_date !== 0">
                  {{$t('date')}} : {{invoice_pos.sale.date}}<br>
                </span>
                <span v-show="pos_settings.show_seller !== 0">
                  {{$t('Seller')}} : {{invoice_pos.sale.seller_name}}<br>
                </span>
                <span v-show="pos_settings.show_address">
                  {{$t('Adress')}} : {{invoice_pos.setting.CompanyAdress}}<br>
                </span>
                <span v-show="pos_settings.show_email">
                  {{$t('Email')}} : {{invoice_pos.setting.email}}<br>
                </span>
                <span v-show="pos_settings.show_phone">
                  {{$t('Phone')}} : {{invoice_pos.setting.CompanyPhone}}<br>
                </span>
                <span v-show="pos_settings.show_customer">
                  {{$t('Customer')}} : {{invoice_pos.sale.client_name}}<br>
                </span>
                <span v-show="pos_settings.show_Warehouse">
                  {{$t('warehouse')}} : {{invoice_pos.sale.warehouse_name}}<br>
                </span>
              </p>
            </div>

            <table style="width: 100%;">
              <tbody>
                <tr v-for="detail_invoice in invoice_pos.details">
                  <td colspan="3">
                    {{detail_invoice.name}}
                    <br v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null">
                    <span v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null ">
                      {{$t('IMEI_SN')}} : {{detail_invoice.imei_number}}
                    </span>
                    <br>
                    <span>
                      {{formatNumber(detail_invoice.quantity,2)}} {{ packLineUnit(detail_invoice) }}
                      x
                      {{ formatPriceDisplay(detail_invoice.total/detail_invoice.quantity,2) }}
                    </span>
                    <br v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1">
                    <small v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1" style="color:#666;">(×{{ detail_invoice.pack_multiplier }}) = {{ formatNumber(detail_invoice.quantity * detail_invoice.pack_multiplier, 2) }} {{ detail_invoice.unit_sale || ($t('Pcs') || 'pcs') }}</small>
                    <br v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0">
                    <small v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0" style="color:#888;font-style:italic;">{{$t('Discount')}}: -{{ formatPriceDisplay(Number(detail_invoice.DiscountNet) * Number(detail_invoice.quantity), 2) }}</small>
                  </td>
                  <td style="text-align:right;vertical-align:bottom">
                    {{ formatPriceDisplay(detail_invoice.total,2) }}
                  </td>
                </tr>

                <!-- Subtotal (before tax/discount/shipping) -->
                <tr style="margin-top:10px">
                  <td colspan="3" class="total">{{$t('pos.Subtotal')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoiceSubtotal, 2) }}
                  </td>
                </tr>

                <tr style="margin-top:10px" v-show="pos_settings.show_tax">
                  <td colspan="3" class="total">{{$t('OrderTax')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.taxe ,2) }}
                    ({{formatNumber(invoice_pos.sale.tax_rate,2)}} %)
                  </td>
                </tr>

                <tr style="margin-top:10px" v-show="pos_settings.show_discount">
                  <td colspan="3" class="total">{{$t('Discount')}}</td>
                  <td style="text-align:right;" class="total">
                    <!-- If percentage: show percent value AND manual discount amount; else amount only -->
                    <template v-if="String(invoice_pos.sale.discount_Method || '2') === '1'">
                      {{ formatNumber(invoice_pos.sale.discount, 2) }}%
                      ({{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }})
                    </template>
                    <template v-else>
                      {{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }}
                    </template>
                  </td>
                </tr>

                <tr
                  style="margin-top:2px"
                  v-show="pos_settings.show_discount && invoice_pos.sale.discount_from_points && Number(invoice_pos.sale.discount_from_points) > 0"
                >
                  <td colspan="3" class="total">{{$t('Discount_from_Points')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.discount_from_points ,2) }}
                  </td>
                </tr>

                <tr style="margin-top:10px" v-show="pos_settings.show_shipping">
                  <td colspan="3" class="total">{{$t('Shipping')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.shipping ,2) }}
                  </td>
                </tr>

                <tr style="margin-top:10px">
                  <td colspan="3" class="total">{{$t('Total')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.GrandTotal ,2) }}
                  </td>
                </tr>

                <tr v-show="pos_settings.show_paid !== 0">
                  <td colspan="3" class="total">{{$t('Paid')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.paid_amount ,2) }}
                  </td>
                </tr>

                <tr v-show="pos_settings.show_due !== 0">
                  <td colspan="3" class="total">{{$t('Due')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, (invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount), 2) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <table
              class="change mt-3"
              style="font-size: 10px;width: 100%;"
              v-show="pos_settings.show_payments !== 0 && invoice_pos.sale.paid_amount > 0"
            >
              <thead>
                <tr style="background: #eee;">
                  <th style="text-align: left;" colspan="1">{{$t('PayeBy')}}:</th>
                  <th style="text-align: center;" colspan="2">{{$t('Amount')}}:</th>
                  <th style="text-align: right;" colspan="1">{{$t('Change')}}:</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="payment_pos in payments">
                  <tr :key="'pay-' + payment_pos.id">
                    <td style="text-align: left;" colspan="1">
                      {{payment_pos.payment_method?payment_pos.payment_method.name:'---'}}
                    </td>
                    <td style="text-align: center;" colspan="2">
                      {{ formatPriceDisplay(payment_pos.montant ,2) }}
                    </td>
                    <td style="text-align: right;" colspan="1">
                      {{ formatPriceDisplay(payment_pos.change ,2) }}
                    </td>
                  </tr>
                  <tr v-if="payment_pos.notes" :key="'pay-note-' + payment_pos.id">
                    <td colspan="4" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">
                      {{$t('Payment_note')}}: {{payment_pos.notes}}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <div id="legalcopy" class="ml-2">
              <p v-if="invoice_pos.sale && invoice_pos.sale.notes" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;margin:0;">
                {{$t('sale_note')}}: {{invoice_pos.sale.notes}}
              </p>
              <p class="legal" v-show="pos_settings.show_note">
                <strong>{{pos_settings.note_customer}}</strong>
              </p>
              <!-- Receipt QR codes (ZATCA + Invoice URL) -->
              <div
                v-if="(invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0) || (pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref)"
                class="receipt-qr-row mt-2"
              >
                <div
                  v-if="invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">ZATCA QR</div>
                  <div class="receipt-qr-canvas" ref="zatcaQrcode"></div>
                </div>
                <div
                  v-if="pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">Invoice QR</div>
                  <div class="receipt-qr-canvas" ref="invoiceUrlQr"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Layout 2 - Compact -->
          <div v-else-if="currentReceiptLayout === 2">
            <div class="info text-center">
              <div class="invoice_logo mb-1" v-show="pos_settings.show_logo !== 0">
                <img
                  :src="$imgUrl('settings', invoice_pos.setting.logo)"
                  alt
                  :width="pos_settings.logo_size || 60"
                  :height="pos_settings.logo_size || 60"
                >
              </div>
              <div v-show="pos_settings.show_store_name !== 0">
                {{invoice_pos.setting.CompanyName}}
              </div>
              <small v-show="pos_settings.show_address">
                {{invoice_pos.setting.CompanyAdress}}
              </small>
              <br v-show="pos_settings.show_address">
              <small v-show="pos_settings.show_phone">
                {{invoice_pos.setting.CompanyPhone}}
              </small>
              <br v-show="pos_settings.show_phone">
              <small v-show="pos_settings.show_email">
                {{invoice_pos.setting.email}}
              </small>
              <div class="mt-1">
                <small
                  v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0"
                >
                  {{$t('Reference')}} : {{invoice_pos.sale.Ref}}
                </small>
                <br v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0">
                <small v-show="pos_settings.show_date !== 0">
                  {{$t('date')}} : {{invoice_pos.sale.date}}
                </small>
                <br>
                <small v-show="pos_settings.show_seller !== 0">
                  {{$t('Seller')}} : {{invoice_pos.sale.seller_name}}
                </small>
                <br>
                <small v-show="pos_settings.show_customer">
                  {{$t('Customer')}} : {{invoice_pos.sale.client_name}}
                </small>
                <br>
                <small v-show="pos_settings.show_Warehouse">
                  {{$t('warehouse')}} : {{invoice_pos.sale.warehouse_name}}
                </small>
              </div>
            </div>

            <table class="table_data mt-2" style="width:100%; font-size:11px;">
              <thead>
                <tr>
                  <th style="text-align:left">{{$t('ProductName')}}</th>
                  <th style="text-align:center">{{$t('Quantity')}}</th>
                  <th style="text-align:right">{{$t('Price')}}</th>
                  <th style="text-align:right">{{$t('Total')}}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="detail_invoice in invoice_pos.details">
                  <tr :key="'sl2-item-' + detail_invoice.id">
                    <td>
                      {{detail_invoice.name}}
                      <br v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null">
                      <small
                        v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null "
                      >
                        {{$t('IMEI_SN')}} : {{detail_invoice.imei_number}}
                      </small>
                      <br v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1">
                      <small v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1" style="color:#666;">(×{{ detail_invoice.pack_multiplier }}) = {{ formatNumber(detail_invoice.quantity * detail_invoice.pack_multiplier, 2) }} {{ detail_invoice.unit_sale || ($t('Pcs') || 'pcs') }}</small>
                    </td>
                    <td style="text-align:center">
                      {{formatNumber(detail_invoice.quantity,2)}} {{ packLineUnit(detail_invoice) }}
                    </td>
                    <td style="text-align:right">
                      {{formatNumber(detail_invoice.total/detail_invoice.quantity,2)}}
                    </td>
                    <td style="text-align:right">
                      {{formatNumber(detail_invoice.total,2)}}
                    </td>
                  </tr>
                  <tr
                    v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0"
                    :key="'sl2-disc-' + detail_invoice.id"
                  >
                    <td colspan="4" style="color:#888;font-style:italic;font-size:10px;padding-left:8px;">
                      {{$t('Discount')}}: -{{ formatPriceDisplay(Number(detail_invoice.DiscountNet) * Number(detail_invoice.quantity), 2) }}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <table class="table_data mt-2" style="width:100%; font-size:11px;">
              <tbody>
                <tr>
                  <td class="total">{{$t('pos.Subtotal')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoiceSubtotal, 2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_tax">
                  <td class="total">{{$t('OrderTax')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.taxe ,2) }}
                    ({{formatNumber(invoice_pos.sale.tax_rate,2)}} %)
                  </td>
                </tr>
                <tr v-show="pos_settings.show_discount">
                  <td class="total">{{$t('Discount')}}</td>
                  <td style="text-align:right" class="total">
                    <!-- If percentage: show percent value AND manual discount amount; else amount only -->
                    <template v-if="String(invoice_pos.sale.discount_Method || '2') === '1'">
                      {{ formatNumber(invoice_pos.sale.discount, 2) }}%
                      ({{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }})
                    </template>
                    <template v-else>
                      {{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }}
                    </template>
                  </td>
                </tr>
                <tr
                  v-show="pos_settings.show_discount && invoice_pos.sale.discount_from_points && Number(invoice_pos.sale.discount_from_points) > 0"
                >
                  <td class="total">{{$t('Discount_from_Points')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.discount_from_points ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_shipping">
                  <td class="total">{{$t('Shipping')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.shipping ,2) }}
                  </td>
                </tr>
                <tr>
                  <td class="total">{{$t('Total')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.GrandTotal ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_paid !== 0">
                  <td class="total">{{$t('Paid')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.paid_amount ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_due !== 0">
                  <td class="total">{{$t('Due')}}</td>
                  <td style="text-align:right" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, (invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount), 2) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <table
              class="change mt-2"
              style="font-size: 10px;width: 100%;"
              v-show="pos_settings.show_payments !== 0 && invoice_pos.sale.paid_amount > 0"
            >
              <thead>
                <tr style="background: #eee;">
                  <th style="text-align: left;" colspan="1">{{$t('PayeBy')}}:</th>
                  <th style="text-align: center;" colspan="2">{{$t('Amount')}}:</th>
                  <th style="text-align: right;" colspan="1">{{$t('Change')}}:</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="payment_pos in payments">
                  <tr :key="'pay2-' + payment_pos.id">
                    <td style="text-align: left;" colspan="1">
                      {{payment_pos.payment_method?payment_pos.payment_method.name:'---'}}
                    </td>
                    <td style="text-align: center;" colspan="2">
                      {{formatNumber(payment_pos.montant ,2)}}
                    </td>
                    <td style="text-align: right;" colspan="1">
                      {{formatNumber(payment_pos.change ,2)}}
                    </td>
                  </tr>
                  <tr v-if="payment_pos.notes" :key="'pay2-note-' + payment_pos.id">
                    <td colspan="4" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">
                      {{$t('Payment_note')}}: {{payment_pos.notes}}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <div id="legalcopy" class="ml-2">
              <p v-if="invoice_pos.sale && invoice_pos.sale.notes" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;margin:0;">
                {{$t('sale_note')}}: {{invoice_pos.sale.notes}}
              </p>
              <p class="legal" v-show="pos_settings.show_note">
                <strong>{{pos_settings.note_customer}}</strong>
              </p>
              <!-- Receipt QR codes (ZATCA + Invoice URL) -->
              <div
                v-if="(invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0) || (pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref)"
                class="receipt-qr-row mt-2"
              >
                <div
                  v-if="invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">ZATCA QR</div>
                  <div class="receipt-qr-canvas" ref="zatcaQrcode"></div>
                </div>
                <div
                  v-if="pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">Invoice QR</div>
                  <div class="receipt-qr-canvas" ref="invoiceUrlQr"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Layout 3 - Detailed -->
          <div v-else-if="currentReceiptLayout === 3">
            <div class="info mb-2">
              <div class="d-flex justify-content-between">
                <div>
                  <strong v-show="pos_settings.show_store_name !== 0">
                    {{invoice_pos.setting.CompanyName}}
                  </strong>
                  <br>
                  <span v-show="pos_settings.show_address">
                    {{invoice_pos.setting.CompanyAdress}}
                  </span>
                  <br v-show="pos_settings.show_address">
                  <span v-show="pos_settings.show_phone">
                    {{invoice_pos.setting.CompanyPhone}}
                  </span>
                  <br v-show="pos_settings.show_phone">
                  <span v-show="pos_settings.show_email">
                    {{invoice_pos.setting.email}}
                  </span>
                </div>
                <div class="invoice_logo text-center mb-2" v-show="pos_settings.show_logo !== 0">
                  <img
                    :src="$imgUrl('settings', invoice_pos.setting.logo)"
                    alt
                    :width="pos_settings.logo_size || 60"
                    :height="pos_settings.logo_size || 60"
                  >
                </div>
              </div>
              <div class="mt-2" style="font-size:11px;">
                <div
                  v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0"
                >
                  {{$t('Reference')}} : {{invoice_pos.sale.Ref}}
                </div>
                <div v-show="pos_settings.show_date !== 0">
                  {{$t('date')}} : {{invoice_pos.sale.date}}
                </div>
                <div v-show="pos_settings.show_seller !== 0">
                  {{$t('Seller')}} : {{invoice_pos.sale.seller_name}}
                </div>
                <div v-show="pos_settings.show_customer">
                  {{$t('Customer')}} : {{invoice_pos.sale.client_name}}
                </div>
                <div v-show="pos_settings.show_Warehouse">
                  {{$t('warehouse')}} : {{invoice_pos.sale.warehouse_name}}
                </div>
              </div>
            </div>

            <table class="table_data w-100 mb-2" style="font-size:11px;">
              <tbody>
                <tr v-for="detail_invoice in invoice_pos.details">
                  <td colspan="2">
                    <strong>{{detail_invoice.name}}</strong>
                    <br v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null">
                    <span
                      v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null "
                    >
                      {{$t('IMEI_SN')}} : {{detail_invoice.imei_number}}
                    </span>
                    <br>
                    <small>
                      {{formatNumber(detail_invoice.quantity,2)}} {{ packLineUnit(detail_invoice) }}
                      x
                      {{ formatPriceDisplay(detail_invoice.total/detail_invoice.quantity,2) }}
                    </small>
                    <br v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1">
                    <small v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1" style="color:#666;">(×{{ detail_invoice.pack_multiplier }}) = {{ formatNumber(detail_invoice.quantity * detail_invoice.pack_multiplier, 2) }} {{ detail_invoice.unit_sale || ($t('Pcs') || 'pcs') }}</small>
                    <br v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0">
                    <small v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0" style="color:#888;font-style:italic;">{{$t('Discount')}}: -{{ formatPriceDisplay(Number(detail_invoice.DiscountNet) * Number(detail_invoice.quantity), 2) }}</small>
                  </td>
                  <td style="text-align:right;vertical-align:bottom">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, detail_invoice.total, 2) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <table class="table_data w-100 mt-2" style="font-size:11px;">
              <tbody>
                <tr>
                  <td class="total">{{$t('pos.Subtotal')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoiceSubtotal, 2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_tax">
                  <td class="total">{{$t('OrderTax')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.taxe ,2) }}
                    ({{formatNumber(invoice_pos.sale.tax_rate,2)}} %)
                  </td>
                </tr>
                <tr v-show="pos_settings.show_discount">
                  <td class="total">{{$t('Discount')}}</td>
                  <td style="text-align:right;" class="total">
                    <!-- If percentage: show percent value AND manual discount amount; else amount only -->
                    <template v-if="String(invoice_pos.sale.discount_Method || '2') === '1'">
                      {{ formatNumber(invoice_pos.sale.discount, 2) }}%
                      ({{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }})
                    </template>
                    <template v-else>
                      {{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }}
                    </template>
                  </td>
                </tr>
                <tr
                  v-show="pos_settings.show_discount && invoice_pos.sale.discount_from_points && Number(invoice_pos.sale.discount_from_points) > 0"
                >
                  <td class="total">{{$t('Discount_from_Points')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.discount_from_points ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_shipping">
                  <td class="total">{{$t('Shipping')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.shipping ,2) }}
                  </td>
                </tr>
                <tr>
                  <td class="total">{{$t('Total')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.GrandTotal ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_paid !== 0">
                  <td class="total">{{$t('Paid')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.paid_amount ,2) }}
                  </td>
                </tr>
                <tr v-show="pos_settings.show_due !== 0">
                  <td class="total">{{$t('Due')}}</td>
                  <td style="text-align:right;" class="total">
                    {{ formatPriceWithSymbol(invoice_pos.symbol, (invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount), 2) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <table
              class="change mt-3"
              style="font-size: 10px;width: 100%;"
              v-show="pos_settings.show_payments !== 0 && invoice_pos.sale.paid_amount > 0"
            >
              <thead>
                <tr style="background: #eee;">
                  <th style="text-align: left;" colspan="1">{{$t('PayeBy')}}:</th>
                  <th style="text-align: center;" colspan="2">{{$t('Amount')}}:</th>
                  <th style="text-align: right;" colspan="1">{{$t('Change')}}:</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="payment_pos in payments">
                  <tr :key="'pay3-' + payment_pos.id">
                    <td style="text-align: left;" colspan="1">
                      {{payment_pos.payment_method?payment_pos.payment_method.name:'---'}}
                    </td>
                    <td style="text-align: center;" colspan="2">
                      {{formatNumber(payment_pos.montant ,2)}}
                    </td>
                    <td style="text-align: right;" colspan="1">
                      {{formatNumber(payment_pos.change ,2)}}
                    </td>
                  </tr>
                  <tr v-if="payment_pos.notes" :key="'pay3-note-' + payment_pos.id">
                    <td colspan="4" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">
                      {{$t('Payment_note')}}: {{payment_pos.notes}}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <div id="legalcopy" class="ml-2">
              <p v-if="invoice_pos.sale && invoice_pos.sale.notes" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;margin:0;">
                {{$t('sale_note')}}: {{invoice_pos.sale.notes}}
              </p>
              <p class="legal" v-show="pos_settings.show_note">
                <strong>{{pos_settings.note_customer}}</strong>
              </p>
              <!-- Receipt QR codes (ZATCA + Invoice URL) -->
              <div
                v-if="(invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0) || (pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref)"
                class="receipt-qr-row mt-2"
              >
                <div
                  v-if="invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">ZATCA QR</div>
                  <div class="receipt-qr-canvas" ref="zatcaQrcode"></div>
                </div>
                <div
                  v-if="pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">Invoice QR</div>
                  <div class="receipt-qr-canvas" ref="invoiceUrlQr"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Layout 4 - Bilingual (Arabic + English) -->
          <div v-else-if="currentReceiptLayout === 4" class="receipt-layout-4">
            <div class="info text-center">
              <div class="invoice_logo mb-2" v-show="pos_settings.show_logo !== 0">
                <img :src="$imgUrl('settings', invoice_pos.setting.logo)" alt :width="pos_settings.logo_size || 60" :height="pos_settings.logo_size || 60">
              </div>
              <div>
                <strong style="font-size:13px;">{{invoice_pos.setting.company_name_ar}}</strong><br>
                <strong style="font-size:12px;">{{invoice_pos.setting.CompanyName}}</strong>
              </div>
              <div v-if="invoice_pos.setting.CompanyAdress" style="font-size:10px;margin-top:2px;">{{invoice_pos.setting.CompanyAdress}}</div>
              <div v-if="invoice_pos.setting.CompanyPhone" style="font-size:10px;">{{invoice_pos.setting.CompanyPhone}}</div>
              <div v-if="invoice_pos.setting.email" v-show="pos_settings.show_email" style="font-size:10px;">{{invoice_pos.setting.email}}</div>
              <div v-if="invoice_pos.setting.vat_number" style="font-size:11px;font-weight:bold;margin-top:4px;">
                الرقم الضريبي / TRN : {{invoice_pos.setting.vat_number}}
              </div>
              <div class="mt-2 mb-2" style="border-top:1px dashed #000;border-bottom:1px dashed #000;padding:4px 0;">
                <strong>فاتورة ضريبية مبسطة</strong><br>
                <strong>Simplified Tax Invoice</strong>
              </div>
            </div>

            <div style="font-size:10px;">
              <div v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0" style="display:flex;justify-content:space-between;">
                <span>Invoice No</span>
                <span>{{invoice_pos.sale.Ref}}</span>
                <span>رقم الفاتورة</span>
              </div>
              <div v-show="pos_settings.show_date !== 0" style="display:flex;justify-content:space-between;">
                <span>Date</span>
                <span>{{invoice_pos.sale.date}}</span>
                <span>تاريخ</span>
              </div>
              <div v-show="pos_settings.show_seller !== 0" style="display:flex;justify-content:space-between;">
                <span>Seller</span>
                <span>{{invoice_pos.sale.seller_name}}</span>
                <span>البائع</span>
              </div>
              <div v-show="pos_settings.show_customer" style="display:flex;justify-content:space-between;">
                <span>Customer</span>
                <span>{{invoice_pos.sale.client_name}}</span>
                <span>العميل</span>
              </div>
              <div v-show="pos_settings.show_Warehouse" style="display:flex;justify-content:space-between;">
                <span>Warehouse</span>
                <span>{{invoice_pos.sale.warehouse_name}}</span>
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
                <tr v-for="detail_invoice in invoice_pos.details" style="border-bottom:1px dashed #eee;">
                  <td>
                    {{detail_invoice.name}}
                    <br v-if="Number(detail_invoice.tax_percent || detail_invoice.tax_rate || 0) > 0">
                    <small v-if="Number(detail_invoice.tax_percent || detail_invoice.tax_rate || 0) > 0">VAT @ {{ formatNumber(Number(detail_invoice.tax_percent || detail_invoice.tax_rate || 0),2) }}% ({{ formatPriceDisplay(detail_invoice.total * Number(detail_invoice.tax_percent || detail_invoice.tax_rate || 0) / 100, 2) }})</small>
                    <br v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0">
                    <small v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0" style="color:#666;font-style:italic;">Discount / تخفيض: -{{ formatPriceDisplay(Number(detail_invoice.DiscountNet) * Number(detail_invoice.quantity), 2) }}</small>
                    <br v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null">
                    <span v-show="detail_invoice.is_imei && detail_invoice.imei_number !==null ">IMEI/SN الرقم التسلسلي : {{detail_invoice.imei_number}}</span>
                    <br v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1">
                    <small v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1" style="color:#666;">(×{{ detail_invoice.pack_multiplier }}) = {{ formatNumber(detail_invoice.quantity * detail_invoice.pack_multiplier, 2) }} {{ detail_invoice.unit_sale || ($t('Pcs') || 'pcs') }}</small>
                  </td>
                  <td style="text-align:center">{{formatNumber(detail_invoice.quantity,2)}} {{ packLineUnit(detail_invoice) }}</td>
                  <td style="text-align:center">{{ formatPriceDisplay(detail_invoice.total/detail_invoice.quantity,2) }}</td>
                  <td style="text-align:right">{{ formatPriceDisplay(detail_invoice.total,2) }}</td>
                </tr>
              </tbody>
            </table>

            <table style="width:100%;font-size:10px;border-top:1px dashed #000;margin-top:4px;">
              <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
              <tbody>
                <tr>
                  <td style="text-align:left" class="total">Sub Total</td>
                  <td class="total">:</td>
                  <td style="text-align:center" class="total">{{ formatPriceWithSymbol(invoice_pos.symbol, invoiceSubtotal - invoiceDetailsTaxTotal, 2) }}</td>
                  <td style="text-align:right" class="total">المجموع الفرعي</td>
                </tr>
                <tr v-show="pos_settings.show_discount">
                  <td style="text-align:left" class="total">Discount</td>
                  <td class="total">:</td>
                  <td style="text-align:center" class="total">
                    <template v-if="String(invoice_pos.sale.discount_Method || '2') === '1'">
                      {{ formatNumber(invoice_pos.sale.discount, 2) }}% ({{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }})
                    </template>
                    <template v-else>
                      {{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount ,2) }}
                    </template>
                  </td>
                  <td style="text-align:right" class="total">تخفيض</td>
                </tr>
                <tr v-show="pos_settings.show_discount && invoice_pos.sale.discount_from_points && Number(invoice_pos.sale.discount_from_points) > 0">
                  <td style="text-align:left" class="total">Discount from Points</td>
                  <td class="total">:</td>
                  <td style="text-align:center" class="total">{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.discount_from_points ,2) }}</td>
                  <td style="text-align:right" class="total">خصم من النقاط</td>
                </tr>
                <tr v-show="pos_settings.show_tax && Number(invoice_pos.sale.taxe || 0) > 0">
                  <td style="text-align:left" class="total">VAT @ Total</td>
                  <td class="total">:</td>
                  <td style="text-align:center" class="total">{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.taxe ,2) }}</td>
                  <td style="text-align:right" class="total">قيمة الضريبة</td>
                </tr>
                <tr v-show="pos_settings.show_shipping">
                  <td style="text-align:left" class="total">Shipping</td>
                  <td class="total">:</td>
                  <td style="text-align:center" class="total">{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.shipping ,2) }}</td>
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
                  <td style="text-align:center">{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.GrandTotal ,2) }}</td>
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
                  <td style="text-align:center">{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.paid_amount ,2) }}</td>
                  <td style="text-align:right"><strong>المبلغ المدفوع</strong></td>
                </tr>
                <tr v-show="pos_settings.show_due !== 0">
                  <td style="text-align:left"><strong>Balance</strong></td>
                  <td><strong>:</strong></td>
                  <td style="text-align:center">{{ formatPriceWithSymbol(invoice_pos.symbol, (invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount), 2) }}</td>
                  <td style="text-align:right"><strong>الرصيد</strong></td>
                </tr>
              </tbody>
            </table>

            <table class="change mt-3" style="font-size:10px;width:100%;" v-show="pos_settings.show_payments !== 0 && invoice_pos.sale.paid_amount > 0">
              <thead>
                <tr style="background:#eee;">
                  <th style="text-align:left;" colspan="1">Paid By / طريقة الدفع:</th>
                  <th style="text-align:center;" colspan="2">Amount / المبلغ:</th>
                  <th style="text-align:right;" colspan="1">Change / الباقي:</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="payment_pos in payments">
                  <tr :key="'pay4-' + payment_pos.id">
                    <td style="text-align:left;" colspan="1">{{payment_pos.payment_method?payment_pos.payment_method.name:'---'}}</td>
                    <td style="text-align:center;" colspan="2">{{ formatPriceDisplay(payment_pos.montant ,2) }}</td>
                    <td style="text-align:right;" colspan="1">{{ formatPriceDisplay(payment_pos.change ,2) }}</td>
                  </tr>
                  <tr v-if="payment_pos.notes" :key="'pay4-note-' + payment_pos.id">
                    <td colspan="4" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">
                      {{$t('Payment_note')}} / ملاحظة الدفع: {{payment_pos.notes}}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <div id="legalcopy" class="ml-2">
              <div v-if="invoice_pos.sale && invoice_pos.sale.notes" style="font-size:9px;font-style:italic;padding-bottom:4px;white-space:pre-line;">
                {{$t('sale_note')}} / ملاحظة البيع: {{invoice_pos.sale.notes}}
              </div>
              <div v-show="pos_settings.show_note && pos_settings.note_customer" class="mt-3" style="border-top:1px dashed #000;padding-top:6px;font-size:9px;line-height:1.5;text-align:center;white-space:pre-line;">
                <strong>{{pos_settings.note_customer}}</strong>
              </div>

              <!-- Receipt QR codes (ZATCA + Invoice URL) -->
              <div
                v-if="(invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0) || (pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref)"
                class="receipt-qr-row mt-2"
              >
                <div
                  v-if="invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">ZATCA QR</div>
                  <div class="receipt-qr-canvas" ref="zatcaQrcode"></div>
                </div>
                <div
                  v-if="pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref"
                  class="receipt-qr-block"
                >
                  <div class="receipt-qr-title">Invoice QR</div>
                  <div class="receipt-qr-canvas" ref="invoiceUrlQr"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Layout 5 - Minimal -->
          <div v-else class="receipt-layout-5">
            <div class="info text-center mb-2">
              <div class="invoice_logo mb-2" v-show="pos_settings.show_logo !== 0">
                <img :src="$imgUrl('settings', invoice_pos.setting.logo)" alt :width="pos_settings.logo_size || 60" :height="pos_settings.logo_size || 60">
              </div>
              <div class="minimal-store-name" v-show="pos_settings.show_store_name !== 0">{{invoice_pos.setting.CompanyName}}</div>
              <div class="minimal-contact" v-if="invoice_pos.setting.CompanyAdress || invoice_pos.setting.CompanyPhone">
                <span v-show="pos_settings.show_address">{{invoice_pos.setting.CompanyAdress}}</span>
                <span v-show="pos_settings.show_address && pos_settings.show_phone"> &middot; </span>
                <span v-show="pos_settings.show_phone">{{invoice_pos.setting.CompanyPhone}}</span>
              </div>
              <div class="minimal-contact" v-show="pos_settings.show_email" v-if="invoice_pos.setting.email">{{invoice_pos.setting.email}}</div>
            </div>

            <div class="minimal-divider"></div>

            <div class="minimal-meta">
              <div v-if="invoice_pos.sale && invoice_pos.sale.Ref && pos_settings.show_reference !== 0" class="minimal-meta-row">
                <span>{{$t('Reference')}}</span><span>{{invoice_pos.sale.Ref}}</span>
              </div>
              <div v-show="pos_settings.show_date !== 0" class="minimal-meta-row">
                <span>{{$t('date')}}</span><span>{{invoice_pos.sale.date}}</span>
              </div>
              <div v-show="pos_settings.show_seller !== 0" class="minimal-meta-row">
                <span>{{$t('Seller')}}</span><span>{{invoice_pos.sale.seller_name}}</span>
              </div>
              <div v-show="pos_settings.show_customer" class="minimal-meta-row">
                <span>{{$t('Customer')}}</span><span>{{invoice_pos.sale.client_name}}</span>
              </div>
              <div v-show="pos_settings.show_Warehouse" class="minimal-meta-row">
                <span>{{$t('warehouse')}}</span><span>{{invoice_pos.sale.warehouse_name}}</span>
              </div>
            </div>

            <div class="minimal-divider"></div>

            <table class="minimal-items">
              <tbody>
                <tr v-for="detail_invoice in invoice_pos.details" :key="'min-item-' + detail_invoice.id">
                  <td>
                    <div class="minimal-item-name">{{detail_invoice.name}}</div>
                    <div class="minimal-item-qty">{{formatNumber(detail_invoice.quantity,2)}} {{ packLineUnit(detail_invoice) }} &times; {{ formatPriceDisplay(detail_invoice.total/detail_invoice.quantity,2) }}</div>
                    <div class="minimal-item-qty" v-if="detail_invoice.pack_name && Number(detail_invoice.pack_multiplier) > 1">(×{{ detail_invoice.pack_multiplier }}) = {{ formatNumber(detail_invoice.quantity * detail_invoice.pack_multiplier, 2) }} {{ detail_invoice.unit_sale || ($t('Pcs') || 'pcs') }}</div>
                    <div class="minimal-item-discount" v-if="pos_settings.show_product_discount !== 0 && Number(detail_invoice.DiscountNet || 0) > 0">{{$t('Discount')}} &minus;{{ formatPriceDisplay(Number(detail_invoice.DiscountNet) * Number(detail_invoice.quantity), 2) }}</div>
                    <div class="minimal-item-qty" v-show="detail_invoice.is_imei && detail_invoice.imei_number !== null">IMEI/SN: {{detail_invoice.imei_number}}</div>
                  </td>
                  <td class="minimal-item-total">{{ formatPriceDisplay(detail_invoice.total,2) }}</td>
                </tr>
              </tbody>
            </table>

            <div class="minimal-divider"></div>

            <table class="minimal-totals">
              <tbody>
                <tr>
                  <td>{{$t('Subtotal')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoiceSubtotal - invoiceDetailsTaxTotal, 2) }}</td>
                </tr>
                <tr v-show="pos_settings.show_tax && Number(invoice_pos.sale.taxe || 0) > 0">
                  <td>{{$t('Tax')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.taxe, 2) }}</td>
                </tr>
                <tr v-show="pos_settings.show_discount">
                  <td>{{$t('Discount')}}</td>
                  <td>
                    <template v-if="String(invoice_pos.sale.discount_Method || '2') === '1'">
                      {{ formatNumber(invoice_pos.sale.discount, 2) }}%
                    </template>
                    <template v-else>
                      {{ formatPriceWithSymbol(invoice_pos.symbol, manualSaleDiscountAmount, 2) }}
                    </template>
                  </td>
                </tr>
                <tr v-show="pos_settings.show_discount && invoice_pos.sale.discount_from_points && Number(invoice_pos.sale.discount_from_points) > 0">
                  <td>{{$t('Discount_from_Points')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.discount_from_points, 2) }}</td>
                </tr>
                <tr v-show="pos_settings.show_shipping">
                  <td>{{$t('Shipping')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.shipping, 2) }}</td>
                </tr>
                <tr class="minimal-grand">
                  <td>{{$t('Total')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.GrandTotal, 2) }}</td>
                </tr>
                <tr v-show="pos_settings.show_paid !== 0">
                  <td>{{$t('Paid')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, invoice_pos.sale.paid_amount, 2) }}</td>
                </tr>
                <tr v-show="pos_settings.show_due !== 0">
                  <td>{{$t('Due')}}</td>
                  <td>{{ formatPriceWithSymbol(invoice_pos.symbol, (invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount), 2) }}</td>
                </tr>
              </tbody>
            </table>

            <table class="minimal-payments" v-show="pos_settings.show_payments !== 0 && invoice_pos.sale.paid_amount > 0">
              <thead>
                <tr>
                  <th>{{$t('PayeBy')}}</th>
                  <th>{{$t('Amount')}}</th>
                  <th>{{$t('Change')}}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="payment_pos in payments">
                  <tr :key="'pay5-' + payment_pos.id">
                    <td>{{payment_pos.payment_method?payment_pos.payment_method.name:'---'}}</td>
                    <td>{{ formatPriceDisplay(payment_pos.montant, 2) }}</td>
                    <td>{{ formatPriceDisplay(payment_pos.change, 2) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>

            <p class="minimal-note" v-show="pos_settings.show_note && pos_settings.note_customer" style="white-space:pre-line;">
              {{pos_settings.note_customer}}
            </p>

            <div
              v-if="(invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0) || (pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref)"
              class="receipt-qr-row mt-2"
            >
              <div
                v-if="invoice_pos.setting && invoice_pos.setting.zatca_enabled && invoice_pos.zatca_qr && pos_settings.show_zatca_qr !== 0"
                class="receipt-qr-block"
              >
                <div class="receipt-qr-title">ZATCA QR</div>
                <div class="receipt-qr-canvas" ref="zatcaQrcode"></div>
              </div>
              <div
                v-if="pos_settings.show_barcode !== 0 && invoice_pos.sale && invoice_pos.sale.Ref"
                class="receipt-qr-block"
              >
                <div class="receipt-qr-title">Invoice QR</div>
                <div class="receipt-qr-canvas" ref="invoiceUrlQr"></div>
              </div>
            </div>
          </div>

        </div>
      </div>
      <button @click="print_it()" class="btn btn-outline-primary mt-3">
        <lucide-icon name="receipt" />
        {{$t('print')}}
      </button>
    </px-modal>

    <!-- Modal Manage Documents -->
    <px-modal v-model="documentsOpen" size="lg" :title="$t('Attach_Documents')">
      <b-row>
        <!-- Upload Section -->
        <b-col lg="12" md="12" sm="12" class="mb-3">
          <b-form-group :label="$t('Upload_Documents')">
            <b-form-file
              v-model="selectedFiles"
              :placeholder="$t('Choose_files_or_drop_them_here')"
              :drop-placeholder="$t('Drop_files_here')"
              multiple
              accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif"
              @change="onFileChange"
            ></b-form-file>
          </b-form-group>
          <b-button
            variant="primary"
            size="sm"
            @click="Upload_Documents"
            :disabled="!selectedFiles || selectedFiles.length === 0 || uploadProcessing"
          >
            <lucide-icon name="upload" /> {{$t('Upload')}}
          </b-button>
          <div v-if="uploadProcessing" class="mt-2">
            <div class="spinner sm spinner-primary"></div>
          </div>
        </b-col>

        <!-- Documents List -->
        <b-col lg="12" md="12" sm="12">
          <h5>{{$t('Attached_Documents')}}</h5>
          <div class="table-responsive">
            <table class="table table-hover table-bordered table-sm">
              <thead>
                <tr>
                  <th scope="col">{{$t('File_Name')}}</th>
                  <th scope="col">{{$t('Size')}}</th>
                  <th scope="col">{{$t('Uploaded_Date')}}</th>
                  <th scope="col">{{$t('Action')}}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="documents.length <= 0">
                  <td colspan="4" class="text-center">{{$t('NodataAvailable')}}</td>
                </tr>
                <tr v-for="document in documents" :key="document.id">
                  <td>
                    <lucide-icon class="mr-1" name="file" />
                    {{document.name}}
                  </td>
                  <td>{{formatFileSize(document.size)}}</td>
                  <td>{{formatDateTime(document.created_at)}}</td>
                  <td>
                    <div role="group" aria-label="Document actions" class="btn-group">
                      <button
                        title="Download"
                        class="btn btn-icon btn-success btn-sm"
                        @click="Download_Document(document)"
                      >
                        <lucide-icon name="download" />
                      </button>
                      <button
                        title="Delete"
                        class="btn btn-icon btn-danger btn-sm"
                        @click="Remove_Document(document.id)"
                      >
                        <lucide-icon name="x" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-col>
      </b-row>
    </px-modal>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import vueEasyPrint from "vue-easy-print";
import VueBarcode from "vue-barcode";
import Util from "../../../../utils";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxMenu from "@/components/px-next/PxMenu.vue";
import PxKebab from "@/components/px-next/PxKebab.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";
export default {
  components: {
    vueEasyPrint,
    barcode: VueBarcode,
    PxEmptyState, PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxMenu,
    PxKebab, PxBadge, PxField, PxInput, PxTextarea, PxModal, "vs-px": VsPx
  },
  metaInfo: {
    title: "Ventas"
  },
  data() {
    return {
      pos_settings:{},
      paymentProcessing: false,
      Submit_Processing_shipment:false,

      

      isLoading: true,
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      search: "",
      _searchTimer: null,
      totalRows: "",
      barcodeFormat: "CODE128",
      showDropdown: false,
      filtersOpen: false,
      invoiceOpen: false,
      addPaymentOpen: false,
      showPaymentOpen: false,
      documentsOpen: false,
      shipmentOpen: false,
      EditPaiementMode: false,
      Filter_Client: "",
      Filter_Ref: "",
      Filter_date: "",
      Filter_status: "",
      Filter_Payment: "",
      Filter_warehouse: "",
      Filter_shipping:"",
      customers: [],
      warehouses: [],
      payment_methods: [],
      shipment: {},
      sales: [],
      sale_due:'',
      due:0,
      client_name:'',
      invoice_pos: {
        sale: {
          Ref: "",
          client_name: "",
          warehouse_name: "",
          discount: "",
          taxe: "",
          tax_rate: "",
          shipping: "",
          GrandTotal: "",
          paid_amount:'',
        },
        details: [],
        setting: {
          logo: "",
          CompanyName: "",
          CompanyAdress: "",
          email: "",
          CompanyPhone: "",
          vat_number: "",
          company_name_ar: "",
          zatca_enabled: false
        },
        zatca_qr: ""
      },
      public_invoice_url: '',
      accounts: [],
      payments: [],
      payment: {},
      zatcaRendered: false,
      Sale_id: "",
      limit: "10",
      sale: {},
      email: {
        to: "",
        subject: "",
        message: "",
        client_name: "",
        Sale_Ref: ""
      },
      emailPayment: {
        id: "",
        to: "",
        subject: "",
        message: "",
        client_name: "",
        Ref: ""
      },
      documents: [],
      selectedFiles: [],
      uploadProcessing: false,
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null,
      currentSaleId: null
    };
  },
   mounted() {
    this.$root.$on("bv::dropdown::show", bvEvent => {
      this.showDropdown = true;
    });
    this.$root.$on("bv::dropdown::hide", bvEvent => {
      this.showDropdown = false;
    });
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),

    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },

    // Sum of per-product VAT (total * tax_percent / 100)
    invoiceDetailsTaxTotal() {
      const details = (this.invoice_pos && Array.isArray(this.invoice_pos.details)) ? this.invoice_pos.details : [];
      return details.reduce((sum, d) => {
        const total = Number(d.total || 0);
        const rate = Number(d.tax_percent || d.tax_rate || 0);
        return sum + (total * rate / 100);
      }, 0);
    },

    // Signed public URL for barcode so scanning opens the invoice (no login required)
    invoiceBarcodeUrl() {
      return this.public_invoice_url || '';
    },

    // Normalize POS receipt layout selection (1, 2, 3, 4, or 5)
    currentReceiptLayout() {
      const raw = this.pos_settings && this.pos_settings.receipt_layout != null
        ? this.pos_settings.receipt_layout
        : 1;
      const n = Number(raw) || 1;
      return [1, 2, 3, 4, 5].includes(n) ? n : 1;
    },

    // Calculate order-level discount amount for invoice display based on discount_Method
    // Manual discount amount only (excluding discount from points)
    manualSaleDiscountAmount() {
      try {
        const sale = (this.invoice_pos && this.invoice_pos.sale) ? this.invoice_pos.sale : {};
        const discMethod = String(sale.discount_Method || '2');
        const discVal = Number(sale.discount || 0);
        const taxNet = Number(sale.taxe || sale.TaxNet || 0);
        const shipping = Number(sale.shipping || 0);
        const grand = Number(sale.GrandTotal || 0);

        // Reconstruct subtotal before discount: subtotal = GrandTotal - shipping - TaxNet
        const subtotal = grand - shipping - taxNet;
        if (!Number.isFinite(subtotal) || subtotal <= 0) {
          return 0;
        }

        if (discMethod === '1') {
          // Percentage discount: use subtotal * %
          return parseFloat((subtotal * (discVal / 100)).toFixed(this.priceDecimals));
        }
        // Fixed discount
        return parseFloat(Math.min(discVal, subtotal).toFixed(this.priceDecimals));
      } catch (e) {
        return 0;
      }
    },

    // Total discount amount (manual + points) – kept for compatibility if needed elsewhere
    saleDiscountAmount() {
      try {
        const sale = (this.invoice_pos && this.invoice_pos.sale) ? this.invoice_pos.sale : {};
        const discMethod = String(sale.discount_Method || '2');
        const discVal = Number(sale.discount || 0);
        const taxNet = Number(sale.taxe || sale.TaxNet || 0);
        const shipping = Number(sale.shipping || 0);
        const grand = Number(sale.GrandTotal || 0);

        // Reconstruct subtotal before discount: subtotal = GrandTotal - shipping - TaxNet
        const subtotal = grand - shipping - taxNet;
        if (!Number.isFinite(subtotal) || subtotal <= 0) {
          return 0;
        }

        if (discMethod === '1') {
          // Percentage discount: use subtotal * %
          return parseFloat((subtotal * (discVal / 100)).toFixed(this.priceDecimals));
        }
        // Fixed discount
        return parseFloat(Math.min(discVal, subtotal).toFixed(this.priceDecimals));
      } catch (e) {
        return 0;
      }
    },

    // Receipt subtotal (sum of invoice detail totals; before order tax/discount/shipping)
    invoiceSubtotal() {
      try {
        const details = (this.invoice_pos && Array.isArray(this.invoice_pos.details)) ? this.invoice_pos.details : [];
        return details.reduce((sum, d) => {
          const n = Number(d && d.total != null ? d.total : 0);
          return sum + (Number.isFinite(n) ? n : 0);
        }, 0);
      } catch (e) {
        return 0;
      }
    },


    columns() {
      return [
        { key: "date", label: this.$t("date"), sortable: true },
        { key: "Ref", label: this.$t("Reference"), sortable: true, strong: true },
        { key: "created_by", label: this.$t("Created_by"), sortable: true },
        { key: "client_name", label: this.$t("Customer"), sortable: true },
        { key: "warehouse_name", label: this.$t("warehouse"), sortable: true },
        { key: "statut", label: this.$t("Status"), sortable: true },
        { key: "GrandTotal", label: this.$t("Total"), sortable: false, align: "right" },
        { key: "paid_amount", label: this.$t("Paid"), sortable: false, align: "right" },
        { key: "due", label: this.$t("Due"), sortable: false, align: "right" },
        { key: "payment_status", label: this.$t("PaymentStatus"), sortable: true },
        { key: "shipping_status", label: this.$t("Shipping_status"), sortable: true },
        { key: "documents", label: this.$t("Documents"), sortable: false }
      ];
    },
    activeFilterCount() {
      return [this.Filter_date, this.Filter_Ref, this.Filter_Client, this.Filter_warehouse, this.Filter_status, this.Filter_Payment, this.Filter_shipping]
        .filter(v => v !== "" && v != null).length;
    },
    exportMenu() {
      return [
        { key: "pdf", label: "PDF de la lista", icon: "file-text" },
        { key: "xlsx", label: "Excel (CSV)", icon: "file-spreadsheet" }
      ];
    }
  },
  watch: {
    'invoice_pos.zatca_qr'(val){
      if(val){
        this.$nextTick(() => { this.renderZatcaQr(); this.renderInvoiceUrlQr(); });
      }
    },
    invoiceOpen(v) {
      if (v) this.$nextTick(() => { setTimeout(() => this.onInvoiceModalShown(), 60); });
    }
  },
  methods: {

  
    //------------------------------ Print -------------------------\\
    // Gated on awaitQrReady() — the popup gets a clone of #invoice-POS via
    // innerHTML, and a <canvas>'s pixel data does not survive that clone.
    // Only the data-URL <img> created by ensureQrImg() does, so we must
    // wait for that conversion before snapshotting (otherwise the printed
    // QR is blank, especially on slow mobile networks where the qrcodejs
    // CDN takes longer to load).
    print_it() {
      this.awaitQrReady().then(() => {
        var el = document.getElementById("invoice-POS");
        if (!el) return;
        var divContents = el.innerHTML;
        var a = window.open("", "", "height=500, width=500");
        if (!a) return;
        a.document.write(
          '<html><head><link rel="stylesheet" href="/css/pos_print.css"></head>'
        );
        // Wrap in #invoice-POS so all the receipt CSS in pos_print.css
        // (which is scoped under that id, including the .receipt-qr-row
        // QR layout rules) actually applies to the printed content.
        a.document.write('<body><div id="invoice-POS">');
        a.document.write(divContents);
        a.document.write("</div></body></html>");
        a.document.close();

        setTimeout(() => {
          a.print();
        }, 300);
      });
    },


    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    // ---- px-next list events
    shipLabel(s) {
      const m = { ordered: this.$t("Ordered"), packed: this.$t("Packed"), shipped: this.$t("Shipped"), delivered: this.$t("Delivered"), cancelled: this.$t("Cancelled") };
      return m[s] || s;
    },
    shipTone(s) {
      const m = { ordered: "warning", packed: "info", shipped: "neutral", delivered: "success", cancelled: "danger" };
      return m[s] || "neutral";
    },
    rowActions(row) {
      const p = this.currentUserPermissions || [];
      const items = [{ key: "view", label: this.$t("SaleDetail"), icon: "eye" }];
      // POS-ONLY MANUAL SALES — a POS sale (is_pos = 1) cannot be edited as a
      // full transaction; historical admin sales (is_pos = 0) keep "Editar venta".
      if (p.includes("Sales_edit") && row.sale_has_return == "no" && Number(row.is_pos) !== 1) items.push({ key: "edit", label: this.$t("EditSale"), icon: "pencil" });
      if (p.includes("Sale_Returns_add") && row.sale_has_return == "no" && row.statut == "completed") items.push({ key: "return", label: this.$t("Sell_Return"), icon: "arrow-left" });
      if (p.includes("Sale_Returns_add") && row.sale_has_return == "yes") items.push({ key: "return_edit", label: this.$t("Sell_Return"), icon: "arrow-left" });
      if (p.includes("payment_sales_view")) items.push({ key: "showpay", label: this.$t("ShowPayment"), icon: "wallet" });
      if (p.includes("payment_sales_add") && row.statut == "completed") items.push({ key: "addpay", label: this.$t("AddPayment"), icon: "plus" });
      if (p.includes("shipment")) items.push({ key: "shipedit", label: this.$t("Edit_Shipping"), icon: "truck" });
      items.push({ key: "invoice", label: this.$t("Invoice_POS"), icon: "file-text" });
      items.push({ key: "pdf", label: this.$t("DownloadPdf"), icon: "file-text" });
      items.push({ key: "whatsapp", label: "WhatsApp", icon: "message-circle" });
      items.push({ key: "email", label: this.$t("email_notification"), icon: "mail" });
      items.push({ key: "sms", label: this.$t("sms_notification"), icon: "message-square" });
      items.push({ key: "docs", label: this.$t("Attach_Documents"), icon: "file" });
      if (p.includes("Sales_delete") && row.fiscal_status === "issued") items.push({ key: "voidsar", label: "Anular factura SAR", icon: "file-x-2", tone: "danger" });
      if (p.includes("Sales_delete") && !row.fiscal_number) items.push({ key: "delete", label: this.$t("DeleteSale"), icon: "x", tone: "danger" });
      return items;
    },
    onRowAction(row, item) {
      const k = item && item.key;
      if (k === "view") this.$router.push("/app/sales/detail/" + row.id);
      else if (k === "edit") this.$router.push("/app/sales/edit/" + row.id);
      else if (k === "return") this.$router.push("/app/sales/sale_return/" + row.id);
      else if (k === "return_edit") this.$router.push("/app/sale_return/edit/" + row.salereturn_id + "/" + row.id);
      else if (k === "showpay") this.Show_Payments(row.id, row);
      else if (k === "addpay") this.New_Payment(row);
      else if (k === "shipedit") this.Edit_Shipment(row.id);
      else if (k === "invoice") this.Invoice_POS(row.id);
      else if (k === "pdf") this.Invoice_PDF(row, row.id);
      else if (k === "whatsapp") this.Send_WhatsApp(row.id);
      else if (k === "email") this.Send_Email(row.id);
      else if (k === "sms") this.Sale_SMS(row.id);
      else if (k === "docs") this.Manage_Documents(row.id);
      else if (k === "voidsar") this.Void_Sar_Invoice(row);
      else if (k === "delete") this.Remove_Sale(row.id, row.sale_has_return);
    },
    onSearchInput(v) {
      this.search = v;
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.updateParams({ page: 1 }); this.Get_Sales(1); }, 350);
    },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.Get_Sales(p); } },
    onLimit(v) { if (this.limit !== String(v)) { this.limit = String(v); this.updateParams({ page: 1, perPage: Number(v) }); this.Get_Sales(1); } },
    onSort({ key, dir }) {
      let field = key;
      if (key === "client_name") field = "client_id";
      else if (key === "warehouse_name") field = "warehouse_id";
      else if (key === "created_by") field = "user_id";
      this.updateParams({ sort: { type: dir, field: field } });
      this.Get_Sales(this.serverParams.page);
    },
    applyFilters() { this.updateParams({ page: 1 }); this.Get_Sales(this.serverParams.page); },
    onExport(item) {
      const k = item && item.key;
      if (k === "pdf") this.Sales_PDF();
      else if (k === "xlsx") this.exportCsv();
    },
    exportCsv() {
      const head = [this.$t("date"), this.$t("Reference"), this.$t("Customer"), this.$t("warehouse"), this.$t("Status"), this.$t("Total"), this.$t("Paid"), this.$t("Due"), this.$t("PaymentStatus")];
      const lines = [head.join(",")].concat(
        (this.sales || []).map(r =>
          [r.date, r.Ref, r.client_name, r.warehouse_name, r.statut, r.GrandTotal, r.paid_amount, r.due, r.payment_status]
            .map(c => `"${String(c == null ? "" : c).replace(/"/g, '""')}"`).join(",")
        )
      );
      const blob = new Blob(["﻿" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.setAttribute("download", "Sales.csv");
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },
    syncPaymentValidators() {
      this.$nextTick(() => {
        if (this.$refs.pDateProvider) this.$refs.pDateProvider.syncValue(this.payment.date);
        if (this.$refs.pMethodProvider) this.$refs.pMethodProvider.syncValue(this.payment.payment_method_id);
        if (this.$refs.pRecvProvider) this.$refs.pRecvProvider.syncValue(this.payment.received_amount);
        if (this.$refs.pAmtProvider) this.$refs.pAmtProvider.syncValue(this.payment.montant);
      });
    },

    //---- Event Page Change
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Sales(currentPage);
      }
    },

    //---- Event Per Page Change
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Sales(1);
      }
    },

    //---- Event Select Rows
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach((row, index) => {
        this.selectedIds.push(row.id);
      });
    },

    //---- Event Sort change
    onSortChange(params) {
      let field = "";
      if (params[0].field == "client_name") {
        field = "client_id";
      } else if (params[0].field == "warehouse_name") {
        field = "warehouse_id";
      }else if (params[0].field == "created_by") {
        field = "user_id";
      } else {
        field = params[0].field;
      }
      this.updateParams({
        sort: {
          type: params[0].type,
          field: field
        }
      });
      this.Get_Sales(this.serverParams.page);
    },

    
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Sales(this.serverParams.page);
    },

     //---------- keyup paid Amount

    Verified_paidAmount() {
      if (isNaN(this.payment.montant)) {
        this.payment.montant = 0;
      } else if (this.payment.montant > this.payment.received_amount) {
        this.makeToast(
          "warning",
          this.$t("Paying_amount_is_greater_than_Received_amount"),
          this.$t("Warning")
        );
        this.payment.montant = 0;
      } 
      else if (this.payment.montant > this.due) {
        this.makeToast(
          "warning",
          this.$t("Paying_amount_is_greater_than_Grand_Total"),
          this.$t("Warning")
        );
        this.payment.montant = 0;
      }
    },

    //---------- keyup Received Amount

    Verified_Received_Amount() {
      if (isNaN(this.payment.received_amount)) {
        this.payment.received_amount = 0;
      } 
    },


    //------ Validate Form Submit_Payment
    Submit_Payment() {
      this.$refs.Add_payment.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else if (this.payment.montant > this.payment.received_amount) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Received_amount"),
            this.$t("Warning")
          );
          this.payment.received_amount = 0;
        }
        else if (this.payment.montant > this.due) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Grand_Total"),
            this.$t("Warning")
          );
          this.payment.montant = 0;

        }else if (!this.EditPaiementMode) {
            this.Create_Payment();
        } else {
            this.Update_Payment();
        }

      });
    },


    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },
    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_Client = "";
      this.Filter_status = "";
      this.Filter_Payment = "";
      this.Filter_shipping = "";
      this.Filter_Ref = "";
      this.Filter_date = "";
      this.Filter_warehouse = "";
      this.Get_Sales(this.serverParams.page);
    },
    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    // Multi-Pack Selling: show the pack name as the line unit when a real pack
    // (×>1) was sold; otherwise the product's base sale unit.
    packLineUnit(d){
      return (d && d.pack_name && Number(d.pack_multiplier) > 1) ? d.pack_name : (d.unit_sale || '');
    },

    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing formatNumber helper to preserve current behavior.
    formatPriceDisplay(number, dec) {
      try {
        // Money formatter: always honour the configured price precision (2 or 3),
        // ignoring any legacy `dec` argument from templates.
        const decimals = this.priceDecimals;
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(number, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

    //----------------------------------------- Format File Size -------------------------------\\
    formatFileSize(bytes) {
      if (bytes === 0 || bytes === null || bytes === undefined) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },

    //----------------------------------------- Format Date Time -------------------------------\\
    formatDateTime(value) {
      if (!value) return '';
      const d = new Date(value);
      if (isNaN(d.getTime())) return value;

      const pad = n => (n < 10 ? '0' + n : n);
      const year = d.getFullYear();
      const month = pad(d.getMonth() + 1);
      const day = pad(d.getDate());
      const hours = pad(d.getHours());
      const minutes = pad(d.getMinutes());

      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },
    //----------------------------------------- Format Display Date (for tables) -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      // Get date format from Vuex store (loaded from database) or fallback
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    //----------------------------------- Sales PDF ------------------------------\\
    
    Sales_PDF() {
      const pdf = new jsPDF('p','pt');
      const fontPath = '/fonts/Vazirmatn-Bold.ttf';
      try { 
        pdf.addFont(fontPath,'Vazirmatn','normal'); 
        pdf.addFont(fontPath,'Vazirmatn','bold'); 
      } catch(e){}
      pdf.setFont('Vazirmatn','normal');

      const headers = [ 
        this.$t('Reference'), 
        this.$t('Customer'), 
        this.$t('warehouse'), 
        this.$t('Status'), 
        this.$t('Total'), 
        this.$t('Paid'), 
        this.$t('Due'), 
        this.$t('PaymentStatus') 
      ];
      
      const body = (this.sales||[]).map(r => [ 
        r.Ref, 
        r.client_name, 
        r.warehouse_name, 
        r.statut, 
        r.GrandTotal, 
        r.paid_amount, 
        r.due, 
        r.payment_status 
      ]);

      const totals = (this.sales||[]).reduce((a,r) => ({
        t: a.t + parseFloat(r.GrandTotal||0),
        p: a.p + parseFloat(r.paid_amount||0),
        d: a.d + parseFloat(r.due||0)
      }), {t:0,p:0,d:0});
      
      const foot = [[ 
        this.$t('Total'), 
        '', 
        '', 
        '', 
        totals.t.toFixed(this.priceDecimals),
        totals.p.toFixed(this.priceDecimals),
        totals.d.toFixed(this.priceDecimals),
        ''
      ]];

      const marginX = 40;
      const rtl = (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) || 
                  (typeof document!=='undefined' && document.documentElement.dir==='rtl');

      autoTable(pdf, {
        head: [headers], 
        body, 
        foot: foot, 
        startY: 110, 
        theme: 'striped', 
        margin: { left: marginX, right: marginX },
        styles: { 
          font: 'Vazirmatn', 
          fontSize: 9, 
          cellPadding: 4, 
          halign: rtl ? 'right' : 'left', 
          textColor: 33 
        },
        headStyles: { 
          font: 'Vazirmatn', 
          fontStyle: 'bold', 
          fillColor: [63,81,181], 
          textColor: 255 
        },
        alternateRowStyles: { 
          fillColor: [245,247,250] 
        },
        footStyles: { 
          font: 'Vazirmatn', 
          fontStyle: 'bold', 
          fillColor: [63,81,181], 
          textColor: 255 
        },
        columnStyles: { 
          0: { halign: rtl ? 'right' : 'left' },  // Reference
          1: { halign: rtl ? 'right' : 'left' },  // Customer
          2: { halign: rtl ? 'right' : 'left' },  // Warehouse
          3: { halign: rtl ? 'right' : 'left' },  // Status
          4: { halign: 'left' },                  // Total
          5: { halign: 'left' },                  // Paid
          6: { halign: 'left' },                  // Due
          7: { halign: rtl ? 'right' : 'left' }   // Payment Status
        },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();
          
          // Header banner
          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');
          
          // Title
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = this.$t('ListSales') || 'Sales List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' }) 
              : pdf.text(title, marginX, 38);
          
          // Reset text color
          pdf.setTextColor(33);
          
          // Footer page numbers
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' }) 
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save('Sales_List.pdf');
    },


    async Void_Sar_Invoice(sale) {
      const result = await this.$swal({
        title: "Anular factura SAR",
        text: "El número fiscal se conservará y no podrá volver a utilizarse.",
        input: "textarea",
        inputPlaceholder: "Escribe el motivo de la anulación",
        type: "warning",
        showCancelButton: true,
        confirmButtonText: "Anular factura",
        cancelButtonText: "Cancelar",
        inputValidator: value => {
          if (!value || value.trim().length < 5) {
            return "Debes escribir un motivo de al menos 5 caracteres.";
          }
          return null;
        }
      });
      if (!result.value) return;

      try {
        await axios.post("sales/" + sale.id + "/sar-void", { reason: result.value.trim() });
        this.makeToast("success", "Factura fiscal anulada correctamente.", "Éxito");
        await this.Get_Sales(this.serverParams.page);
      } catch (error) {
        const data = error.response && error.response.data;
        this.makeToast("danger", (data && data.message) || "No se pudo anular la factura fiscal.", "Error");
      }
    },

    //-------------------------------- Invoice POS ------------------------------\\
    Invoice_POS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("sales_print_invoice/" + id)
        .then(response => {
          this.invoice_pos = response.data;
          this.payments = response.data.payments;
          this.pos_settings = response.data.pos_settings;
          this.public_invoice_url = response.data.public_invoice_url || '';
          this.zatcaRendered = false;
          // Auto-print is gated on awaitQrReady() so the popup snapshot is
          // taken AFTER canvas → <img> conversion — otherwise the canvas
          // pixels are lost on innerHTML clone and the QR prints blank.
          const autoPrintable = !!(response.data.pos_settings && response.data.pos_settings.is_printable);
          setTimeout(() => {
            // Complete the animation of the  progress bar.
            NProgress.done();
            this.invoiceOpen = true;
            this.$nextTick(() => {
              const qrReady = this.awaitQrReady();
              if (autoPrintable) {
                qrReady.then(() => { try { this.print_it(); } catch (e) {} });
              }
            });
          }, 500);

        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //---------------------------------- Get_pos_Settings ----------------\\
    get_pos_Settings() {
      axios
        .get("get_pos_Settings")
        .then(response => {
          if (response.data && response.data.pos_settings) {
            this.pos_settings = response.data.pos_settings;
          }
        })
        .catch(error => {
          // Silently fail if settings can't be loaded
        });
    },

    onInvoiceModalShown() {
      try { this.renderZatcaQr(); } catch (e) {}
      try { this.renderInvoiceUrlQr(); } catch (e) {}
    },

    // Loads the qrcodejs library exactly once across all callers.
    // Returns a Promise that resolves when window.QRCode is available
    // (or all sources have been exhausted). Cached on the instance so
    // overlapping render calls share one in-flight script load.
    // Sanity-check window.QRCode by encoding a non-trivial string and
    // verifying the canvas actually has dark pixels. Detects the broken
    // minimal build previously shipped at /vendor/qrcode/qrcode.min.js
    // (its mapData was missing bit-index increments → all-white canvas).
    _qrLibIsWorking() {
      try {
        if (!window.QRCode) return false;
        if (this._qrLibVerified) return true; // memoized per instance
        const probe = document.createElement('div');
        probe.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
        document.body.appendChild(probe);
        try {
          new window.QRCode(probe, {
            text: 'https://example.com/test/abcdefghij',
            width: 32, height: 32,
            correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.L : 1
          });
          const cv = probe.querySelector('canvas');
          if (!cv) return false;
          const data = cv.getContext('2d').getImageData(0, 0, cv.width, cv.height).data;
          let darkPixels = 0;
          for (let i = 0; i < data.length; i += 4) {
            if (data[i] < 128 && data[i + 1] < 128 && data[i + 2] < 128) {
              if (++darkPixels > 16) break;
            }
          }
          this._qrLibVerified = darkPixels > 16;
          return this._qrLibVerified;
        } finally {
          try { document.body.removeChild(probe); } catch (e) {}
        }
      } catch (e) { return false; }
    },

    loadQRCodeLib() {
      if (window.QRCode && this._qrLibIsWorking()) return Promise.resolve();
      // window.QRCode may be the old broken local lib cached from a
      // previous page visit — discard it so a known-good source replaces it.
      if (window.QRCode) {
        try { delete window.QRCode; } catch (e) { window.QRCode = undefined; }
        this._qrLibVerified = false;
      }
      if (this._qrLoaderPromise) return this._qrLoaderPromise;
      const tryLoad = (src, next) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = () => { if (window.QRCode) next(true); else next(false); };
        s.onerror = () => next(false);
        document.head.appendChild(s);
      };
      // The local /vendor/qrcode/qrcode.min.js previously shipped a broken
      // minimal build (missing bit-index increments in mapData → blank
      // canvas). We replaced it with the full davidshimjs/qrcodejs
      // library. The cache-buster forces mobile browsers to fetch the new
      // version instead of the cached broken one.
      const localQrUrl = '/vendor/qrcode/qrcode.min.js?v=full-2';
      // After each load attempt, validate the library actually produces QR
      // pixels — otherwise fall through to the next source. Catches a
      // corrupted CDN response or any future broken minimal build.
      const validateOrFalse = () => {
        if (!window.QRCode) return false;
        this._qrLibVerified = false;
        return this._qrLibIsWorking();
      };
      const discardBroken = () => {
        try { delete window.QRCode; } catch (e) { window.QRCode = undefined; }
        this._qrLibVerified = false;
      };
      this._qrLoaderPromise = new Promise((resolve) => {
        tryLoad('https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', (ok) => {
          if (ok && validateOrFalse()) return resolve();
          if (window.QRCode) discardBroken();
          tryLoad(localQrUrl, (ok2) => {
            if (ok2 && validateOrFalse()) return resolve();
            if (window.QRCode) discardBroken();
            tryLoad('/assets_setup/js/qrcode.js', () => resolve());
          });
        });
      }).then(() => {
        // Allow a future retry if no working library was ever obtained.
        if (!window.QRCode) this._qrLoaderPromise = null;
      });
      return this._qrLoaderPromise;
    },

    // Render public invoice URL as QR code (replaces barcode).
    // Returns a Promise that resolves when the <img> data URL is in the DOM
    // (or when there is nothing to render). Callers that intend to clone
    // #invoice-POS via innerHTML for printing must await this — canvas pixel
    // data is lost on innerHTML clone, only the <img> survives.
    renderInvoiceUrlQr() {
      return new Promise((resolve) => {
        try {
          if (this.pos_settings && Number(this.pos_settings.show_barcode) === 0) return resolve();
          if (!this.invoice_pos || !this.invoice_pos.sale || !this.invoice_pos.sale.Ref) return resolve();
          if (!this.$refs.invoiceUrlQr) return resolve();
          const text = String(this.invoiceBarcodeUrl || this.invoice_pos.sale.Ref || '');
          if (!text) return resolve();

          // Per-mount render token: each call wins, older pending draws bail.
          this._invoiceQrToken = (this._invoiceQrToken || 0) + 1;
          const myToken = this._invoiceQrToken;

          this.loadQRCodeLib().then(() => {
            if (myToken !== this._invoiceQrToken) return resolve();
            if (!window.QRCode) return resolve();
            const m = this.$refs.invoiceUrlQr;
            if (!m) return resolve();
            m.innerHTML = '';
            try { m.setAttribute('title', text); } catch (e) {}
            try {
              new window.QRCode(m, {
                text,
                width: 140,
                height: 140,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.L : undefined
              });
            } catch (e1) {
              try { new window.QRCode(m, text); } catch (e2) {}
            }
            setTimeout(() => {
              if (myToken !== this._invoiceQrToken) return resolve();
              try { this.ensureQrImg(m); } catch (e) {}
              resolve();
            }, 150);
          }).catch(() => resolve());
        } catch (e) { resolve(); }
      });
    },

    // Ensure the QR mount contains an <img> (data URL) — qrcode.js' CDN build
    // emits one already, but the local minimal build only emits a <canvas>,
    // and a <canvas>'s pixel data is lost when the receipt is cloned via
    // innerHTML into the print popup. The <img> survives that clone.
    ensureQrImg(mount) {
      if (!mount) return;
      let img = null;
      try { img = mount.querySelector('img'); } catch (e) {}
      if (!img) {
        let canvas = null;
        try { canvas = mount.querySelector('canvas'); } catch (e) {}
        if (canvas) {
          try {
            const dataUrl = canvas.toDataURL('image/png');
            img = document.createElement('img');
            img.src = dataUrl;
            img.alt = 'QR';
            mount.appendChild(img);
          } catch (e) {}
        }
      }
      if (img) {
        try {
          img.style.display = '';
          img.style.marginLeft = 'auto';
          img.style.marginRight = 'auto';
        } catch (e) {}
      }
    },

    // Render ZATCA QR code if enabled.
    // Returns a Promise — see renderInvoiceUrlQr for why callers must await
    // this before snapshotting #invoice-POS for the print popup.
    renderZatcaQr() {
      return new Promise((resolve) => {
        try {
          if (!this.invoice_pos || !this.invoice_pos.setting || !this.invoice_pos.setting.zatca_enabled || !this.invoice_pos.zatca_qr) return resolve();
          if (!this.$refs.zatcaQrcode) return resolve();
          const text = String(this.invoice_pos.zatca_qr || '');
          if (!text) return resolve();

          this._zatcaQrToken = (this._zatcaQrToken || 0) + 1;
          const myToken = this._zatcaQrToken;

          this.loadQRCodeLib().then(() => {
            if (myToken !== this._zatcaQrToken) return resolve();
            if (!window.QRCode) return resolve();
            const m = this.$refs.zatcaQrcode;
            if (!m) return resolve();
            m.innerHTML = '';
            try { m.setAttribute('title', text); } catch (e) {}
            try {
              new window.QRCode(m, {
                text,
                width: 180,
                height: 180,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.L : undefined
              });
            } catch (e1) {
              try { new window.QRCode(m, text); } catch (e2) {}
            }
            this.zatcaRendered = true;
            setTimeout(() => {
              if (myToken !== this._zatcaQrToken) return resolve();
              if (m && !m.childNodes.length && window.QRCode) {
                try { new window.QRCode(m, text); } catch (e) {}
              }
              try { this.ensureQrImg(m); } catch (e) {}
              resolve();
            }, 150);
          }).catch(() => resolve());
        } catch (e) { resolve(); }
      });
    },

    // Resolve once both QR mounts (Invoice URL + ZATCA) have an <img> in the
    // DOM. Used to gate print so the popup snapshot is not taken before
    // canvas → <img> conversion completes.
    awaitQrReady() {
      const a = (() => { try { return this.renderInvoiceUrlQr(); } catch (e) { return Promise.resolve(); } })();
      const b = (() => { try { return this.renderZatcaQr(); } catch (e) { return Promise.resolve(); } })();
      return Promise.all([
        Promise.resolve(a).catch(() => {}),
        Promise.resolve(b).catch(() => {})
      ]);
    },

    //-----------------------------  Invoice PDF ------------------------------\\
    Invoice_PDF(sale, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
       axios
        .get("sale_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Sale-" + sale.Ref + ".pdf");
          document.body.appendChild(link);
          link.click();
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    Send_WhatsApp(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("sales_send_whatsapp", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);

          var phone = response.data.phone;
          var message = response.data.message;

          // Encode phone number and message
          var encodedPhone = encodeURIComponent(phone);
          var encodedMessage = encodeURIComponent(message);

          // Create WhatsApp URL
          var whatsappUrl = `https://web.whatsapp.com/send/?phone=${encodedPhone}&text=${encodedMessage}`;

          // Open the WhatsApp URL in a new window
          window.open(whatsappUrl, '_blank');
          
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", "Failed to send the Message", this.$t("Failed"));
        });
    },

    //------------------------ Payments Sale PDF ------------------------------\\
    Payment_Sale_PDF(payment, id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
     
      axios
        .get("payment_sale_pdf/" + id, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "Payment-" + payment.Ref + ".pdf");
          document.body.appendChild(link);
          link.click();
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },
    //---------------------------------------- Set To Strings-------------------------\\
    setToStrings() {
      // Simply replaces null values with strings=''
      if (this.Filter_Client === null) {
        this.Filter_Client = "";
      } else if (this.Filter_warehouse === null) {
        this.Filter_warehouse = "";
      } else if (this.Filter_status === null) {
        this.Filter_status = "";
      } else if (this.Filter_Payment === null) {
        this.Filter_Payment = "";
      }else if (this.Filter_shipping === null) {
        this.Filter_shipping = "";
      }
    },
    //----------------------------------------- Get all Sales ------------------------------\\
    Get_Sales(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "sales?page=" +
            page +
            "&Ref=" +
            this.Filter_Ref +
            "&date=" +
            this.Filter_date +
            "&client_id=" +
            this.Filter_Client +
            "&statut=" +
            this.Filter_status +
            "&warehouse_id=" +
            this.Filter_warehouse +
            "&payment_statut=" +
            this.Filter_Payment +
            "&shipping_status=" +
            this.Filter_shipping +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.sales = response.data.sales;
          this.customers = response.data.customers;
          this.accounts = response.data.accounts;
          this.warehouses = response.data.warehouses;
          this.payment_methods = response.data.payment_methods;
          this.totalRows = response.data.totalRows;
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------SMS notification
     Payment_Sale_SMS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment_sale_send_sms", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("sms_send_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("sms_config_invalid"), this.$t("Failed"));
        });
    },


    Send_Email_Payment(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("payment_sale_send_email", {
          id: id,
         
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("SendEmail"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("SMTPIncorrect"), this.$t("Failed"));
        });
    },

    Send_Email(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("sales_send_email", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("SendEmail"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("SMTPIncorrect"), this.$t("Failed"));
        });
    },

      //---------SMS notification
     Sale_SMS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("sales_send_sms", {
          id: id,
        })
        .then(response => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast(
            "success",
            this.$t("sms_send_successfully"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("sms_config_invalid"), this.$t("Failed"));
        });
    },


    Number_Order_Payment() {
      axios
        .get("payment_sale_get_number")
        .then(({ data }) => (this.payment.Ref = data));
    },


    //----------------------------------- New Payment Sale ------------------------------\\
    New_Payment(sale) {
      if (sale.payment_status == "paid") {
        this.$swal({
          icon: "error",
          title: "Oops...",
          text: this.$t("PaymentComplete")
        });
      } else {
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        this.reset_form_payment();
        this.EditPaiementMode = false;
        this.sale = sale;
        this.payment.date = new Date().toISOString().slice(0, 10);
        this.Number_Order_Payment();
        this.payment.montant = sale.due;
        this.payment.payment_method_id = 2;
        this.payment.received_amount = sale.due;
        this.due = parseFloat(sale.due);
        this.client_name = sale.client_name;
        setTimeout(() => {
          // Complete the animation of the  progress bar.
          NProgress.done();
          this.addPaymentOpen = true;
        }, 500);
        this.syncPaymentValidators();
      }
    },
    //------------------------------------Edit Payment ------------------------------\\
    Edit_Payment(payment) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.EditPaiementMode = true;

      this.payment.id        = payment.id;
      this.payment.Ref       = payment.Ref;
      this.payment.payment_method_id = payment.payment_method_id;
      this.payment.account_id = payment.account_id;
      this.payment.date    = payment.date;
      this.payment.change  = payment.change;
      this.payment.montant = payment.montant;
      this.payment.received_amount = parseFloat(payment.montant + payment.change).toFixed(this.priceDecimals);
      this.payment.notes   = payment.notes;

      this.due = parseFloat(this.sale_due) + payment.montant;
      setTimeout(() => {
        // Complete the animation of the  progress bar.
        NProgress.done();
        this.addPaymentOpen = true;
      }, 1000);
        this.syncPaymentValidators();
     
    },
    //-------------------------------Show All Payment with Sale ---------------------\\
    Show_Payments(id, sale) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.reset_form_payment();
      this.Sale_id = id;
      this.sale = sale;
      this.client_name = sale.client_name;
      this.Get_Payments(id);
    },
    //----------------------------------Process Payment (Mode Create) ------------------------------\\
    async processPayment_Create() {
      // Legacy helper retained; Stripe processing removed, use Create_Payment instead.
      return this.Create_Payment();
    },

    //----------------------------------Create Payment sale ------------------------------\\
    Create_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
        axios
          .post("payment_sale", {
            sale_id: this.sale.id,
            date: this.payment.date,
            montant: parseFloat(this.payment.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.payment.received_amount).toFixed(this.priceDecimals),
            change: parseFloat(this.payment.received_amount - this.payment.montant).toFixed(this.priceDecimals),
            payment_method_id: this.payment.payment_method_id,
            account_id: this.payment.account_id,
            notes: this.payment.notes,
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Create_Facture_sale");
            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
              this.$t("Success")
            );
          })
          .catch(error => {
            this.paymentProcessing = false;
            NProgress.done();
          });
    },
    //---------------------------------------- Update Payment ------------------------------\\
    Update_Payment() {
      this.paymentProcessing = true;
      NProgress.start();
      NProgress.set(0.1);
      
        axios
          .put("payment_sale/" + this.payment.id, {
            sale_id: this.sale.id,
            date: this.payment.date,
            montant: parseFloat(this.payment.montant).toFixed(this.priceDecimals),
            received_amount: parseFloat(this.payment.received_amount).toFixed(this.priceDecimals),
            change: parseFloat(this.payment.received_amount - this.payment.montant).toFixed(this.priceDecimals),
            payment_method_id: this.payment.payment_method_id,
            account_id: this.payment.account_id,
            notes: this.payment.notes
          })
          .then(response => {
            this.paymentProcessing = false;
            Fire.$emit("Update_Facture_sale");
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );
          })
          .catch(error => {
            this.paymentProcessing = false;
            NProgress.done();
          });
    },
    //----------------------------------------- Remove Payment ------------------------------\\
    Remove_Payment(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("payment_sale/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_Facture_sale");
            })
            .catch(() => {
              // Complete the animation of the  progress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },
    //----------------------------------------- Get Payments  -------------------------------\\
    Get_Payments(id) {
      axios
        .get("get_payments_by_sale/" + id)
        .then(response => {
          this.payments = response.data.payments;
          this.sale_due = response.data.due;
          setTimeout(() => {
            // Complete the animation of the  progress bar.
            NProgress.done();
            this.showPaymentOpen = true;
          }, 500);
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },
    //------------------------------------------ Reset Form Payment ------------------------------\\
    reset_form_payment() {
      this.due = 0;
      this.payment = {
        id: "",
        Sale_id: "",
        date: "",
        Ref: "",
        montant: "",
        received_amount: "",
        payment_method_id: "",
        account_id: "",
        notes: ""
      };
    },

    //----------------------------------------- Manage Documents -------------------------------\\
    Manage_Documents(saleId) {
      this.currentSaleId = saleId;
      this.selectedFiles = [];
      NProgress.start();
      NProgress.set(0.1);
      this.Get_Documents(saleId);
    },

    //----------------------------------------- Get Documents -------------------------------\\
    Get_Documents(saleId) {
      axios
        .get("sales/" + saleId + "/documents")
        .then(response => {
          this.documents = response.data.documents || [];
          setTimeout(() => {
            NProgress.done();
            this.documentsOpen = true;
          }, 500);
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_load_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- On File Change -------------------------------\\
    onFileChange(event) {
      this.selectedFiles = event.target.files || [];
    },

    //----------------------------------------- Upload Documents -------------------------------\\
    Upload_Documents() {
      if (!this.selectedFiles || this.selectedFiles.length === 0) {
        this.makeToast("warning", this.$t("Please_select_files"), this.$t("Warning"));
        return;
      }

      this.uploadProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      const formData = new FormData();
      for (let i = 0; i < this.selectedFiles.length; i++) {
        formData.append('documents[]', this.selectedFiles[i]);
      }
      formData.append('sale_id', this.currentSaleId);

      axios
        .post("sales/" + this.currentSaleId + "/documents", formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        })
        .then(response => {
          this.uploadProcessing = false;
          this.selectedFiles = [];
          this.Get_Documents(this.currentSaleId);
          this.Get_Sales(this.serverParams.page);
          this.makeToast("success", this.$t("Documents_uploaded_successfully"), this.$t("Success"));
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          this.uploadProcessing = false;
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_upload_documents"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Download Document -------------------------------\\
    Download_Document(doc) {
      NProgress.start();
      NProgress.set(0.1);
      
      axios
        .get("sales/documents/" + doc.id + "/download", {
          responseType: "blob"
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = window.document.createElement("a");
          link.href = url;
          link.setAttribute("download", doc.name);
          window.document.body.appendChild(link);
          link.click();
          window.document.body.removeChild(link);
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(error => {
          setTimeout(() => NProgress.done(), 500);
          this.makeToast("danger", this.$t("Failed_to_download_document"), this.$t("Failed"));
        });
    },

    //----------------------------------------- Remove Document -------------------------------\\
    Remove_Document(documentId) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("sales/documents/" + documentId)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              this.Get_Documents(this.currentSaleId);
              this.Get_Sales(this.serverParams.page);
              setTimeout(() => NProgress.done(), 500);
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },

     //---------------------- Get_Data_Create  ------------------------------\\

      Get_shipment_by_sale(sale_id) {
        axios
            .get("/shipments/" + sale_id)
            .then(response => {
                this.shipment   = response.data.shipment;

                 setTimeout(() => {
                    NProgress.done();
                    this.shipmentOpen = true;
                    this.$nextTick(() => { if (this.$refs.shipStatusProvider) this.$refs.shipStatusProvider.syncValue(this.shipment.status); });
                }, 1000);
            })
            .catch(error => {
              NProgress.done();
                
            });
    },

      //------------- Submit Validation Edit shipment
      Submit_Shipment() {
      this.$refs.shipment_ref.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Shipment();
        }
      });
    },

      //----------------------- Update_Shipment ---------------------------\\
    Update_Shipment() {
      var self = this;
      self.Submit_Processing_shipment = true;
      axios
        .post("shipments", {
          Ref: self.shipment.Ref,
          sale_id: self.shipment.sale_id,
          shipping_address: self.shipment.shipping_address,
          delivered_to: self.shipment.delivered_to,
          shipping_details: self.shipment.shipping_details,
          status: self.shipment.status
        })
        .then(response => {
          this.makeToast(
            "success",
            this.$t("Updated_in_successfully"),
            this.$t("Success")
          );
          Fire.$emit("event_update_shipment");
          self.Submit_Processing_shipment = false;
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          self.Submit_Processing_shipment = false;
        });
    },


     //------------------------------ Show Modal (Edit shipment) -------------------------------\\
    Edit_Shipment(sale_id) {
      NProgress.start();
      NProgress.set(0.1);
      this.reset_Form_shipment();
      this.Get_shipment_by_sale(sale_id);
    },

      //-------------------------------- Reset Form -------------------------------\\
    reset_Form_shipment() {
      this.shipment = {
        id: "",
        date: "",
        Ref: "",
        sale_id: "",
        attachment: "",
        delivered_to: "",
        shipping_address: "",
        status: "",
        shipping_details: ""
      };
    },

    //------------------------------------------ Remove Sale ------------------------------\\
    Remove_Sale(id , sale_has_return) {
      if(sale_has_return == 'yes'){
        this.makeToast("danger", this.$t("Return_exist_for_the_Transaction"), this.$t("Failed"));
      }else{
        this.$swal({
          title: this.$t("Delete_Title"),
          text: this.$t("Delete_Text"),
          type: "warning",
          showCancelButton: true,
          confirmButtonColor: "var(--px-primary)",
          cancelButtonColor: "#d33",
          cancelButtonText: this.$t("Delete_cancelButtonText"),
          confirmButtonText: this.$t("Delete_confirmButtonText")
        }).then(result => {
          if (result.value) {
            // Start the progress bar.
            NProgress.start();
            NProgress.set(0.1);
            axios
              .delete("sales/" + id)
              .then(() => {
                this.$swal(
                  this.$t("Delete_Deleted"),
                  this.$t("Deleted_in_successfully"),
                  "success"
                );
                Fire.$emit("Delete_sale");
              })
              .catch(() => {
                // Complete the animation of the  progress bar.
                setTimeout(() => NProgress.done(), 500);
                this.$swal(
                  this.$t("Delete_Failed"),
                  this.$t("Delete_Therewassomethingwronge"),
                  "warning"
                );
              });
          }
        });
      }
    },
    //---- Delete sales by selection
    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--px-primary)",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("sales_delete_by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("Delete_sale");
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  },
  //----------------------------- Created function-------------------\\
  created() {
    this.Get_Sales(1);
    this.get_pos_Settings();

    Fire.$on("Create_Facture_sale", () => {
      setTimeout(() => {
        this.Get_Sales(this.serverParams.page);
        NProgress.done();
        this.addPaymentOpen = false;
      }, 800);
    });


    Fire.$on("Update_Facture_sale", () => {

      setTimeout(() => {
        NProgress.done();
        this.addPaymentOpen = false;
        this.showPaymentOpen = false;
        this.Get_Sales(this.serverParams.page);
      }, 800);
    });


    Fire.$on("Delete_Facture_sale", () => {
      setTimeout(() => {
        NProgress.done();
        this.showPaymentOpen = false;
        this.Get_Sales(this.serverParams.page);
      }, 800);
    });


    Fire.$on("Delete_sale", () => {
      setTimeout(() => {
        this.Get_Sales(this.serverParams.page);
        // Complete the animation of the  progress bar.
        NProgress.done();
      }, 800);
    });

     Fire.$on("event_update_shipment", () => {
      setTimeout(() => {
        this.Get_Sales(this.serverParams.page);
        this.shipmentOpen = false;
      }, 800);
    });
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxsl { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxsl { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxsl__pad { padding: var(--pxn-space-6) 0; }
.pxsl__filters { margin-top: var(--pxn-space-4); padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxsl__filters-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 900px) { .pxsl__filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxsl__filters-grid { grid-template-columns: minmax(0, 1fr); } }
.pxsl__filters-act { display: flex; gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
.pxsl__bulk { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-5); margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-primary-soft); border: 1px solid var(--pxn-primary-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-sm); color: var(--pxn-primary-ink); }
.pxsl__bulk-act { display: flex; gap: var(--pxn-space-3); }
.pxsl-bulk-enter-active, .pxsl-bulk-leave-active { transition: opacity var(--pxn-dur-2) var(--pxn-ease), transform var(--pxn-dur-2) var(--pxn-ease); }
.pxsl-bulk-enter, .pxsl-bulk-leave-to { opacity: 0; transform: translateY(-6px); }
.pxsl__tablewrap { margin-top: var(--pxn-space-5); }
.pxsl__link { color: var(--pxn-primary); text-decoration: none; }
.pxsl__link:hover { text-decoration: underline; }
.pxsl__ret { color: var(--pxn-danger-ink); margin-left: var(--pxn-space-2); vertical-align: -2px; }
.pxsl__muted { color: var(--pxn-ink-3); }
.pxsl-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxsl-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxsl-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxsl-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); }
.pxsl-tbl tr:last-child td { border-bottom: 0; }
.pxsl-tbl .is-right { text-align: right; }
.pxsl__empty { text-align: center; color: var(--pxn-ink-3); }
.pxsl__rowbtns { display: inline-flex; gap: var(--pxn-space-1); }
.pxsl__modaltitle { text-align: center; margin: 0 0 var(--pxn-space-5); font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxsl__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxsl__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxsl__span2 { grid-column: span 2; }
@media (max-width: 720px) { .pxsl__span2 { grid-column: span 1; } }
.pxsl__gap { margin-top: var(--pxn-space-5); }
.pxsl__change { margin: 0; padding: var(--pxn-space-2) 0; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxsl__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
.pxsl__grow { flex: 1; }
</style>

<style>
  .total{
    font-weight: bold;
    font-size: 14px;
    /* text-transform: uppercase;
    height: 50px; */
  }

  /* ============================================
     Sales receipt modal — QR codes row (ZATCA + Invoice URL)
     `flex-wrap: nowrap` forces the two blocks to stay inline even inside
     the narrow size="sm" modal; QRs are 100px so 2 × 100 + 10 gap = 210px
     comfortably fits a ~280px content column.
     ============================================ */
  #invoice-POS .sar-fiscal-receipt {
    border: 2px solid #111;
    padding: 7px;
    margin-bottom: 10px;
    text-align: center;
    font-size: 10px;
    line-height: 1.35;
    overflow-wrap: anywhere;
  }
  #invoice-POS .sar-fiscal-title { font-size: 15px; font-weight: 800; }
  #invoice-POS .sar-fiscal-voided { color: #b91c1c; font-size: 14px; font-weight: 800; }
  #invoice-POS .sar-fiscal-words { margin-top: 5px; font-weight: 700; }

  #invoice-POS .receipt-qr-row {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    justify-content: center !important;
    align-items: flex-start !important;
    gap: 10px !important;
    width: 100%;
  }
  #invoice-POS .receipt-qr-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 0 0 auto;
    width: 100px;
    margin: 0;
  }
  #invoice-POS .receipt-qr-title {
    font-weight: 700;
    font-size: 10px;
    letter-spacing: 1px;
    text-transform: uppercase;
    text-align: center;
    margin: 0 0 4px;
    line-height: 1.2;
    display: block;
    width: 100%;
  }
  #invoice-POS .receipt-qr-canvas {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100px;
    height: 100px;
    margin: 0 auto;
  }
  /* qrcode.js' CDN build emits a <canvas> + an <img>; the local minimal
     vendor build only emits a <canvas>. Our render code (ensureQrImg) makes
     sure an <img> always exists by converting canvas → toDataURL when
     needed, then we show only the <img>. The <img>'s data URL survives
     `innerHTML` cloning into the print popup (canvas pixel data does not). */
  #invoice-POS .receipt-qr-canvas img {
    display: block !important;
    margin: 0 auto !important;
    width: 100px !important;
    height: 100px !important;
    max-width: 100px !important;
  }
  #invoice-POS .receipt-qr-canvas canvas,
  #invoice-POS .receipt-qr-canvas table {
    display: none !important;
  }
</style>
