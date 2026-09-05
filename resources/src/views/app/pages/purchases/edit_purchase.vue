<template>
  <div class="px-next pxpuf">
    <px-page-header
      :title="$t('EditPurchase')"
      :breadcrumbs="[{ label: $t('Purchases') }, { label: $t('ListPurchases') }, { label: $t('EditPurchase') }]"
    >
      <template #actions>
        <px-button variant="ghost" icon="arrow-left" @click="$router.push({ name: 'index_purchases' })">{{ $t('Cancel') }}</px-button>
        <px-button
          variant="primary" icon="check"
          :loading="SubmitProcessing"
          :disabled="SubmitProcessing || hasBatchValidationErrors"
          :title="hasBatchValidationErrors ? $t('Batch_Qty_Mismatch') : ''"
          @click="Submit_Purchase"
        >{{ $t('submit') }}</px-button>
      </template>
    </px-page-header>

    <div v-if="isLoading" class="pxpuf__pad">
      <px-skeleton variant="lines" :rows="12" />
    </div>

    <validation-observer v-else ref="edit_purchase" tag="div">
      <px-card class="pxpuf__card">
        <!-- Header fields -->
        <div class="pxpuf__grid3">
          <validation-provider ref="dateProvider" name="date" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('date')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" type="date" v-model="purchase.date" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider ref="supplierProvider" name="Supplier" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Supplier')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase.supplier_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Supplier')" @input="v.validate"
                  :options="suppliers.map(s => ({ label: s.name, value: s.id }))" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider ref="warehouseProvider" name="warehouse" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('warehouse')" required :error="v.errors[0]"
              :hint="details.length > 0 ? ($t('Warehouse_locked_has_lines') || 'No se puede cambiar el almacén con productos en la lista.') : ''">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase.warehouse_id" :reduce="o => o.value"
                  :disabled="details.length > 0" :placeholder="$t('Choose_Warehouse')"
                  @input="val => { Selected_Warehouse(val); v.validate(val); }"
                  :options="warehouses.map(w => ({ label: w.name, value: w.id }))" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider v-if="location_meta.requires" ref="locProvider" name="inventory_location" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Inventory_Location')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase.inventory_location_id" :reduce="o => o.value"
                  :disabled="details.length > 0 || !purchase.warehouse_id" :placeholder="$t('Choose_Inventory_Location')"
                  @input="v.validate"
                  :options="inventory_locations.map(l => ({ label: l.name + ' · ' + l.type, value: l.id }))" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <px-alert v-if="location_meta.blocked" tone="danger" class="pxpuf__gap">
          {{ $t('Inventory_Location_Warehouse_Not_Ready') || 'Este almacén usa inventario por ubicación pero aún no está reconciliado. No se puede registrar la compra hasta resolverlo.' }}
        </px-alert>

        <!-- Product search -->
        <div class="pxpuf__search pxpuf__gap">
          <h5 class="pxpuf__lineshead">{{ $t('ProductName') }}</h5>
          <div id="autocomplete" class="pxpuf-ac">
            <button type="button" class="pxpuf-ac__scan" :title="$t('Scan')" @click="showModal">
              <lucide-icon name="scan-line" />
            </button>
            <input
              :placeholder="$t('Scan_Search_Product_by_Code_Name')"
              @input="e => search_input = e.target.value"
              @keyup="search(search_input)"
              @focus="handleFocus"
              @blur="handleBlur"
              ref="product_autocomplete"
              class="pxpuf-ac__input" />
            <ul class="pxpuf-ac__list" v-show="focused && product_filter.length">
              <li class="pxpuf-ac__item" v-for="product_fil in product_filter" :key="product_fil.id" @mousedown="SearchProduct(product_fil)">
                {{ getResultValue(product_fil) }}
              </li>
            </ul>
          </div>
        </div>

        <!-- Order products -->
        <div class="pxpuf__lines pxpuf__gap">
          <h5 class="pxpuf__lineshead">{{ $t('order_products') }} *</h5>
          <div class="pxpuf-tbl__wrap pxn-scroll">
            <table class="pxpuf-tbl">
              <thead>
                <tr>
                  <th style="width:44px">#</th>
                  <th>{{ $t('ProductName') }}</th>
                  <th class="is-right">{{ $t('Net_Unit_Cost') }}</th>
                  <th class="is-right">{{ $t('Current_stock') }}</th>
                  <th class="is-center">{{ $t('Qty') }}</th>
                  <th class="is-right">{{ $t('Discount') }}</th>
                  <th class="is-right">{{ $t('Tax') }}</th>
                  <th class="is-right">{{ $t('SubTotal') }}</th>
                  <th class="is-center" style="width:72px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="details.length <= 0"><td colspan="9" class="pxpuf__empty">{{ $t('NodataAvailable') }}</td></tr>
                <template v-for="detail in details">
                  <tr :key="'detail-' + detail.detail_id" :class="{ 'pxpuf__rowdead': detail.del === 1 || detail.no_unit === 0 }">
                    <td class="pxn-num">{{ detail.detail_id }}</td>
                    <td>
                      <span class="pxn-mono">{{ detail.code }}</span><br />
                      <px-badge tone="success">{{ detail.name }}</px-badge>
                      <div v-if="detail.warehouse_location" class="pxpuf__sub">{{ $t('Warehouse_Locations') }}: <strong>{{ detail.warehouse_location }}</strong></div>
                      <div v-if="detail.is_batch_tracked" class="pxpuf__badgeline">
                        <px-badge tone="info" icon="flask-conical">{{ $t('Track_Batches_Expiry') }}</px-badge>
                      </div>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.Net_cost, priceDecimals) }}</td>
                    <td class="is-right"><px-badge tone="warning">{{ detail.stock }} {{ detail.unitPurchase }}</px-badge></td>
                    <td class="is-center">
                      <span class="pxpuf__stepper">
                        <button type="button" class="pxpuf__step" v-show="detail.no_unit !== 0" @click="decrement(detail, detail.detail_id)">−</button>
                        <input class="pxpuf__stepinput" @keyup="Verified_Qty(detail, detail.detail_id)" :min="0.00" v-model.number="detail.quantity" :disabled="detail.del === 1 || detail.no_unit === 0" />
                        <button type="button" class="pxpuf__step" v-show="detail.no_unit !== 0" @click="increment(detail, detail.detail_id)">+</button>
                      </span>
                    </td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.DiscountNet * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ formatNumber(detail.taxe * detail.quantity, priceDecimals) }}</td>
                    <td class="is-right pxn-num">{{ currentUser.currency }} {{ detail.subtotal.toFixed(priceDecimals) }}</td>
                    <td class="is-center pxpuf__rowactions" v-show="detail.no_unit !== 0">
                      <lucide-icon class="pxpuf__ico is-edit" name="pencil"
                        v-if="currentUserPermissions && currentUserPermissions.includes('edit_product_purchase')"
                        @click="Modal_Updat_Detail(detail)" />
                      <lucide-icon class="pxpuf__ico is-del" name="x" @click="delete_Product_Detail(detail.detail_id)" />
                    </td>
                  </tr>

                  <!-- Batch: deferred until received -->
                  <tr v-if="detail.is_batch_tracked && detail.no_unit !== 0 && detail.del !== 1 && purchase.statut !== 'received'" :key="'batches-defer-' + detail.detail_id" class="pxpuf__batchrow">
                    <td colspan="9">
                      <div class="pxpuf__batchdefer">
                        <lucide-icon name="package" :size="14" />
                        <span>{{ $t('Batches_Assigned_On_Receipt') || 'Los lotes se asignarán cuando la compra se marque como recibida.' }}</span>
                      </div>
                    </td>
                  </tr>

                  <!-- Batch entry -->
                  <tr v-if="detail.is_batch_tracked && detail.no_unit !== 0 && detail.del !== 1 && purchase.statut === 'received'" :key="'batches-' + detail.detail_id" class="pxpuf__batchrow">
                    <td colspan="9">
                      <div class="pxpuf__batchbox">
                        <div class="pxpuf__batchhead">
                          <div class="pxpuf__batchtitle">
                            {{ $t('Batches') }}
                            <span class="pxpuf__batchsub">
                              {{ (detail.batches || []).length }} {{ $t('items') || 'items' }}
                              <template v-if="(detail.batches || []).length">
                                · {{ $t('Total') }}: <strong>{{ formatNumber(batchTotalQty(detail), 2) }}</strong> / {{ formatNumber(detail.quantity || 0, 2) }}
                              </template>
                            </span>
                          </div>
                          <px-button size="sm" variant="secondary" icon="plus" @click="add_batch(detail)">{{ $t('Add') }}</px-button>
                        </div>

                        <table v-if="(detail.batches || []).length" class="pxpuf-batchtbl">
                          <thead>
                            <tr>
                              <th>{{ $t('Batch_No') }}</th>
                              <th>{{ $t('Expiry_Date') }}</th>
                              <th>{{ $t('Mfg_Date') }}</th>
                              <th class="is-right">{{ $t('quantity') }}</th>
                              <th class="is-right">{{ $t('cost') }}</th>
                              <th style="width:40px"></th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(b, bIdx) in detail.batches" :key="'b-' + detail.detail_id + '-' + bIdx">
                              <td><input class="pxpuf__bin" v-model="b.batch_no" :placeholder="$t('Batch_No')" /></td>
                              <td><input class="pxpuf__bin" type="date" v-model="b.expiry_date" /></td>
                              <td><input class="pxpuf__bin" type="date" v-model="b.mfg_date" /></td>
                              <td><input class="pxpuf__bin is-right" type="text" inputmode="decimal" lang="en" pattern="[0-9]*[.,]?[0-9]*" :value="b.qty" @input="val => onBatchNumberInput(b, 'qty', val.target ? val.target.value : val)" /></td>
                              <td><input class="pxpuf__bin is-right" type="text" inputmode="decimal" lang="en" pattern="[0-9]*[.,]?[0-9]*" :value="b.unit_cost" @input="val => onBatchNumberInput(b, 'unit_cost', val.target ? val.target.value : val)" /></td>
                              <td class="is-center">
                                <lucide-icon class="pxpuf__ico is-del" name="x" @click="remove_batch(detail, bIdx)" />
                              </td>
                            </tr>
                          </tbody>
                        </table>
                        <div v-else class="pxpuf__batchempty">
                          <lucide-icon name="inbox" :size="20" />
                          <div>{{ $t('NodataAvailable') }}</div>
                          <div class="pxpuf__batchemptyhint">{{ $t('Click_Add_To_Start') || 'Click "Add" to create a batch' }}</div>
                        </div>

                        <div v-if="batchQtyMismatch(detail)" class="pxpuf__batchwarn">
                          <lucide-icon name="alert-triangle" :size="14" />
                          <span>{{ $t('Batch_Qty_Mismatch') }}</span>
                        </div>
                      </div>
                    </td>
                  </tr>

                  <tr v-if="detail.is_imei && detail.is_batch_tracked" :key="'serial-batch-conflict-' + detail.detail_id" class="pxpuf__batchrow">
                    <td colspan="9" class="pxpuf__inlinealert">
                      <px-alert tone="danger" bare>
                        {{ $t('Serial_Batch_Incompatible') || 'Este producto está configurado con lote Y serie/IMEI a la vez. La combinación no es compatible: corrige la configuración del producto antes de registrar la compra.' }}
                      </px-alert>
                    </td>
                  </tr>

                  <tr v-if="detail.is_imei && !detail.is_batch_tracked" :key="'serials-' + detail.detail_id" class="pxpuf__batchrow">
                    <td colspan="9" class="pxpuf__inlinealert">
                      <div class="pxpuf__serialbox">
                        <serial-numbers-field mode="entry" v-model="detail.serial_numbers" :required-count="serialRequiredCount(detail)" />
                        <div v-if="purchase.statut !== 'received'" class="pxpuf__hint">
                          {{ $t('Serials_Assigned_On_Receive') || 'Los seriales se asignarán cuando la compra se marque como recibida.' }}
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Extra charges + custom fields -->
        <div v-if="purchase_extra_charges_enabled || purchase_custom_fields_enabled" class="pxpuf__grid2 pxpuf__gap">
          <div v-if="purchase_extra_charges_enabled">
            <px-field :label="$t('Add_Other_Charges')">
              <template #default>
                <div class="pxpuf__inline">
                  <px-input v-model="new_charge.name" :placeholder="$t('Charge_Name')" />
                  <px-input v-model.number="new_charge.amount" :placeholder="$t('Amount')" />
                  <px-button variant="secondary" icon-only icon="plus" @click="add_Extra_Charge" />
                </div>
              </template>
            </px-field>
            <div v-for="(charge, index) in extra_charges" :key="'charge_' + index" class="pxpuf__chargerow">
              <px-input v-if="charge.name_editing" v-model="charge.name" :placeholder="$t('Charge_Name')" />
              <span v-else class="pxpuf__chargename">{{ charge.name }}</span>
              <px-input v-model.number="charge.amount" :suffix="currentUser.currency" @input="keyup_Extra_Charge(index)" />
              <lucide-icon class="pxpuf__ico is-edit" :name="charge.name_editing ? 'check' : 'pencil'" @click="toggle_Edit_Charge(index)" />
              <lucide-icon class="pxpuf__ico is-del" name="trash-2" @click="remove_Extra_Charge(index)" />
            </div>
          </div>

          <div v-if="purchase_custom_fields_enabled">
            <px-field :label="$t('Additional_Fields')">
              <template #default>
                <div class="pxpuf__inline">
                  <px-input v-model="new_field.name" :placeholder="$t('Field_Name')" />
                  <px-input v-model="new_field.value" :placeholder="$t('Field_Value')" />
                  <px-button variant="secondary" icon-only icon="plus" @click="add_Custom_Field" />
                </div>
              </template>
            </px-field>
            <div v-for="(field, index) in custom_fields" :key="'field_' + index" class="pxpuf__chargerow">
              <px-input v-if="field.name_editing" v-model="field.name" :placeholder="$t('Field_Name')" />
              <span v-else class="pxpuf__chargename">{{ field.name }}</span>
              <px-input v-model="field.value" :placeholder="$t('Field_Value')" />
              <lucide-icon class="pxpuf__ico is-edit" :name="field.name_editing ? 'check' : 'pencil'" @click="toggle_Edit_Field(index)" />
              <lucide-icon class="pxpuf__ico is-del" name="trash-2" @click="remove_Custom_Field(index)" />
            </div>
          </div>
        </div>

        <!-- Tax / Discount / Shipping -->
        <div class="pxpuf__grid3 pxpuf__gap" v-if="currentUserPermissions && currentUserPermissions.includes('edit_tax_discount_shipping_purchase')">
          <validation-provider name="Order Tax" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase.tax_rate" suffix="%" @input="v.validate($event); keyup_OrderTax()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Discount" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase.discount" :suffix="currentUser.currency" @input="v.validate($event); keyup_Discount()" /></template>
            </px-field>
          </validation-provider>
          <validation-provider name="Shipping" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Shipping')" :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="purchase.shipping" :suffix="currentUser.currency" @input="v.validate($event); keyup_Shipping()" /></template>
            </px-field>
          </validation-provider>
        </div>

        <!-- Totals -->
        <div class="pxpuf__totals pxpuf__gap">
          <table class="pxpuf-totbl">
            <tbody>
              <tr><td>{{ $t('OrderTax') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase.TaxNet.toFixed(priceDecimals) }} ({{ formatNumber(purchase.tax_rate, 2) }} %)</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase.discount.toFixed(priceDecimals) }}</td></tr>
              <tr><td>{{ $t('Shipping') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ purchase.shipping.toFixed(priceDecimals) }}</td></tr>
              <tr v-for="(charge, index) in extra_charges" :key="'sum_charge_' + index"><td>{{ charge.name }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ (parseFloat(charge.amount) || 0).toFixed(priceDecimals) }}</td></tr>
              <tr class="pxpuf__totrow"><td>{{ $t('Total') }}</td><td class="is-right pxn-num">{{ currentUser.currency }} {{ GrandTotal.toFixed(priceDecimals) }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Status + Note -->
        <div class="pxpuf__grid3 pxpuf__gap">
          <validation-provider ref="statutProvider" name="Status" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Status')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="purchase.statut" :reduce="o => o.value"
                  :placeholder="$t('Choose_Status')" @input="v.validate"
                  :options="[{ label: 'received', value: 'received' }, { label: 'pending', value: 'pending' }, { label: 'ordered', value: 'ordered' }]" />
              </template>
            </px-field>
          </validation-provider>
        </div>

        <px-field :label="$t('Note')" class="pxpuf__gap">
          <template #default="{ id }"><px-textarea :id="id" v-model="purchase.notes" :rows="4" :placeholder="$t('Afewwords')" /></template>
        </px-field>

        <px-alert v-if="hasBatchValidationErrors" tone="warning" icon="alert-triangle" class="pxpuf__gap">
          <template v-if="firstBatchErrorDetail && (!firstBatchErrorDetail.batches || firstBatchErrorDetail.batches.length === 0)">
            {{ $t('Batch_Required_For_Item') || 'Add at least one batch for' }}: <strong>{{ firstBatchErrorDetail.name }}</strong>
          </template>
          <template v-else>{{ $t('Batch_Qty_Mismatch') }}</template>
        </px-alert>

        <div class="pxpuf__actionbar">
          <px-button variant="secondary" type="button" @click="$router.push({ name: 'index_purchases' })">{{ $t('Cancel') }}</px-button>
          <px-button variant="primary" icon="check" :loading="SubmitProcessing" :disabled="SubmitProcessing || hasBatchValidationErrors" @click="Submit_Purchase">{{ $t('submit') }}</px-button>
        </div>
      </px-card>
    </validation-observer>

    <!-- Modal: Update line detail -->
    <px-modal v-model="updateDetailOpen" :title="detail.name || $t('EditProduct')" size="lg">
      <validation-observer ref="Update_Detail_purchase" tag="div">
        <div class="pxpuf__grid2">
          <validation-provider name="Product Cost" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('ProductCost')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="detail.Unit_cost" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider name="Tax Method" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('TaxMethod')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.tax_method" :reduce="o => o.value"
                  :placeholder="$t('Choose_Method')" @input="v.validate"
                  :options="[{ label: 'Exclusive', value: '1' }, { label: 'Inclusive', value: '2' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Order Tax" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('OrderTax')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="detail.tax_percent" suffix="%" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider name="Discount Method" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('Discount_Method')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.discount_Method" :reduce="o => o.value"
                  :placeholder="$t('Choose_Method')" @input="v.validate"
                  :options="[{ label: 'Percent %', value: '1' }, { label: 'Fixed', value: '2' }]" />
              </template>
            </px-field>
          </validation-provider>

          <validation-provider name="Discount Rate" :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="v">
            <px-field :label="$t('Discount')" required :error="v.errors[0]">
              <template #default="{ id }"><px-input :id="id" v-model.number="detail.discount" @input="v.validate" /></template>
            </px-field>
          </validation-provider>

          <validation-provider name="Unit Purchase" :rules="{ required: true }" v-slot="v">
            <px-field :label="$t('UnitPurchase')" required :error="v.errors[0]">
              <template #default="{ id }">
                <vs-px :input-id="id" :invalid="!!v.errors.length" v-model="detail.purchase_unit_id" :reduce="o => o.value"
                  :placeholder="$t('Choose_Unit_Purchase')" @input="v.validate"
                  :options="units.map(u => ({ label: u.name, value: u.id }))" />
              </template>
            </px-field>
          </validation-provider>
        </div>
      </validation-observer>
      <template #footer="{ close }">
        <px-button variant="secondary" @click="close">{{ $t('Cancel') }}</px-button>
        <px-button variant="primary" icon="check" :loading="Submit_Processing_detail" :disabled="Submit_Processing_detail" @click="submit_Update_Detail">{{ $t('submit') }}</px-button>
      </template>
    </px-modal>

    <!-- Modal: Barcode scanner -->
    <px-modal v-model="scanOpen" :title="$t('Barcode_Scanner') || 'Barcode Scanner'" size="md">
      <qrcode-scanner v-if="scanOpen" :qrbox="250" :fps="10" style="width: 100%;" @result="onScan" />
    </px-modal>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import { getPriceDecimals } from "../../../../utils/priceFormat";
import { resolveAutoInventoryLocation } from "../../../../utils/inventoryLocationAutoSelect";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: {
    title: "Edit Purchase"
  },
  components: {
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxButton, PxBadge, PxAlert, PxModal, "vs-px": VsPx
  },
  data() {
    return {
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      isLoading: true,
      SubmitProcessing:false,
      Submit_Processing_detail:false,
      scanOpen: false,
      updateDetailOpen: false,

      // ——— Inline styles for batches section ———
      batchThStyle: {
        padding: '8px 10px',
        background: '#f1f5f9',
        borderBottom: '1px solid #e2e8f0',
        fontSize: '10px',
        fontWeight: '700',
        color: '#475569',
        textTransform: 'uppercase',
        letterSpacing: '0.5px',
        textAlign: 'left'
      },
      batchTdStyle: {
        padding: '6px 10px',
        borderBottom: '1px solid #f1f5f9',
        verticalAlign: 'middle'
      },
      batchInputStyle: {
        height: '30px',
        fontSize: '12px',
        padding: '4px 8px'
      },

      warehouses: [],
      suppliers: [],
      products: [],
      details: [],
      detail: {},
      purchases: [],
      purchase: {
        id: "",
        statut: "",
        date: "",
        notes: "",
        supplier_id: "",
        warehouse_id: "",
        inventory_location_id: null,
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      // MS2 — inventory-location select (location_primary warehouses / location-native purchases).
      inventory_locations: [],
      location_meta: { requires: false, blocked: false, mode: null, status: null },
      total: 0,
      GrandTotal: 0,
      extra_charges: [],
      custom_fields: [],
      new_charge: { name: "", amount: "" },
      new_field: { name: "", value: "" },
      purchase_extra_charges_enabled: false,
      purchase_custom_fields_enabled: false,
      product: {
        id: "",
        code: "",
        stock: "",
        quantity: 1,
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        name: "",
        no_unit:"",
        unitPurchase: "",
        purchase_unit_id: "",
        Net_cost: "",
        Total_cost: "",
        Unit_cost: "",
        subtotal: "",
        product_id: "",
        detail_id: "",
        taxe: "",
        tax_percent: "",
        tax_method: "",
        product_variant_id: "",
        del: "",
        is_imei: "",
        imei_number:"",
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions","currentUser"]),

    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },

    // Sum of the extra charges (included in the Grand Total)
    ExtraChargesTotal() {
      return this.extra_charges.reduce(
        (sum, charge) => sum + (parseFloat(charge.amount) || 0),
        0
      );
    },

    // Only validate lines that are visible & not marked for deletion
    activeBatchTrackedDetails() {
      if (!Array.isArray(this.details)) return [];
      return this.details.filter(d =>
        d && d.is_batch_tracked && d.no_unit !== 0 && d.del !== 1
      );
    },

    // Batches are ONLY required for a RECEIVED purchase (matches the backend:
    // a pending purchase never creates a batch artifact).
    hasBatchValidationErrors() {
      if (this.purchase.statut !== 'received') return false;
      return this.activeBatchTrackedDetails.some(d => {
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return this.batchQtyMismatch(d);
      });
    },

    // First detail that failed batch validation — used for the banner
    firstBatchErrorDetail() {
      if (this.purchase.statut !== 'received') return null;
      return this.activeBatchTrackedDetails.find(d => {
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return this.batchQtyMismatch(d);
      }) || null;
    },

    // A product configured with BOTH batch and serial/IMEI tracking — the
    // backend rejects the combination (422); block the form the same way.
    serialBatchConflictDetail() {
      if (!Array.isArray(this.details)) return null;
      return this.details.find(d => d && d.is_imei && d.is_batch_tracked) || null;
    }
  },

  methods: {

    syncValidators() {
      this.$nextTick(() => {
        if (this.$refs.dateProvider) this.$refs.dateProvider.syncValue(this.purchase.date);
        if (this.$refs.supplierProvider) this.$refs.supplierProvider.syncValue(this.purchase.supplier_id);
        if (this.$refs.warehouseProvider) this.$refs.warehouseProvider.syncValue(this.purchase.warehouse_id);
        if (this.$refs.statutProvider) this.$refs.statutProvider.syncValue(this.purchase.statut);
        if (this.$refs.locProvider) this.$refs.locProvider.syncValue(this.purchase.inventory_location_id);
      });
    },

       
    showModal() {
      this.scanOpen = true;
      
    },

    onScan (decodedText, decodedResult) {
      const code = decodedText;
      this.search_input = code;
      this.search();
      this.scanOpen = false;
    },

    handleFocus() {
      this.focused = true
    },

    handleBlur() {
      this.focused = false
    },

    //--- Submit Validate Update Purchase
    Submit_Purchase() {
      // A product configured with BOTH batch and serial/IMEI tracking is not
      // supported by the backend (422) — block before submit.
      if (this.serialBatchConflictDetail) {
        this.makeToast(
          "danger",
          `${this.$t('Serial_Batch_Incompatible') || 'Lote y serie/IMEI no son compatibles'} (${this.serialBatchConflictDetail.name})`,
          this.$t("Failed")
        );
        return;
      }
      // Block submission when any active batch-tracked line has missing
      // batches or batch quantities that don't sum to the line quantity.
      if (this.hasBatchValidationErrors) {
        const d = this.firstBatchErrorDetail;
        const missing = d && (!Array.isArray(d.batches) || d.batches.length === 0);
        const msg = missing
          ? `${this.$t('Batch_Required_For_Item') || 'Add at least one batch for'}: ${d ? d.name : ''}`
          : this.$t('Batch_Qty_Mismatch');
        this.makeToast("danger", msg, this.$t("Failed"));
        return;
      }
      // Block when a serialized line's serial count != quantity (received only).
      if (this.purchase.statut === 'received') {
        const badSerial = this.details.find(d => this.serialCountMismatch(d));
        if (badSerial) {
          this.makeToast("danger", `${this.$t('Serials_Count_Mismatch')} (${badSerial.name})`, this.$t("Failed"));
          return;
        }
      }
      this.$refs.edit_purchase.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Purchase();
        }
      });
    },
    //---Submit Validation Update Detail
    submit_Update_Detail() {
      this.$refs.Update_Detail_purchase.validate().then(success => {
        if (!success) {
          return;
        } else {
          this.Update_Detail();
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

    //------  Show Modal Update Detail Product
    Modal_Updat_Detail(detail) {
      NProgress.start();
      NProgress.set(0.1);
      this.detail = {};
      this.detail.name = detail.name;
      this.detail.detail_id = detail.detail_id;
      this.detail.Unit_cost = detail.Unit_cost;
      this.detail.tax_method = detail.tax_method;
      this.detail.discount_Method = detail.discount_Method;
      this.detail.discount = detail.discount;
      this.detail.quantity = detail.quantity;
      this.detail.tax_percent = detail.tax_percent;
      this.detail.is_imei = detail.is_imei;
      this.detail.imei_number = detail.imei_number;

      setTimeout(() => {
        NProgress.done();
        this.updateDetailOpen = true;
      }, 1000);
    },

    //------ Submit Detail Product

    Update_Detail() {
      NProgress.start();
      NProgress.set(0.1);
      this.Submit_Processing_detail = true;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === this.detail.detail_id) {
          this.details[i].tax_percent = this.detail.tax_percent;
          this.details[i].Unit_cost = this.detail.Unit_cost;
          this.details[i].quantity = this.detail.quantity;
          this.details[i].tax_method = this.detail.tax_method;
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          this.details[i].imei_number = this.detail.imei_number;

          if (this.details[i].discount_Method == "2") {
            //Fixed
            this.details[i].DiscountNet = this.detail.discount;
          } else {
            //Percentage %
            this.details[i].DiscountNet = parseFloat(
              (this.detail.Unit_cost * this.details[i].discount) / 100
            );
          }

          if (this.details[i].tax_method == "1") {
            //Exclusive
            this.details[i].Net_cost = parseFloat(
              this.detail.Unit_cost - this.details[i].DiscountNet
            );

            this.details[i].taxe = parseFloat(
              (this.detail.tax_percent *
                (this.detail.Unit_cost - this.details[i].DiscountNet)) /
                100
            );
          } else {
            //Inclusive
            this.details[i].taxe = parseFloat(
              (this.detail.Unit_cost - this.details[i].DiscountNet) *
                (this.detail.tax_percent / 100)
            );

            this.details[i].Net_cost = parseFloat(
              this.detail.Unit_cost -
                this.details[i].taxe -
                this.details[i].DiscountNet
            );
          }

          this.$forceUpdate();
        }
      }
      this.Calcul_Total();

       setTimeout(() => {
        NProgress.done();
        this.Submit_Processing_detail = false;
        this.updateDetailOpen = false;
      }, 1000);
    },

  // Search Products
    search(){

      if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
      }

      if (this.search_input.length < 2) {

        return this.product_filter= [];
      }
      if (this.purchase.warehouse_id != "" &&  this.purchase.warehouse_id != null) {
        this.timer = setTimeout(() => {
          const product_filter = this.products.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.SearchProduct(product_filter[0])
            }else{
                this.product_filter=  this.products.filter(product => {
                  return (
                    product.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.code.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.barcode.toLowerCase().includes(this.search_input.toLowerCase())
                    );
                });

                // Check if product_filter is empty and show alert
                if (this.product_filter.length <= 0) {
                  this.makeToast(
                    "warning",
                    "Product Not Found",
                    "Warning"
                  );
                }
            }
        }, 800);
      } else {
        this.makeToast(
          "warning",
          this.$t("SelectWarehouse"),
          this.$t("Warning")
        );
      }


    },

    //------  get Result Value Search Products

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------  Submit Search Products
    SearchProduct(result) {
      this.product = {};
      if (
        this.details.length > 0 &&
        this.details.some(detail => detail.code === result.code)
      ) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
      } else {
        this.product.code = result.code;
        this.product.quantity = 1;
        this.product.no_unit = 1;
        this.product.stock = result.qte_purchase;
        this.product.product_variant_id = result.product_variant_id;
        this.Get_Product_Details(result.id, result.product_variant_id);
      }

      this.search_input= '';
      this.$refs.product_autocomplete.value = "";
      this.product_filter = [];
    },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      // MS2 — reload inventory-location context for the chosen warehouse.
      this.purchase.inventory_location_id = null;
      this.inventory_locations = [];
      this.location_meta = { requires: false, blocked: false, mode: null, status: null };
      this.Load_Inventory_Locations(value);
      this.Get_Products_By_Warehouse(value);
    },

    //---- MS2 · inventory locations of the selected warehouse ----\\
    Load_Inventory_Locations(id) {
      if (!id) return;
      axios
        .get("purchases_inventory_locations/" + id)
        .then(({ data }) => {
          this.inventory_locations = (data && data.locations) || [];
          this.location_meta = {
            requires: !!(data && data.requires_inventory_location),
            blocked: !!(data && data.blocked),
            mode: data && data.transition_mode,
            status: data && data.transition_status
          };
          if (!this.location_meta.requires) {
            this.purchase.inventory_location_id = null;
            return;
          }
          // MS5-D.1 (D2) — a persisted, still-valid location (including
          // quarantine) is kept as document state; if it is no longer valid it
          // is cleared and D2 re-runs over the current options. A sole
          // quarantine location without an explicit default stays unselected.
          this.purchase.inventory_location_id = resolveAutoInventoryLocation({
            locations: this.inventory_locations,
            defaultLocationId: data && data.default_inventory_location_id,
            persistedLocationId: this.purchase.inventory_location_id
          });
        })
        .catch(() => {
          this.inventory_locations = [];
          this.location_meta = { requires: false, blocked: false, mode: null, status: null };
        });
    },

     //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 0 + "&product_service=" + 0)
         .then(response => {
            this.products = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add Product -------------------------\\
    add_product() {
      if (this.details.length > 0) {
        this.Last_Detail_id();
      } else if (this.details.length === 0) {
        this.product.detail_id = 1;
      }
      this.details.push(this.product);
    },

    //----------------------------------------- Batch helpers (pharmacy) ------------\\
    add_batch(detail) {
      if (!detail.batches) this.$set(detail, 'batches', []);
      detail.batches.push({
        batch_no: '',
        expiry_date: '',
        mfg_date: '',
        qty: detail.batches.length === 0 ? Number(detail.quantity) || 0 : 0,
        unit_cost: Number(detail.Unit_cost) || 0
      });
    },
    // Locale-proof decimal input: allow digits + one separator, accept both "." and ","
    onBatchNumberInput(batchRow, field, raw) {
      let s = (raw == null ? '' : String(raw)).replace(',', '.');
      s = s.replace(/[^0-9.]/g, '');
      const firstDot = s.indexOf('.');
      if (firstDot !== -1) {
        s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '');
      }
      this.$set(batchRow, field, s);
    },
    remove_batch(detail, idx) {
      if (!detail.batches) return;
      detail.batches.splice(idx, 1);
    },
    batchTotalQty(detail) {
      if (!detail || !Array.isArray(detail.batches)) return 0;
      return detail.batches.reduce((s, b) => s + (Number(b.qty) || 0), 0);
    },
    batchQtyMismatch(detail) {
      if (!detail || !detail.is_batch_tracked) return false;
      if (!Array.isArray(detail.batches) || detail.batches.length === 0) return false;
      return Math.abs(this.batchTotalQty(detail) - (Number(detail.quantity) || 0)) > 0.0001;
    },

    //----------------------------------------- Serial helpers ---------------------\\
    // MS6-B1 — base-unit quantity for a line, matching the backend unit maths
    // ('*' multiplies by operator_value, '/' divides).
    detailBaseQty(detail) {
      const q = Number(detail && detail.quantity) || 0;
      const op = detail && detail.purchase_unit_operator;
      const val = Number(detail && detail.purchase_unit_operator_value);
      if (!op || !isFinite(val) || val <= 0) return q;
      return op === '/' ? q / val : q * val;
    },
    // Serials required for a line: the PHYSICAL base quantity for a
    // location-native (location_primary) warehouse — count(serials) ==
    // quantity_base — the entered document quantity for a legacy one. Kept
    // mode-dependent so legacy tenants keep their exact current behaviour.
    serialRequiredCount(detail) {
      return this.location_meta.requires
        ? Math.round(this.detailBaseQty(detail))
        : Math.round(Number(detail && detail.quantity) || 0);
    },
    serialCountMismatch(detail) {
      if (!detail || !detail.is_imei) return false;
      const count = Array.isArray(detail.serial_numbers) ? detail.serial_numbers.length : 0;
      return count !== this.serialRequiredCount(detail);
    },

    //-----------------------------------Verified QTY ------------------------------\\
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = 1;
          }
          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          this.formatNumber(this.details[i].quantity++, 2);
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------decrement QTY ------------------------------\\

    decrement(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > 0) {
            this.formatNumber(this.details[i].quantity--, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.purchase.tax_rate)) {
        this.purchase.tax_rate = 0;
      } else if(this.purchase.tax_rate == ''){
         this.purchase.tax_rate = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.purchase.discount)) {
        this.purchase.discount = 0;
      } else if(this.purchase.discount == ''){
         this.purchase.discount = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.purchase.shipping)) {
        this.purchase.shipping = 0;
      } else if(this.purchase.shipping == ''){
         this.purchase.shipping = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
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

    //-----------------------------------------Calcul Total ------------------------------\\
    Calcul_Total() {
      this.total = 0;
      for (var i = 0; i < this.details.length; i++) {
        var tax = this.details[i].taxe * this.details[i].quantity;
        this.details[i].subtotal = parseFloat(
          this.details[i].quantity * this.details[i].Net_cost + tax
        );
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_without_discount = parseFloat(
        this.total - this.purchase.discount
      );
      this.purchase.TaxNet = parseFloat(
        (total_without_discount * this.purchase.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount + this.purchase.TaxNet + this.purchase.shipping + this.ExtraChargesTotal
      );

      var grand_total =  this.GrandTotal.toFixed(this.priceDecimals);
      this.GrandTotal = parseFloat(grand_total);
    },

    //---------------------------------- Extra Charges (Shipping & Other) ----------------------\\
    add_Extra_Charge() {
      if (!this.new_charge.name || String(this.new_charge.name).trim() === "") {
        this.makeToast("warning", this.$t("Charge_Name"), this.$t("Warning"));
        return;
      }
      this.extra_charges.push({
        name: String(this.new_charge.name).trim(),
        amount: parseFloat(this.new_charge.amount) || 0,
        name_editing: false
      });
      this.new_charge = { name: "", amount: "" };
      this.Calcul_Total();
    },

    remove_Extra_Charge(index) {
      this.extra_charges.splice(index, 1);
      this.Calcul_Total();
    },

    toggle_Edit_Charge(index) {
      const charge = this.extra_charges[index];
      if (charge.name_editing && (!charge.name || String(charge.name).trim() === "")) {
        this.makeToast("warning", this.$t("Charge_Name"), this.$t("Warning"));
        return;
      }
      charge.name_editing = !charge.name_editing;
    },

    keyup_Extra_Charge(index) {
      const charge = this.extra_charges[index];
      if (isNaN(charge.amount) || charge.amount === "") {
        charge.amount = 0;
      }
      this.Calcul_Total();
    },

    //---------------------------------- Custom Fields (Weight / Text / Number) ----------------\\
    add_Custom_Field() {
      if (!this.new_field.name || String(this.new_field.name).trim() === "") {
        this.makeToast("warning", this.$t("Field_Name"), this.$t("Warning"));
        return;
      }
      this.custom_fields.push({
        name: String(this.new_field.name).trim(),
        value: this.new_field.value,
        name_editing: false
      });
      this.new_field = { name: "", value: "" };
    },

    remove_Custom_Field(index) {
      this.custom_fields.splice(index, 1);
    },

    toggle_Edit_Field(index) {
      const field = this.custom_fields[index];
      if (field.name_editing && (!field.name || String(field.name).trim() === "")) {
        this.makeToast("warning", this.$t("Field_Name"), this.$t("Warning"));
        return;
      }
      field.name_editing = !field.name_editing;
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          this.Calcul_Total();
        }
      }
    },

    //-----------------------------------Verified Detail Qty If Null ------------------------------\\

    verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast(
          "warning",
          this.$t("AddProductToList"),
          this.$t("Warning")
        );
        return false;
      } else {
        var count = 0;
        for (var i = 0; i < this.details.length; i++) {
          if (
            this.details[i].quantity == "" ||
            this.details[i].quantity === 0
          ) {
            count += 1;
          }
        }

        if (count > 0) {
          this.makeToast("warning", this.$t("AddQuantity"), this.$t("Warning"));
          return false;
        }

        // MS2 — never submit a purchase that would fall back to legacy.
        if (this.location_meta.blocked) {
          this.makeToast("danger", this.$t("Inventory_Location_Warehouse_Not_Ready") || "El almacén de inventario por ubicación no está listo.", this.$t("Failed"));
          return false;
        }
        if (this.location_meta.requires && !this.purchase.inventory_location_id) {
          this.makeToast("warning", this.$t("Choose_Inventory_Location") || "Selecciona una ubicación de inventario.", this.$t("Warning"));
          return false;
        }

        return true;
      }
    },

    //--------------------------------- Update Purchase -------------------------\\
    Update_Purchase() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        let id = this.$route.params.id;
        axios
          .put(`purchases/${id}`, {
            date: this.purchase.date,
            supplier_id: this.purchase.supplier_id,
            warehouse_id: this.purchase.warehouse_id,
            // MS2 — sent for a location-native purchase / location_primary warehouse.
            inventory_location_id: this.location_meta.requires ? this.purchase.inventory_location_id : null,
            statut: this.purchase.statut,
            notes: this.purchase.notes,
            tax_rate: this.purchase.tax_rate?this.purchase.tax_rate:0,
            TaxNet: this.purchase.TaxNet?this.purchase.TaxNet:0,
            discount: this.purchase.discount?this.purchase.discount:0,
            shipping: this.purchase.shipping?this.purchase.shipping:0,
            GrandTotal: this.GrandTotal,
            extra_charges: this.extra_charges.map(charge => ({
              name: charge.name,
              amount: parseFloat(charge.amount) || 0
            })),
            custom_fields: this.custom_fields.map(field => ({
              name: field.name,
              value: field.value
            })),
            details: this.details
          })
          .then(response => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );

            this.SubmitProcessing = false;
            this.$router.push({ name: "index_purchases" });
          })
          .catch(error => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            this.SubmitProcessing = false;
          });
      }
    },

    //-------------------------------- Get Last Detail Id -------------------------\\
    Last_Detail_id() {
      this.product.detail_id = 0;
      var len = this.details.length;
      this.product.detail_id = this.details[len - 1].detail_id + 1;
    },

    //---------------------------------get Product Details ------------------------\\

    Get_Product_Details(product_id, variant_id) {
      const wid = this.purchase && this.purchase.warehouse_id ? this.purchase.warehouse_id : null;
      const url = wid
        ? `/show_product_data/${product_id}/${variant_id}/${wid}`
        : `/show_product_data/${product_id}/${variant_id}`;

      axios.get(url).then(response => {
        this.product.del = 0;
        this.product.id = 0;
        this.product.discount           = response.data.discount;
        this.product.DiscountNet        = response.data.DiscountNet;
        this.product.discount_Method    = response.data.discount_method;
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.Net_cost = response.data.Net_cost;
        this.product.Unit_cost = response.data.Unit_cost;
        this.product.taxe = response.data.tax_cost;
        this.product.tax_method = response.data.tax_method;
        this.product.tax_percent = response.data.tax_percent;
        this.product.unitPurchase = response.data.unitPurchase;
        this.product.purchase_unit_id = response.data.purchase_unit_id;
        this.product.is_imei = response.data.is_imei;
        this.product.imei_number = '';
        this.$set(this.product, 'serial_numbers', []);
        this.product.is_batch_tracked = !!response.data.is_batch_tracked;
        this.$set(this.product, 'batches', []);
        this.product.warehouse_location = response.data.warehouse_location
          ? (response.data.warehouse_location.name
              ? `${response.data.warehouse_location.code} - ${response.data.warehouse_location.name}`
              : response.data.warehouse_location.code)
          : null;
        this.add_product();
        this.Calcul_Total();
      });
    },

    //---------------------------------------Get Elements Purchase ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      axios
        .get(`purchases/${id}/edit`)
        .then(response => {
          this.purchase = response.data.purchase;
          // System Settings (Defaults tab) toggles for the two optional sections
          this.purchase_extra_charges_enabled = response.data.purchase_extra_charges_enabled === true;
          this.purchase_custom_fields_enabled = response.data.purchase_custom_fields_enabled === true;
          this.extra_charges = (response.data.purchase.extra_charges || []).map(charge => ({
            name: charge.name,
            amount: parseFloat(charge.amount) || 0,
            name_editing: false
          }));
          this.custom_fields = (response.data.purchase.custom_fields || []).map(field => ({
            name: field.name,
            value: field.value,
            name_editing: false
          }));
          // Prefill serials from the legacy imei_number text so the entry field shows them.
          this.details = (response.data.details || []).map(d => {
            if (!Array.isArray(d.serial_numbers)) {
              d.serial_numbers = (d.is_imei && d.imei_number)
                ? String(d.imei_number).split(/[\r\n,;\t]+/).map(s => s.trim()).filter(s => s !== "")
                : [];
            }
            return d;
          });
          this.suppliers = response.data.suppliers;
          this.warehouses = response.data.warehouses;
          // MS2 — load the inventory-location context; the loaded
          // purchase.inventory_location_id (if any) is preserved.
          this.Load_Inventory_Locations(this.purchase.warehouse_id);
          this.Get_Products_By_Warehouse(this.purchase.warehouse_id);
          this.Calcul_Total();
          this.isLoading = false;
          this.syncValidators();
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //----------------------------- Created function-------------------
  created() {
    this.GetElements();
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxpuf { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxpuf { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxpuf__pad { padding: var(--pxn-space-6) 0; }
.pxpuf__card { margin-top: var(--pxn-space-5); overflow: visible; }
.pxpuf__grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 820px) { .pxpuf__grid3 { grid-template-columns: minmax(0, 1fr); } }
.pxpuf__grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 620px) { .pxpuf__grid2 { grid-template-columns: minmax(0, 1fr); } }
.pxpuf__grid2-full { grid-column: 1 / -1; }
.pxpuf__gap { margin-top: var(--pxn-space-6); }
.pxpuf__lineshead { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin-bottom: var(--pxn-space-3); }
.pxpuf__inline { display: flex; align-items: flex-start; gap: var(--pxn-space-2); }
.pxpuf__inline > *:first-child { flex: 1; min-width: 0; }
.pxpuf__sub { margin-top: var(--pxn-space-1); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpuf__search { position: relative; }
.pxpuf-ac { position: relative; display: flex; align-items: stretch; gap: var(--pxn-space-2); }
.pxpuf-ac__scan { flex: none; width: 40px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink-2); cursor: pointer; }
.pxpuf-ac__scan:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxpuf-ac__input { flex: 1; height: 40px; padding: 0 var(--pxn-space-4); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxpuf-ac__input:focus { outline: none; border-color: var(--pxn-primary); box-shadow: 0 0 0 3px var(--pxn-primary-soft); }
.pxpuf-ac__list { position: absolute; top: calc(100% + 4px); left: 48px; right: 0; z-index: 60; margin: 0; padding: var(--pxn-space-2); list-style: none; max-height: 280px; overflow-y: auto; background: var(--pxn-surface); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-lg); }
.pxpuf-ac__item { padding: var(--pxn-space-2) var(--pxn-space-3); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-sm); color: var(--pxn-ink); cursor: pointer; }
.pxpuf-ac__item:hover { background: var(--pxn-primary-soft); color: var(--pxn-primary); }
.pxpuf-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); overflow-x: auto; }
.pxpuf-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpuf-tbl th { padding: var(--pxn-space-3) var(--pxn-space-4); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap; }
.pxpuf-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink); vertical-align: top; }
.pxpuf-tbl .is-right { text-align: right; }
.pxpuf-tbl .is-center { text-align: center; }
.pxpuf__rowdead { opacity: 0.5; }
.pxpuf__empty { text-align: center; color: var(--pxn-ink-3); }
.pxpuf__badgeline { margin-top: var(--pxn-space-2); }
.pxpuf__rowactions { white-space: nowrap; }
.pxpuf__ico { cursor: pointer; width: 18px; height: 18px; }
.pxpuf__ico.is-edit { color: var(--pxn-success); margin-right: var(--pxn-space-3); }
.pxpuf__ico.is-del { color: var(--pxn-danger); }
.pxpuf__stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxpuf__step { border: 0; width: 30px; background: var(--pxn-primary); color: var(--pxn-primary-contrast); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxpuf__step:hover { background: var(--pxn-primary-hover); }
.pxpuf__stepinput { width: 64px; text-align: center; border: 0; border-left: 1px solid var(--pxn-border-control); border-right: 1px solid var(--pxn-border-control); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxpuf__stepinput:focus { outline: none; }
.pxpuf__batchrow td { background: transparent; padding: 0 var(--pxn-space-3) var(--pxn-space-4) !important; border-bottom: 0; }
.pxpuf__batchdefer { display: flex; align-items: center; gap: var(--pxn-space-2); padding: var(--pxn-space-3) var(--pxn-space-4); background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpuf__batchbox { background: var(--pxn-info-soft); border: 1px solid var(--pxn-info-border); border-left: 4px solid var(--pxn-info); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-4) var(--pxn-space-5); }
.pxpuf__batchhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--pxn-space-3); gap: var(--pxn-space-3); flex-wrap: wrap; }
.pxpuf__batchtitle { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpuf__batchsub { font-weight: var(--pxn-fw-regular); color: var(--pxn-ink-3); margin-left: var(--pxn-space-2); font-size: var(--pxn-fs-xs); }
.pxpuf-batchtbl { width: 100%; border-collapse: collapse; background: var(--pxn-surface); border-radius: var(--pxn-radius-sm); overflow: hidden; }
.pxpuf-batchtbl th { padding: var(--pxn-space-2) var(--pxn-space-3); text-align: left; font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-3); background: var(--pxn-surface-2); }
.pxpuf-batchtbl th.is-right { text-align: right; }
.pxpuf-batchtbl td { padding: var(--pxn-space-2) var(--pxn-space-3); border-top: 1px solid var(--pxn-border); vertical-align: middle; }
.pxpuf-batchtbl td.is-center { text-align: center; }
.pxpuf__bin { width: 100%; height: 32px; padding: 0 var(--pxn-space-3); border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-sm); background: var(--pxn-surface); color: var(--pxn-ink); font-size: var(--pxn-fs-sm); }
.pxpuf__bin.is-right { text-align: right; }
.pxpuf__batchempty { background: var(--pxn-surface); border: 1px dashed var(--pxn-border-control); border-radius: var(--pxn-radius-sm); padding: var(--pxn-space-6) var(--pxn-space-4); text-align: center; color: var(--pxn-ink-3); }
.pxpuf__batchemptyhint { font-size: var(--pxn-fs-xs); margin-top: var(--pxn-space-1); }
.pxpuf__batchwarn { margin-top: var(--pxn-space-3); padding: var(--pxn-space-2) var(--pxn-space-3); background: var(--pxn-danger-soft); color: var(--pxn-danger-ink); border: 1px solid var(--pxn-danger-border); border-left: 3px solid var(--pxn-danger); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-xs); display: flex; align-items: center; gap: var(--pxn-space-2); }
.pxpuf__inlinealert { padding: 0 var(--pxn-space-3) var(--pxn-space-4) !important; border-bottom: 1px solid var(--pxn-border); }
.pxpuf__serialbox { background: var(--pxn-info-soft); border: 1px solid var(--pxn-info-border); border-left: 4px solid var(--pxn-info); border-radius: var(--pxn-radius-md); padding: var(--pxn-space-4) var(--pxn-space-5); }
.pxpuf__hint { margin-top: var(--pxn-space-2); font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxpuf__chargerow { display: flex; align-items: center; gap: var(--pxn-space-2); margin-top: var(--pxn-space-2); }
.pxpuf__chargename { flex: 1; font-size: var(--pxn-fs-sm); color: var(--pxn-ink); }
.pxpuf__totals { display: flex; justify-content: flex-end; }
.pxpuf-totbl { min-width: 300px; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxpuf-totbl td { padding: var(--pxn-space-2) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); color: var(--pxn-ink-2); }
.pxpuf-totbl td.is-right { text-align: right; color: var(--pxn-ink); }
.pxpuf__totrow td { font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }
.pxpuf__actionbar { display: flex; justify-content: flex-end; gap: var(--pxn-space-3); margin-top: var(--pxn-space-7); }
</style>

<style>

  .input-with-icon {
    display: flex;
    align-items: center;
  }

  .scan-icon {
    width: 50px; /* Adjust size as needed */
    height: 50px;
    margin-right: 8px; /* Adjust spacing as needed */
    cursor: pointer;
  }
</style>