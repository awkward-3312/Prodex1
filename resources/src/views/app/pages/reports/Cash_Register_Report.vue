<template>
  <div class="main-content">
    <breadcumb :page="$t('Cash_Register_Report')" :folder="$t('Reports')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card class="wrapper" v-if="!isLoading">
      <div class="row align-items-end">
        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label class="mb-1 d-block text-muted">{{$t('DateRange')}}</label>
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
              <b-button variant="light" class="btn-pill w-100 text-left">
                <lucide-icon class="mr-1" name="calendar-days" />
                {{ fmt(picker.startDate) }} - {{ fmt(picker.endDate) }}
              </b-button>
            </template>
          </date-range-picker>
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>Número de sesión</label>
          <b-form-input v-model="filters.register_id" placeholder="Ej. 25" @keyup.enter="getData(1)" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>{{$t('Cashier')}}</label>
          <b-form-select v-model="filters.user_id" :options="userOptions" @change="getData(1)" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>Sucursal</label>
          <b-form-select v-model="filters.branch_id" :options="branchOptions" @change="onBranchChange" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>Ubicación</label>
          <b-form-select v-model="filters.inventory_location_id" :options="locationOptions" @change="onLocationChange" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>Caja física</label>
          <b-form-select v-model="filters.cash_drawer_id" :options="drawerOptions" @change="getData(1)" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <label>Estado del arqueo</label>
          <b-form-select v-model="filters.closing_status" :options="closingStatusOptions" @change="getData(1)" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2" v-if="legacyWarehouseOptions.length > 1">
          <label>Almacén histórico</label>
          <b-form-select v-model="filters.legacy_warehouse_id" :options="legacyWarehouseOptions" @change="getData(1)" />
        </div>

        <div class="col-xl-2 col-lg-3 col-md-4 mb-2">
          <b-button block variant="outline-secondary" @click="resetFilters">
            <lucide-icon name="refresh-cw" /> {{$t('Reset') || 'Restablecer'}}
          </b-button>
        </div>
      </div>

      <div class="native-report-note mb-2">
        El reporte usa <strong>Sucursal → Ubicación → Caja física</strong>. “Almacén histórico” solo consulta sesiones antiguas sin contexto operativo nativo.
      </div>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="rows"
        :group-options="{ enabled: true, headerPosition: 'bottom' }"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="tableOne table-hover vgt-table mt-3"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="printTableOnly" size="sm" variant="outline-secondary ripple m-1">
            <lucide-icon name="printer" /> {{ $t('print') }}
          </b-button>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'id'">#{{ props.row.id }}</span>
          <span v-else-if="props.column.field === 'cashier'">{{ formatCashier(props.row) }}</span>
          <span v-else-if="props.column.field === 'operational_context'">
            <strong>{{ props.row.operational_context_label || '-' }}</strong>
            <small v-if="props.row.is_legacy_context" class="d-block text-muted">Compatibilidad histórica</small>
          </span>
          <span v-else-if="props.column.field === 'cash_drawer'">
            {{ props.row.cash_drawer_name || 'Sin caja física' }}
            <small v-if="props.row.cash_drawer_code" class="d-block text-muted">{{ props.row.cash_drawer_code }}</small>
          </span>
          <span v-else-if="props.column.field === 'closed_date'">{{ formatDateOnly(props.row.closed_date || props.row.closed_at) }}</span>
          <span v-else-if="props.column.field === 'opened_time' || props.column.field === 'closed_time'">{{ formatTime(props.row[props.column.field]) }}</span>
          <span v-else-if="moneyFields.includes(props.column.field)">{{ formatMoney(props.row[props.column.field]) }}</span>
          <span v-else-if="props.column.field === 'closing_status'">
            <span class="badge" :class="auditBadgeClass(props.row.closing_status)">{{ props.row.closing_status_label }}</span>
          </span>
          <span v-else-if="props.column.field === 'actions'">
            <b-button size="sm" variant="outline-primary" @click="showDetail(props.row)">
              <lucide-icon name="eye" /> Ver detalle
            </b-button>
          </span>
          <span v-else>{{ props.formattedRow[props.column.field] }}</span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal id="cash-register-detail" hide-footer size="xl" title="Detalle del cierre de caja">
      <div v-if="selectedRegister" class="cr-history-detail">
        <div class="crd-header">
          <div>
            <div class="crd-kicker">Sesión #{{ selectedRegister.id }}</div>
            <h4>{{ selectedRegister.register_number }}</h4>
            <p>{{ selectedRegister.operational_context_label || '-' }}</p>
          </div>
          <div class="crd-actions">
            <span class="badge" :class="auditBadgeClass(selectedRegister.closing_status)">{{ selectedRegister.closing_status_label }}</span>
            <b-button size="sm" variant="outline-secondary" @click="printRegisterDetail(selectedRegister)">
              <lucide-icon name="printer" /> Imprimir
            </b-button>
          </div>
        </div>

        <div class="crd-grid">
          <div class="crd-panel">
            <h5>Identificación operativa</h5>
            <div class="crd-line"><span>Sucursal</span><strong>{{ selectedRegister.branch_name || (selectedRegister.is_legacy_context ? 'Histórica' : '-') }}</strong></div>
            <div class="crd-line"><span>Ubicación</span><strong>{{ selectedRegister.inventory_location_name || '-' }}</strong></div>
            <div class="crd-line"><span>Caja física</span><strong>{{ selectedRegister.cash_drawer_name || 'Sin caja física' }}</strong></div>
            <div class="crd-line"><span>Cajero</span><strong>{{ formatCashier(selectedRegister) }}</strong></div>
            <div class="crd-line"><span>Abrió</span><strong>{{ selectedRegister.opened_by_user_name || '-' }}</strong></div>
            <div class="crd-line"><span>Cerró</span><strong>{{ selectedRegister.closed_by_user_name || '-' }}</strong></div>
            <div class="crd-line" v-if="selectedRegister.is_legacy_context"><span>Almacén histórico</span><strong>{{ selectedRegister.warehouse_name || '-' }}</strong></div>
            <div class="crd-line"><span>Apertura</span><strong>{{ formatDate(selectedRegister.opened_at) }}</strong></div>
            <div class="crd-line"><span>Cierre</span><strong>{{ formatDate(selectedRegister.closed_at) }}</strong></div>
            <div class="crd-line"><span>Duración</span><strong>{{ selectedRegister.session_duration_human || '-' }}</strong></div>
          </div>

          <div class="crd-panel">
            <h5>Control de efectivo</h5>
            <div class="crd-line"><span>Fondo inicial</span><strong>{{ formatMoney(selectedRegister.opening_balance) }}</strong></div>
            <div class="crd-line"><span>Entradas</span><strong>{{ formatMoney(selectedRegister.cash_in) }}</strong></div>
            <div class="crd-line"><span>Retiros/salidas</span><strong>{{ formatMoney(selectedRegister.cash_out) }}</strong></div>
            <div class="crd-line"><span>Devoluciones en efectivo</span><strong>{{ formatMoney(snapshotValue('cash_refunds', 0)) }}</strong></div>
            <div class="crd-line"><span>Efectivo esperado</span><strong>{{ formatMoney(selectedRegister.expected_cash) }}</strong></div>
            <div class="crd-line"><span>Efectivo contado</span><strong>{{ formatMoney(selectedRegister.counted_cash) }}</strong></div>
            <div class="crd-line"><span>Diferencia</span><strong>{{ formatMoney(selectedRegister.difference) }}</strong></div>
          </div>
        </div>

        <div class="crd-grid">
          <div class="crd-panel">
            <h5>Ventas por método</h5>
            <div class="crd-line" v-for="method in selectedRegister.sales_by_payment_method || []" :key="method.name + '-' + method.id">
              <span>{{ method.name }}</span><strong>{{ formatMoney(method.total) }}</strong>
            </div>
            <div class="crd-line crd-total"><span>Total vendido</span><strong>{{ formatMoney(selectedRegister.total_sales) }}</strong></div>
          </div>

          <div class="crd-panel">
            <h5>Conciliación</h5>
            <div class="crd-line"><span>Tarjeta según PRODEX</span><strong>{{ formatMoney(selectedRegister.card_system_total) }}</strong></div>
            <div class="crd-line"><span>Cierre terminal</span><strong>{{ selectedRegister.card_terminal_total === null ? '-' : formatMoney(selectedRegister.card_terminal_total) }}</strong></div>
            <div class="crd-line"><span>Diferencia tarjeta</span><strong>{{ selectedRegister.card_difference === null ? '-' : formatMoney(selectedRegister.card_difference) }}</strong></div>
            <div class="crd-line"><span>Transferencias</span><strong>{{ formatMoney(selectedRegister.transfer_total) }}</strong></div>
            <div class="crd-line"><span>Transferencias verificadas</span><strong>{{ selectedRegister.transfers_verified ? 'Sí' : 'No' }}</strong></div>
          </div>
        </div>

        <div class="crd-grid">
          <div class="crd-panel">
            <h5>Arqueo por denominaciones</h5>
            <div class="crd-denom-head"><span>Denominación</span><span>Cantidad</span><span>Subtotal</span></div>
            <div class="crd-denom-row" v-for="row in denominationRows(selectedRegister)" :key="row.denomination">
              <span>{{ row.label }}</span><span>{{ row.quantity }}</span><strong>{{ formatMoney(row.subtotal) }}</strong>
            </div>
          </div>

          <div class="crd-panel">
            <h5>Fondo y notas</h5>
            <div class="crd-line"><span>Efectivo retirado</span><strong>{{ selectedRegister.cash_withdrawn_at_close === null ? '-' : formatMoney(selectedRegister.cash_withdrawn_at_close) }}</strong></div>
            <div class="crd-line"><span>Fondo siguiente apertura</span><strong>{{ selectedRegister.next_opening_float === null ? '-' : formatMoney(selectedRegister.next_opening_float) }}</strong></div>
            <div class="crd-note"><strong>Notas</strong><p>{{ selectedRegister.notes || '-' }}</p></div>
            <div class="crd-note"><strong>Tarjeta</strong><p>{{ selectedRegister.card_notes || '-' }}</p></div>
            <div class="crd-note"><strong>Transferencias</strong><p>{{ selectedRegister.transfer_notes || '-' }}</p></div>
          </div>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from 'nprogress'
import moment from 'moment'
import DateRangePicker from 'vue2-daterange-picker'
import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import Util from '../../../../utils'
import { formatPriceDisplay as formatPriceDisplayHelper, getPriceFormatSetting, getPriceDecimals } from '../../../../utils/priceFormat'

export default {
  metaInfo: { title: 'Cash Register Report' },
  components: { 'date-range-picker': DateRangePicker },
  data() {
    return {
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
      closingStatusOptions: [
        { value: '', text: this.$t('All') },
        { value: 'balanced', text: 'Cuadrada' },
        { value: 'over', text: 'Sobrante' },
        { value: 'short', text: 'Faltante' }
      ],
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
    userOptions() {
      return [{ value: '', text: this.$t('All') }].concat(this.users.map(x => ({ value: x.id, text: this.userName(x) })))
    },
    branchOptions() {
      return [{ value: '', text: this.$t('All') }].concat(this.branches.map(x => ({ value: x.id, text: x.name })))
    },
    locationOptions() {
      const branchId = Number(this.filters.branch_id || 0)
      const rows = branchId ? this.locations.filter(x => Number(x.branch_id) === branchId) : this.locations
      return [{ value: '', text: this.$t('All') }].concat(rows.map(x => ({ value: x.id, text: x.name })))
    },
    drawerOptions() {
      const branchId = Number(this.filters.branch_id || 0)
      const locationId = Number(this.filters.inventory_location_id || 0)
      let rows = this.drawers
      if (locationId) rows = rows.filter(x => Number(x.inventory_location_id) === locationId)
      else if (branchId) rows = rows.filter(x => Number(x.branch_id) === branchId)
      return [{ value: '', text: this.$t('All') }].concat(rows.map(x => ({ value: x.id, text: x.code ? `${x.name} · ${x.code}` : x.name })))
    },
    legacyWarehouseOptions() {
      return [{ value: '', text: this.$t('All') }].concat(this.legacyWarehouses.map(x => ({ value: x.id, text: x.name })))
    },
    columns() {
      return [
        { label: '#', field: 'id', sortable: true },
        { label: 'Sesión', field: 'register_number', sortable: false },
        { label: 'Fecha', field: 'closed_date', sortable: false },
        { label: 'Apertura', field: 'opened_time', sortable: false },
        { label: 'Cierre', field: 'closed_time', sortable: false },
        { label: this.$t('Cashier'), field: 'cashier', sortable: false },
        { label: 'Sucursal / Ubicación', field: 'operational_context', sortable: false },
        { label: 'Caja física', field: 'cash_drawer', sortable: false },
        { label: this.$t('TotalSales'), field: 'total_sales', headerField: this.sumTotalSales, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Efectivo esperado', field: 'expected_cash', headerField: row => this.sumField(row, 'expected_cash'), thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Efectivo contado', field: 'counted_cash', headerField: row => this.sumField(row, 'counted_cash'), thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: this.$t('Difference'), field: 'difference', headerField: row => this.sumField(row, 'difference'), thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Estado', field: 'closing_status', sortable: true },
        { label: this.$t('Action'), field: 'actions', sortable: false, thClass: 'text-right', tdClass: 'text-right' }
      ]
    }
  },
  created() { this.getData(1) },
  methods: {
    fmt(d) { try { return moment(d).format('YYYY-MM-DD') } catch (e) { return '' } },
    userName(x) { return x.firstname || x.lastname ? `${x.firstname || ''} ${x.lastname || ''}`.trim() : (x.username || `User #${x.id}`) },
    onDateRangeUpdate() { this.getData(1) },
    onBranchChange() {
      if (this.filters.inventory_location_id && !this.locationOptions.some(x => Number(x.value) === Number(this.filters.inventory_location_id))) this.filters.inventory_location_id = ''
      this.filters.cash_drawer_id = ''
      this.getData(1)
    },
    onLocationChange() { this.filters.cash_drawer_id = ''; this.getData(1) },
    updateParams(next) { this.serverParams = Object.assign({}, this.serverParams, next) },
    onPageChange({ currentPage }) { this.updateParams({ page: currentPage }); this.getData(currentPage) },
    onPerPageChange({ currentPerPage }) { this.updateParams({ page: 1, perPage: currentPerPage }); this.getData(1) },
    onSortChange(params) { if (params && params[0]) { this.updateParams({ sort: { field: params[0].field, type: params[0].type } }); this.getData(1) } },
    onSearch(value) { this.updateParams({ searchTerm: value }); this.getData(1) },
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
    auditBadgeClass(status) { return status === 'balanced' ? 'badge-success' : status === 'over' ? 'badge-warning' : status === 'short' ? 'badge-danger' : 'badge-secondary' },
    showDetail(row) { this.selectedRegister = row; this.$bvModal.show('cash-register-detail') },
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
    sumField(rowObj, field) {
      if (!rowObj || !Array.isArray(rowObj.children)) return this.formatMoney(0)
      return this.formatMoney(rowObj.children.reduce((sum, row) => sum + (parseFloat(row[field] || 0) || 0), 0))
    },
    sumTotalSales(rowObj) { return this.sumField(rowObj, 'total_sales') },
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

<style scoped>
.native-report-note { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f8fafc; color: #64748b; font-size: 12px; }
.cr-history-detail { color: #1f2937; }
.crd-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding-bottom:12px; border-bottom:1px solid #e5e7eb; }
.crd-kicker { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; }
.crd-header h4 { margin:4px 0; font-weight:800; }
.crd-header p { margin:0; color:#6b7280; }
.crd-actions { display:flex; align-items:center; gap:8px; }
.crd-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:14px; }
.crd-panel { min-width:0; padding:14px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
.crd-panel h5 { margin:0 0 10px; font-size:13px; font-weight:800; text-transform:uppercase; }
.crd-line,.crd-denom-row,.crd-denom-head { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:12px; padding:7px 0; border-bottom:1px solid #f1f3f5; }
.crd-line span { color:#6b7280; }
.crd-line strong,.crd-denom-row strong { text-align:right; white-space:nowrap; }
.crd-total { font-weight:800; }
.crd-denom-head,.crd-denom-row { grid-template-columns:minmax(90px,1fr) 80px minmax(90px,1fr); }
.crd-denom-head { font-size:12px; font-weight:800; color:#6b7280; }
.crd-denom-head span:last-child,.crd-denom-row span:nth-child(2),.crd-denom-row strong { text-align:right; }
.crd-note { margin-top:10px; }
.crd-note p { margin:4px 0 0; color:#4b5563; white-space:pre-wrap; }
@media(max-width:768px){.crd-header,.crd-actions{flex-direction:column;align-items:stretch}.crd-grid{grid-template-columns:1fr}}
</style>
