<template>
  <div class="px-next pxcr">
    <px-page-header :title="$t('Cash_Register_Report')" :breadcrumbs="[{ label: $t('Reports'), href: '#/app/reports/all' }, { label: $t('Cash_Register_Report') }]">
      <template #actions>
        <px-button variant="secondary" size="sm" icon="printer" @click="printTableOnly">{{ $t('print') }}</px-button>
      </template>
    </px-page-header>

    <px-card class="pxcr__filtercard">
      <div class="pxcr__filtergrid">
        <px-field :label="$t('DateRange')">
          <template #default>
            <date-range-picker
              v-model="dateRange"
              :locale-data="locale"
              :autoApply="true"
              :showDropdowns="true"
              :linkedCalendars="false"
              :parentEl="'body'"
              @update="onDateRangeUpdate"
            >
              <template v-slot:input="picker">
                <button type="button" class="pxcr__daterange pxn-ring">
                  <lucide-icon name="calendar-days" :size="14" />
                  {{ fmt(picker.startDate) }} — {{ fmt(picker.endDate) }}
                </button>
              </template>
            </date-range-picker>
          </template>
        </px-field>

        <px-field label="Número de sesión">
          <template #default="{ id }"><px-input :id="id" v-model="filters.register_id" placeholder="Ej. 25" @keyup.native.enter="getData(1)" /></template>
        </px-field>

        <px-field :label="$t('Cashier')">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.user_id" :reduce="o => o.value" :placeholder="$t('All')"
              :options="users.map(x => ({ label: userName(x), value: x.id }))" @input="getData(1)" />
          </template>
        </px-field>

        <px-field label="Sucursal">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.branch_id" :reduce="o => o.value" :placeholder="$t('All')"
              :options="branches.map(x => ({ label: x.name, value: x.id }))" @input="onBranchChange" />
          </template>
        </px-field>

        <px-field label="Ubicación">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.inventory_location_id" :reduce="o => o.value" :placeholder="$t('All')"
              :options="locationList.map(x => ({ label: x.name, value: x.id }))" @input="onLocationChange" />
          </template>
        </px-field>

        <px-field label="Caja física">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.cash_drawer_id" :reduce="o => o.value" :placeholder="$t('All')"
              :options="drawerList.map(x => ({ label: x.code ? `${x.name} · ${x.code}` : x.name, value: x.id }))" @input="getData(1)" />
          </template>
        </px-field>

        <px-field label="Estado del arqueo">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.closing_status" :reduce="o => o.value" :clearable="false"
              :options="closingStatusList" @input="getData(1)" />
          </template>
        </px-field>

        <px-field v-if="legacyWarehouses.length > 1" label="Almacén histórico">
          <template #default="{ id }">
            <vs-px :input-id="id" v-model="filters.legacy_warehouse_id" :reduce="o => o.value" :placeholder="$t('All')"
              :options="legacyWarehouses.map(x => ({ label: x.name, value: x.id }))" @input="getData(1)" />
          </template>
        </px-field>

        <div class="pxcr__filteract">
          <px-button variant="secondary" icon="refresh-cw" @click="resetFilters">{{ $t('Reset') || 'Restablecer' }}</px-button>
        </div>
      </div>
    </px-card>

    <px-alert tone="info" icon="info" bare class="pxcr__note">
      El reporte usa <strong>Sucursal → Ubicación → Caja física</strong>. “Almacén histórico” solo consulta sesiones antiguas sin contexto operativo nativo.
    </px-alert>

    <px-toolbar
      :search="serverParams.searchTerm"
      :search-placeholder="$t('Search_this_table')"
      @update:search="onSearchInput"
    />

    <div v-if="isLoading" class="pxcr__pad">
      <px-skeleton variant="table" :rows="8" :columns="10" />
    </div>

    <template v-else>
      <div class="pxcr__tablewrap">
        <px-table
          v-if="tableRows.length"
          :columns="columns"
          :rows="tableRows"
          row-key="id"
          :sort-key="serverParams.sort.field"
          :sort-dir="serverParams.sort.type"
          has-row-actions
          @sort="onSort"
        >
          <template #cell-id="{ row }">#{{ row.id }}</template>
          <template #cell-closed_date="{ row }">{{ formatDateOnly(row.closed_date || row.closed_at) }}</template>
          <template #cell-opened_time="{ row }">{{ formatTime(row.opened_time) }}</template>
          <template #cell-closed_time="{ row }">{{ formatTime(row.closed_time) }}</template>
          <template #cell-cashier="{ row }">{{ formatCashier(row) }}</template>
          <template #cell-operational_context="{ row }">
            <strong>{{ row.operational_context_label || '-' }}</strong>
            <small v-if="row.is_legacy_context" class="pxcr__muted pxcr__block">Compatibilidad histórica</small>
          </template>
          <template #cell-cash_drawer="{ row }">
            {{ row.cash_drawer_name || 'Sin caja física' }}
            <small v-if="row.cash_drawer_code" class="pxcr__muted pxcr__block">{{ row.cash_drawer_code }}</small>
          </template>
          <template #cell-total_sales="{ row }"><span class="pxn-num">{{ formatMoney(row.total_sales) }}</span></template>
          <template #cell-expected_cash="{ row }"><span class="pxn-num">{{ formatMoney(row.expected_cash) }}</span></template>
          <template #cell-counted_cash="{ row }"><span class="pxn-num">{{ formatMoney(row.counted_cash) }}</span></template>
          <template #cell-difference="{ row }"><span class="pxn-num">{{ formatMoney(row.difference) }}</span></template>
          <template #cell-closing_status="{ row }">
            <px-badge :tone="auditTone(row.closing_status)">{{ row.closing_status_label }}</px-badge>
          </template>
          <template #row-actions="{ row }">
            <px-button variant="ghost" size="sm" icon="eye" @click="showDetail(row)">Ver detalle</px-button>
          </template>
        </px-table>

        <px-empty-state v-else icon="wallet" :title="$t('No_report_rows') || 'Sin resultados'" :description="$t('No_report_rows_desc')" />
      </div>

      <div v-if="tableRows.length" class="pxcr__totalrow">
        <span>{{ $t('Totals') }}</span>
        <span>{{ $t('TotalSales') }}: <b class="pxn-num">{{ sumFieldRaw('total_sales') }}</b></span>
        <span>Efectivo esperado: <b class="pxn-num">{{ sumFieldRaw('expected_cash') }}</b></span>
        <span>Efectivo contado: <b class="pxn-num">{{ sumFieldRaw('counted_cash') }}</b></span>
        <span>{{ $t('Difference') }}: <b class="pxn-num">{{ sumFieldRaw('difference') }}</b></span>
      </div>

      <px-pagination
        v-if="tableRows.length"
        :page="serverParams.page"
        :per-page="Number(serverParams.perPage)"
        :total="Number(totalRows) || 0"
        @update:page="onPage"
        @update:perPage="onLimit"
      />
    </template>

    <px-modal v-model="detailOpen" size="lg" title="Detalle del cierre de caja">
      <div v-if="selectedRegister" class="pxcr-detail">
        <div class="pxcr-detail__header">
          <div>
            <div class="pxcr-detail__kicker">Sesión #{{ selectedRegister.id }}</div>
            <h4>{{ selectedRegister.register_number }}</h4>
            <p>{{ selectedRegister.operational_context_label || '-' }}</p>
          </div>
          <div class="pxcr-detail__actions">
            <px-badge :tone="auditTone(selectedRegister.closing_status)">{{ selectedRegister.closing_status_label }}</px-badge>
            <px-button size="sm" variant="secondary" icon="printer" @click="printRegisterDetail(selectedRegister)">Imprimir</px-button>
          </div>
        </div>

        <div class="pxcr-detail__grid">
          <div class="pxcr-detail__panel">
            <h5>Identificación operativa</h5>
            <div class="pxcr-detail__line"><span>Sucursal</span><strong>{{ selectedRegister.branch_name || (selectedRegister.is_legacy_context ? 'Histórica' : '-') }}</strong></div>
            <div class="pxcr-detail__line"><span>Ubicación</span><strong>{{ selectedRegister.inventory_location_name || '-' }}</strong></div>
            <div class="pxcr-detail__line"><span>Caja física</span><strong>{{ selectedRegister.cash_drawer_name || 'Sin caja física' }}</strong></div>
            <div class="pxcr-detail__line"><span>Cajero</span><strong>{{ formatCashier(selectedRegister) }}</strong></div>
            <div class="pxcr-detail__line"><span>Abrió</span><strong>{{ selectedRegister.opened_by_user_name || '-' }}</strong></div>
            <div class="pxcr-detail__line"><span>Cerró</span><strong>{{ selectedRegister.closed_by_user_name || '-' }}</strong></div>
            <div class="pxcr-detail__line" v-if="selectedRegister.is_legacy_context"><span>Almacén histórico</span><strong>{{ selectedRegister.warehouse_name || '-' }}</strong></div>
            <div class="pxcr-detail__line"><span>Apertura</span><strong>{{ formatDate(selectedRegister.opened_at) }}</strong></div>
            <div class="pxcr-detail__line"><span>Cierre</span><strong>{{ formatDate(selectedRegister.closed_at) }}</strong></div>
            <div class="pxcr-detail__line"><span>Duración</span><strong>{{ selectedRegister.session_duration_human || '-' }}</strong></div>
          </div>

          <div class="pxcr-detail__panel">
            <h5>Control de efectivo</h5>
            <div class="pxcr-detail__line"><span>Fondo inicial</span><strong>{{ formatMoney(selectedRegister.opening_balance) }}</strong></div>
            <div class="pxcr-detail__line"><span>Entradas</span><strong>{{ formatMoney(selectedRegister.cash_in) }}</strong></div>
            <div class="pxcr-detail__line"><span>Retiros/salidas</span><strong>{{ formatMoney(selectedRegister.cash_out) }}</strong></div>
            <div class="pxcr-detail__line"><span>Devoluciones en efectivo</span><strong>{{ formatMoney(snapshotValue('cash_refunds', 0)) }}</strong></div>
            <div class="pxcr-detail__line"><span>Efectivo esperado</span><strong>{{ formatMoney(selectedRegister.expected_cash) }}</strong></div>
            <div class="pxcr-detail__line"><span>Efectivo contado</span><strong>{{ formatMoney(selectedRegister.counted_cash) }}</strong></div>
            <div class="pxcr-detail__line"><span>Diferencia</span><strong>{{ formatMoney(selectedRegister.difference) }}</strong></div>
          </div>
        </div>

        <div class="pxcr-detail__grid">
          <div class="pxcr-detail__panel">
            <h5>Ventas por método</h5>
            <div class="pxcr-detail__line" v-for="method in selectedRegister.sales_by_payment_method || []" :key="method.name + '-' + method.id">
              <span>{{ method.name }}</span><strong>{{ formatMoney(method.total) }}</strong>
            </div>
            <div class="pxcr-detail__line pxcr-detail__line--total"><span>Total vendido</span><strong>{{ formatMoney(selectedRegister.total_sales) }}</strong></div>
          </div>

          <div class="pxcr-detail__panel">
            <h5>Conciliación</h5>
            <div class="pxcr-detail__line"><span>Tarjeta según PRODEX</span><strong>{{ formatMoney(selectedRegister.card_system_total) }}</strong></div>
            <div class="pxcr-detail__line"><span>Cierre terminal</span><strong>{{ selectedRegister.card_terminal_total === null ? '-' : formatMoney(selectedRegister.card_terminal_total) }}</strong></div>
            <div class="pxcr-detail__line"><span>Diferencia tarjeta</span><strong>{{ selectedRegister.card_difference === null ? '-' : formatMoney(selectedRegister.card_difference) }}</strong></div>
            <div class="pxcr-detail__line"><span>Transferencias</span><strong>{{ formatMoney(selectedRegister.transfer_total) }}</strong></div>
            <div class="pxcr-detail__line"><span>Transferencias verificadas</span><strong>{{ selectedRegister.transfers_verified ? 'Sí' : 'No' }}</strong></div>
          </div>
        </div>

        <div class="pxcr-detail__grid">
          <div class="pxcr-detail__panel">
            <h5>Arqueo por denominaciones</h5>
            <div class="pxcr-detail__denomhead"><span>Denominación</span><span>Cantidad</span><span>Subtotal</span></div>
            <div class="pxcr-detail__denomrow" v-for="row in denominationRows(selectedRegister)" :key="row.denomination">
              <span>{{ row.label }}</span><span>{{ row.quantity }}</span><strong>{{ formatMoney(row.subtotal) }}</strong>
            </div>
          </div>

          <div class="pxcr-detail__panel">
            <h5>Fondo y notas</h5>
            <div class="pxcr-detail__line"><span>Efectivo retirado</span><strong>{{ selectedRegister.cash_withdrawn_at_close === null ? '-' : formatMoney(selectedRegister.cash_withdrawn_at_close) }}</strong></div>
            <div class="pxcr-detail__line"><span>Fondo siguiente apertura</span><strong>{{ selectedRegister.next_opening_float === null ? '-' : formatMoney(selectedRegister.next_opening_float) }}</strong></div>
            <div class="pxcr-detail__note"><strong>Notas</strong><p>{{ selectedRegister.notes || '-' }}</p></div>
            <div class="pxcr-detail__note"><strong>Tarjeta</strong><p>{{ selectedRegister.card_notes || '-' }}</p></div>
            <div class="pxcr-detail__note"><strong>Transferencias</strong><p>{{ selectedRegister.transfer_notes || '-' }}</p></div>
          </div>
        </div>
      </div>
    </px-modal>
  </div>
</template>

<script>
import NProgress from 'nprogress'
import moment from 'moment'
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import Util from '../../../../utils'
import { formatPriceDisplay as formatPriceDisplayHelper, getPriceFormatSetting, getPriceDecimals } from '../../../../utils/priceFormat'
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxToolbar from "@/components/px-next/PxToolbar.vue";
import PxTable from "@/components/px-next/PxTable.vue";
import PxPagination from "@/components/px-next/PxPagination.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

export default {
  metaInfo: { title: 'Cash Register Report' },
  components: {
    'date-range-picker': DateRangePicker,
    PxPageHeader, PxToolbar, PxTable, PxPagination, PxButton, PxCard, PxBadge,
    PxField, PxInput, PxAlert, PxModal, PxEmptyState, "vs-px": VsPx
  },
  data() {
    return {
      _searchTimer: null,
      detailOpen: false,
      isLoading: true,
      serverParams: { sort: { field: 'closed_at', type: 'desc' }, page: 1, perPage: 10, searchTerm: '' },
      totalRows: 0,
      rows: [{ statut: '', children: [] }],
      users: [],
      branches: [],
      locations: [],
      drawers: [],
      legacyWarehouses: [],
      filters: {
        register_id: '', user_id: '', branch_id: '', inventory_location_id: '', cash_drawer_id: '',
        legacy_warehouse_id: '', closing_status: ''
      },
      selectedRegister: null,
      dateRange: { startDate: new Date(new Date().setDate(new Date().getDate() - 6)), endDate: new Date() },
      price_format_key: null,
      moneyFields: ['opening_balance','total_sales','cash_sales','card_sales','transfer_sales','other_sales','expected_cash','counted_cash','difference'],
      locale: {
        Label: this.$t('Apply') || 'Apply', cancelLabel: this.$t('Cancel') || 'Cancel', weekLabel: 'W',
        customRangeLabel: this.$t('CustomRange') || 'Custom Range', daysOfWeek: ['Su','Mo','Tu','We','Th','Fr','Sa'],
        monthNames: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], firstDay: 1
      }
    }
  },
  computed: {
    priceDecimals() { return getPriceDecimals({ store: this.$store }) },
    tableRows() { return (this.rows[0] && this.rows[0].children) || [] },
    closingStatusList() {
      return [
        { label: this.$t('All'), value: '' },
        { label: 'Cuadrada', value: 'balanced' },
        { label: 'Sobrante', value: 'over' },
        { label: 'Faltante', value: 'short' }
      ]
    },
    locationList() {
      const branchId = Number(this.filters.branch_id || 0)
      return branchId ? this.locations.filter(x => Number(x.branch_id) === branchId) : this.locations
    },
    drawerList() {
      const branchId = Number(this.filters.branch_id || 0)
      const locationId = Number(this.filters.inventory_location_id || 0)
      let rows = this.drawers
      if (locationId) rows = rows.filter(x => Number(x.inventory_location_id) === locationId)
      else if (branchId) rows = rows.filter(x => Number(x.branch_id) === branchId)
      return rows
    },
    columns() {
      return [
        { key: 'id', label: '#', sortable: true, strong: true },
        { key: 'register_number', label: 'Sesión' },
        { key: 'closed_date', label: 'Fecha' },
        { key: 'opened_time', label: 'Apertura' },
        { key: 'closed_time', label: 'Cierre' },
        { key: 'cashier', label: this.$t('Cashier') },
        { key: 'operational_context', label: 'Sucursal / Ubicación' },
        { key: 'cash_drawer', label: 'Caja física' },
        { key: 'total_sales', label: this.$t('TotalSales'), align: 'right', sortable: true },
        { key: 'expected_cash', label: 'Efectivo esperado', align: 'right', sortable: true },
        { key: 'counted_cash', label: 'Efectivo contado', align: 'right', sortable: true },
        { key: 'difference', label: this.$t('Difference'), align: 'right', sortable: true },
        { key: 'closing_status', label: 'Estado', sortable: true }
      ]
    }
  },
  created() { this.getData(1) },
  methods: {
    fmt(d) { try { return moment(d).format('YYYY-MM-DD') } catch (e) { return '' } },
    userName(x) { return x.firstname || x.lastname ? `${x.firstname || ''} ${x.lastname || ''}`.trim() : (x.username || `User #${x.id}`) },
    onDateRangeUpdate() { this.getData(1) },
    onBranchChange() {
      if (this.filters.inventory_location_id && !this.locationList.some(x => Number(x.id) === Number(this.filters.inventory_location_id))) this.filters.inventory_location_id = ''
      this.filters.cash_drawer_id = ''
      this.getData(1)
    },
    onLocationChange() { this.filters.cash_drawer_id = ''; this.getData(1) },
    updateParams(next) { this.serverParams = Object.assign({}, this.serverParams, next) },
    onPage(p) { if (this.serverParams.page !== p) { this.updateParams({ page: p }); this.getData(p) } },
    onLimit(v) { this.updateParams({ page: 1, perPage: Number(v) }); this.getData(1) },
    onSort({ key, dir }) { this.updateParams({ sort: { field: key, type: dir } }); this.getData(1) },
    onSearchInput(v) {
      this.updateParams({ searchTerm: v });
      if (this._searchTimer) clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => { this.serverParams.page = 1; this.getData(1); }, 350);
    },
    getData(page = 1) {
      NProgress.start(); NProgress.set(0.1); this.isLoading = true
      const params = Object.assign({}, this.filters, {
        page,
        limit: this.serverParams.perPage,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.serverParams.searchTerm,
        from: this.fmt(this.dateRange.startDate),
        to: this.fmt(this.dateRange.endDate)
      })
      Object.keys(params).forEach(key => { if (params[key] === '' || params[key] === null) delete params[key] })
      return axios.get('report/cash_registers_native', { params }).then(res => {
        const payload = res.data || {}
        const data = payload.registers || []
        this.rows[0].children = data
        this.totalRows = payload.totalRows || 0
        this.users = payload.users || []
        this.branches = payload.branches || []
        this.locations = payload.inventory_locations || []
        this.drawers = payload.cash_drawers || []
        this.legacyWarehouses = payload.legacy_warehouses || []
      }).catch(() => {
        if (this.$bvToast && this.$bvToast.toast) this.$bvToast.toast(this.$t('OperationFailed'), { title: this.$t('Failed'), variant: 'danger', solid: true })
      }).finally(() => {
        this.isLoading = false
        setTimeout(() => NProgress.done(), 250)
      })
    },
    resetFilters() {
      this.filters = { register_id: '', user_id: '', branch_id: '', inventory_location_id: '', cash_drawer_id: '', legacy_warehouse_id: '', closing_status: '' }
      this.dateRange = { startDate: new Date(new Date().setDate(new Date().getDate() - 6)), endDate: new Date() }
      this.getData(1)
    },
    formatMoney(value) {
      const n = parseFloat(value || 0)
      try {
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store })
        if (key) this.price_format_key = key
        return formatPriceDisplayHelper(n, this.priceDecimals, key || null)
      } catch (e) { return n.toFixed(this.priceDecimals) }
    },
    sumFieldRaw(field) {
      return this.formatMoney(this.tableRows.reduce((sum, row) => sum + (parseFloat(row[field] || 0) || 0), 0))
    },
    formatDate(value) {
      if (!value) return '-'
      const format = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store)
      return Util.formatDisplayDate(value, format)
    },
    formatDateOnly(value) {
      if (!value) return '-'
      const format = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store)
      return moment(value).format(format || 'YYYY-MM-DD')
    },
    formatTime(value) {
      if (!value) return '-'
      const parsed = moment(String(value), ['HH:mm:ss', 'HH:mm'])
      return parsed.isValid() ? parsed.format('hh:mm A') : value
    },
    formatCashier(row) { return row.cashier_name || row.opened_by_user_name || row.closed_by_user_name || `User #${row.user_id || '-'}` },
    auditTone(status) { return status === 'balanced' ? 'success' : status === 'over' ? 'warning' : status === 'short' ? 'danger' : 'neutral' },
    showDetail(row) { this.selectedRegister = row; this.detailOpen = true },
    snapshotValue(key, fallback = null) {
      const snapshot = this.selectedRegister && this.selectedRegister.closing_snapshot ? this.selectedRegister.closing_snapshot : {}
      return snapshot[key] !== undefined && snapshot[key] !== null ? snapshot[key] : fallback
    },
    denominationRows(row) {
      const counted = row && row.counted_denominations ? row.counted_denominations : {}
      const denominations = row && row.closing_snapshot && row.closing_snapshot.denominations ? row.closing_snapshot.denominations : {}
      const symbol = denominations.currency_symbol || ''
      let keys = []
      if (Array.isArray(denominations.bills)) keys = keys.concat(denominations.bills)
      if (Array.isArray(denominations.coins)) keys = keys.concat(denominations.coins)
      if (!keys.length) keys = Object.keys(counted).map(Number).sort((a, b) => b - a)
      return keys.map(value => {
        const quantity = Number(counted[value] || counted[String(value)] || 0)
        const denomination = Number(value) || 0
        return { denomination, quantity, subtotal: denomination * quantity, label: `${symbol ? symbol + ' ' : ''}${denomination < 1 ? denomination.toFixed(2) : denomination.toFixed(0)}` }
      })
    },
    printTableOnly() {
      const registers = this.rows[0] && Array.isArray(this.rows[0].children) ? this.rows[0].children : []
      const rows = registers.map(r => `<tr><td>#${r.id}</td><td>${this.formatCashier(r)}</td><td>${r.operational_context_label || '-'}</td><td>${r.cash_drawer_name || '-'}</td><td>${this.formatDate(r.closed_at)}</td><td style="text-align:right">${this.formatMoney(r.total_sales)}</td><td style="text-align:right">${this.formatMoney(r.difference)}</td><td>${r.closing_status_label || ''}</td></tr>`).join('')
      this.printHtml('Reporte de cierres de caja', `<table><thead><tr><th>Sesión</th><th>Cajero</th><th>Sucursal / Ubicación</th><th>Caja física</th><th>Cierre</th><th>Total</th><th>Diferencia</th><th>Estado</th></tr></thead><tbody>${rows}</tbody></table>`)
    },
    printRegisterDetail(r) {
      if (!r) return
      const methods = (r.sales_by_payment_method || []).map(m => `<tr><td>${m.name || ''}</td><td style="text-align:right">${this.formatMoney(m.total)}</td></tr>`).join('')
      this.printHtml(`Cierre de caja #${r.id}`, `<p><strong>${r.operational_context_label || '-'}</strong></p><table><tr><th>Cajero</th><td>${this.formatCashier(r)}</td></tr><tr><th>Caja física</th><td>${r.cash_drawer_name || 'Sin caja física'}</td></tr><tr><th>Apertura</th><td>${this.formatDate(r.opened_at)}</td></tr><tr><th>Cierre</th><td>${this.formatDate(r.closed_at)}</td></tr><tr><th>Total vendido</th><td>${this.formatMoney(r.total_sales)}</td></tr><tr><th>Efectivo esperado</th><td>${this.formatMoney(r.expected_cash)}</td></tr><tr><th>Efectivo contado</th><td>${this.formatMoney(r.counted_cash)}</td></tr><tr><th>Diferencia</th><td>${this.formatMoney(r.difference)}</td></tr><tr><th>Estado</th><td>${r.closing_status_label || ''}</td></tr></table><h3>Ventas por método</h3><table>${methods}</table>`)
    },
    printHtml(title, body) {
      const w = window.open('', '_blank')
      if (!w) return
      w.document.open()
      w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>@media print{@page{size:A4 landscape;margin:.5cm}}body{font-family:Arial,sans-serif;color:#222}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px 8px;font-size:11px}th{background:#f5f5f5;text-align:left}h1{font-size:20px}</style></head><body><h1>${title}</h1>${body}</body></html>`)
      w.document.close(); w.focus(); setTimeout(() => { w.print(); w.close() }, 350)
    }
  }
}
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcr { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcr { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcr__pad { padding: var(--pxn-space-6) 0; }
.pxcr__filtercard { margin-top: var(--pxn-space-5); }
.pxcr__filtergrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); align-items: end; }
@media (max-width: 1100px) { .pxcr__filtergrid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 820px) { .pxcr__filtergrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .pxcr__filtergrid { grid-template-columns: minmax(0, 1fr); } }
.pxcr__filteract { display: flex; align-items: flex-end; }
.pxcr__daterange {
  display: inline-flex; align-items: center; gap: var(--pxn-space-3); width: 100%;
  height: var(--pxn-control-h-md); padding: 0 var(--pxn-space-5);
  border: 1px solid var(--pxn-border-control); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface); font: inherit; font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium);
  color: var(--pxn-ink); cursor: pointer;
}
.pxcr__daterange:hover { background: var(--pxn-surface-2); }
.pxcr__note { margin-top: var(--pxn-space-4); }
.pxcr__tablewrap { margin-top: var(--pxn-space-5); }
.pxcr__muted { color: var(--pxn-ink-3); }
.pxcr__block { display: block; }
.pxcr__totalrow { display: flex; align-items: center; justify-content: flex-end; gap: var(--pxn-space-6); margin-top: var(--pxn-space-3); padding: var(--pxn-space-3) var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); flex-wrap: wrap; }
.pxcr__totalrow > span:first-child { margin-right: auto; font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); }

.pxcr-detail { color: var(--pxn-ink); }
.pxcr-detail__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--pxn-space-5); padding-bottom: var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); }
.pxcr-detail__kicker { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.pxcr-detail__header h4 { margin: var(--pxn-space-2) 0; font-weight: var(--pxn-fw-bold); font-size: var(--pxn-fs-lg); }
.pxcr-detail__header p { margin: 0; color: var(--pxn-ink-3); }
.pxcr-detail__actions { display: flex; align-items: center; gap: var(--pxn-space-3); }
.pxcr-detail__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4); margin-top: var(--pxn-space-4); }
@media (max-width: 768px) { .pxcr-detail__grid { grid-template-columns: minmax(0, 1fr); } }
.pxcr-detail__panel { min-width: 0; padding: var(--pxn-space-5); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pxcr-detail__panel h5 { margin: 0 0 var(--pxn-space-3); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-2); }
.pxcr-detail__line, .pxcr-detail__denomrow, .pxcr-detail__denomhead { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--pxn-space-4); padding: var(--pxn-space-2) 0; border-bottom: 1px solid var(--pxn-border); font-size: var(--pxn-fs-sm); }
.pxcr-detail__line span { color: var(--pxn-ink-3); }
.pxcr-detail__line strong, .pxcr-detail__denomrow strong { text-align: right; white-space: nowrap; }
.pxcr-detail__line--total { font-weight: var(--pxn-fw-bold); }
.pxcr-detail__denomhead, .pxcr-detail__denomrow { grid-template-columns: minmax(90px, 1fr) 80px minmax(90px, 1fr); }
.pxcr-detail__denomhead { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-bold); color: var(--pxn-ink-3); }
.pxcr-detail__denomhead span:last-child, .pxcr-detail__denomrow span:nth-child(2), .pxcr-detail__denomrow strong { text-align: right; }
.pxcr-detail__note { margin-top: var(--pxn-space-3); }
.pxcr-detail__note p { margin: var(--pxn-space-1) 0 0; color: var(--pxn-ink-2); white-space: pre-wrap; }
.pxcr ::v-deep .daterangepicker { z-index: 2055 !important; }
</style>
