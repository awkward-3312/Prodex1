<template>
  <div class="main-content">
    <breadcumb :page="$t('Cash_Register_Report')" :folder="$t('Reports')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card class="wrapper" v-if="!isLoading">
      <div class="row align-items-end">
        <div class="col-lg-2 col-md-4 mb-2">
          <label class="mb-1 d-block text-muted">{{$t('DateRange')}}</label>
          <date-range-picker
            v-model="dateRange"
            :locale-data="locale"
            :autoApply="true"
            :showDropdowns="true"
            :opens="'right'"
            :drops="'down'"
            :parentEl="'body'"
            :linkedCalendars="false"
            @update="onDateRangeUpdate"
          >
            <template v-slot:input="picker">
              <b-button variant="light" class="btn-pill">
                <lucide-icon class="mr-1" name="calendar-days" />
                {{ fmt(picker.startDate) }} - {{ fmt(picker.endDate) }}
              </b-button>
            </template>
          </date-range-picker>
        </div>
        <div class="col-lg-2 col-md-4 mb-2">
          <label>Número de caja</label>
          <b-form-input v-model="filters.register_id" placeholder="Register #" @keyup.enter="getData(1)"></b-form-input>
        </div>
        <div class="col-lg-2 col-md-4 mb-2">
          <label>{{$t('Cashier')}}</label>
          <b-form-select v-model="filters.user_id" :options="userOptions" @change="getData(1)"></b-form-select>
        </div>
        <div class="col-lg-2 col-md-4 mb-2">
          <label>{{$t('warehouse')}}</label>
          <b-form-select v-model="filters.warehouse_id" :options="warehouseOptions" @change="getData(1)"></b-form-select>
        </div>
        <div class="col-lg-2 col-md-4 mb-2">
          <label>Estado del arqueo</label>
          <b-form-select v-model="filters.closing_status" :options="closingStatusOptions" @change="getData(1)"></b-form-select>
        </div>
        <div class="col-lg-2 col-md-4 mb-2">
          <b-button block variant="outline-secondary" @click="resetFilters">
            <lucide-icon name="refresh-cw" /> {{$t('Reset') || 'Reset'}}
          </b-button>
        </div>
      </div>

      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="rows"
        :group-options="{
          enabled: true,
          headerPosition: 'bottom',
        }"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="tableOne table-hover vgt-table mt-3"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="printTableOnly()" size="sm" variant="outline-secondary ripple m-1">
            <lucide-icon name="printer" /> {{ $t("print") }}
          </b-button>
        </div>
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'id'">
            #{{ props.row.id }}
          </span>
          <span v-else-if="props.column.field === 'register_number'">
            {{ props.row.register_number }}
          </span>
          <span v-else-if="props.column.field === 'cashier'">
            {{ formatCashier(props.row) }}
          </span>
          <span v-else-if="props.column.field === 'warehouse'">
            {{ props.row.warehouse_name }}
          </span>
          <span v-else-if="props.column.field === 'opened_at' || props.column.field === 'closed_at'">
            {{ formatDate(props.row[props.column.field]) }}
          </span>
          <span v-else-if="props.column.field === 'closed_date'">
            {{ formatDateOnly(props.row.closed_date || props.row.closed_at) }}
          </span>
          <span v-else-if="props.column.field === 'opened_time' || props.column.field === 'closed_time'">
            {{ formatTime(props.row[props.column.field]) }}
          </span>
          <span v-else-if="['opening_balance','total_sales','cash_sales','card_sales','transfer_sales','other_sales','expected_cash','counted_cash','difference'].includes(props.column.field)">
            {{ formatMoney(props.row[props.column.field]) }}
          </span>
          <span v-else-if="props.column.field === 'closing_status'">
            <span class="badge" :class="auditBadgeClass(props.row.closing_status)">{{ props.row.closing_status_label }}</span>
          </span>
          <span v-else-if="props.column.field === 'actions'">
            <b-button size="sm" variant="outline-primary" @click="showDetail(props.row)">
              <lucide-icon name="eye" /> Ver detalle
            </b-button>
          </span>
          <span v-else>
            {{ props.formattedRow[props.column.field] }}
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal id="cash-register-detail" hide-footer size="xl" title="Detalle del cierre de caja">
      <div v-if="selectedRegister" class="cr-history-detail">
        <div class="crd-header">
          <div>
            <div class="crd-kicker">Cierre #{{ selectedRegister.id }}</div>
            <h4>{{ selectedRegister.register_number }}</h4>
            <p>
              Cajero: {{ formatCashier(selectedRegister) }} · Almacén: {{ selectedRegister.warehouse_name || '-' }}
            </p>
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
            <h5>Identificación de sesión</h5>
            <div class="crd-line"><span>Caja</span><strong>{{ selectedRegister.register_number }}</strong></div>
            <div class="crd-line"><span>Abrió</span><strong>{{ selectedRegister.opened_by_user_name || '-' }} (#{{ selectedRegister.opened_by_user_id || '-' }})</strong></div>
            <div class="crd-line"><span>Cerró</span><strong>{{ selectedRegister.closed_by_user_name || '-' }} (#{{ selectedRegister.closed_by_user_id || '-' }})</strong></div>
            <div class="crd-line"><span>Almacén</span><strong>{{ selectedRegister.warehouse_name || '-' }} (#{{ selectedRegister.warehouse_snapshot_id || selectedRegister.warehouse_id || '-' }})</strong></div>
            <div class="crd-line"><span>Tenant</span><strong>{{ selectedRegister.tenant_id || '-' }}</strong></div>
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
            <h5>Resumen de ventas por método</h5>
            <div class="crd-line" v-for="method in selectedRegister.sales_by_payment_method || []" :key="'detail-method-' + method.id + '-' + method.name">
              <span>{{ method.name }}</span>
              <strong>{{ formatMoney(method.total) }}</strong>
            </div>
            <div class="crd-line crd-total"><span>Total vendido</span><strong>{{ formatMoney(selectedRegister.total_sales) }}</strong></div>
          </div>

          <div class="crd-panel">
            <h5>Conciliación</h5>
            <div class="crd-line"><span>Tarjeta según Prodex</span><strong>{{ formatMoney(selectedRegister.card_system_total) }}</strong></div>
            <div class="crd-line"><span>Cierre terminal</span><strong>{{ selectedRegister.card_terminal_total === null ? '-' : formatMoney(selectedRegister.card_terminal_total) }}</strong></div>
            <div class="crd-line"><span>Diferencia tarjeta</span><strong>{{ selectedRegister.card_difference === null ? '-' : formatMoney(selectedRegister.card_difference) }}</strong></div>
            <div class="crd-line"><span>Lote</span><strong>{{ selectedRegister.card_batch_number || '-' }}</strong></div>
            <div class="crd-line"><span>Referencia</span><strong>{{ selectedRegister.card_reference || '-' }}</strong></div>
            <div class="crd-line"><span>Transferencias</span><strong>{{ formatMoney(selectedRegister.transfer_total) }}</strong></div>
            <div class="crd-line"><span>Verificadas</span><strong>{{ selectedRegister.transfers_verified ? 'Sí' : 'No' }}</strong></div>
          </div>
        </div>

        <div class="crd-grid">
          <div class="crd-panel">
            <h5>Arqueo por denominaciones</h5>
            <div class="crd-denom-head">
              <span>Denominación</span><span>Cantidad</span><span>Subtotal</span>
            </div>
            <div class="crd-denom-row" v-for="row in denominationRows(selectedRegister)" :key="'denom-' + row.denomination">
              <span>{{ row.label }}</span><span>{{ row.quantity }}</span><strong>{{ formatMoney(row.subtotal) }}</strong>
            </div>
          </div>

          <div class="crd-panel">
            <h5>Fondo y notas</h5>
            <div class="crd-line"><span>Efectivo retirado al cierre</span><strong>{{ selectedRegister.cash_withdrawn_at_close === null ? '-' : formatMoney(selectedRegister.cash_withdrawn_at_close) }}</strong></div>
            <div class="crd-line"><span>Fondo siguiente apertura</span><strong>{{ selectedRegister.next_opening_float === null ? '-' : formatMoney(selectedRegister.next_opening_float) }}</strong></div>
            <div class="crd-note"><strong>Notas cierre</strong><p>{{ selectedRegister.notes || '-' }}</p></div>
            <div class="crd-note"><strong>Notas tarjeta</strong><p>{{ selectedRegister.card_notes || '-' }}</p></div>
            <div class="crd-note"><strong>Notas transferencias</strong><p>{{ selectedRegister.transfer_notes || '-' }}</p></div>
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
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting,
  getPriceDecimals
} from "../../../../utils/priceFormat";

export default {
  metaInfo: { title: 'Cash Register Report' },
  components: { 'date-range-picker': DateRangePicker },
  data() {
    return {
      isLoading: true,
      serverParams: {
        sort: { field: 'closed_at', type: 'desc' },
        page: 1,
        perPage: 10,
        searchTerm: ''
      },
      totalRows: 0,
      rows: [{
        statut: '',
        children: [],
      }],
      userOptions: [{ value: '', text: this.$t('All') }],
      warehouseOptions: [{ value: '', text: this.$t('All') }],
      closingStatusOptions: [
        { value: '', text: this.$t('All') },
        { value: 'balanced', text: 'Cuadrada' },
        { value: 'over', text: 'Sobrante' },
        { value: 'short', text: 'Faltante' }
      ],
      filters: { register_id: '', user_id: '', warehouse_id: '', closing_status: '' },
      selectedRegister: null,
      dateRange: { startDate: new Date(new Date().setDate(new Date().getDate()-6)), endDate: new Date() },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null,
      locale: {
        Label: this.$t('Apply') || 'Apply',
        cancelLabel: this.$t('Cancel') || 'Cancel',
        weekLabel: 'W',
        customRangeLabel: this.$t('CustomRange') || 'Custom Range',
        daysOfWeek: ['Su','Mo','Tu','We','Th','Fr','Sa'],
        monthNames: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        firstDay: 1,
      },
    }
  },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    columns() {
      return [
        { label: '#', field: 'id', thClass: 'text-left', tdClass: 'text-left', sortable: true },
        { label: 'Caja/Register', field: 'register_number', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: 'Fecha', field: 'closed_date', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: 'Hora apertura', field: 'opened_time', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: 'Hora cierre', field: 'closed_time', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: 'Duración', field: 'session_duration_human', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: this.$t('Cashier'), field: 'cashier', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: this.$t('warehouse'), field: 'warehouse', thClass: 'text-left', tdClass: 'text-left', sortable: false },
        { label: this.$t('Opening'), field: 'opening_balance', headerField: this.sumOpeningBalance, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: this.$t('TotalSales'), field: 'total_sales', headerField: this.sumTotalSales, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Efectivo', field: 'cash_sales', headerField: this.sumCashSales, thClass: 'text-right', tdClass: 'text-right', sortable: false },
        { label: 'Tarjeta', field: 'card_sales', headerField: this.sumCardSales, thClass: 'text-right', tdClass: 'text-right', sortable: false },
        { label: 'Transferencias', field: 'transfer_sales', headerField: this.sumTransferSales, thClass: 'text-right', tdClass: 'text-right', sortable: false },
        { label: 'Otros', field: 'other_sales', headerField: this.sumOtherSales, thClass: 'text-right', tdClass: 'text-right', sortable: false },
        { label: 'Efectivo esperado', field: 'expected_cash', headerField: this.sumExpectedCash, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Efectivo contado', field: 'counted_cash', headerField: this.sumCountedCash, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: this.$t('Difference'), field: 'difference', headerField: this.sumDifference, thClass: 'text-right', tdClass: 'text-right', sortable: true },
        { label: 'Estado', field: 'closing_status', thClass: 'text-left', tdClass: 'text-left', sortable: true },
        { label: this.$t('Action'), field: 'actions', html: true, thClass: 'text-right', tdClass: 'text-right', sortable: false },
      ]
    }
  },
  created() {
    this.bootstrapFilters().finally(() => {
      this.getData(1)
    })
  },
  methods: {
    // Same as dashboard: format date for picker display and API params (YYYY-MM-DD, local time via moment)
    fmt(d) { try { return moment(d).format('YYYY-MM-DD') } catch(e) { return '' } },
    onDateRangeUpdate() { this.getData(1) },
    bootstrapFilters() {
      // Load from the same endpoint (like sales_report) to avoid extra requests
      return this.getData(1, true)
    },
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps)
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage })
        this.getData(currentPage)
      }
    },
    onPerPageChange({ currentPerPage }) {
      if (this.serverParams.perPage !== currentPerPage) {
        this.updateParams({ page: 1, perPage: currentPerPage })
        this.getData(1)
      }
    },
    onSortChange(params) {
      if (!params || !params[0]) return
      const { field, type } = params[0]
      this.updateParams({ sort: { field, type } })
      this.getData(1)
    },
    onSearch(value) {
      this.updateParams({ searchTerm: value })
      this.getData(1)
    },
    getData(page = 1, preloadOnly = false) {
      NProgress.start(); NProgress.set(0.1)
      this.isLoading = true
      const params = {
        page: page,
        limit: this.serverParams.perPage,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.serverParams.searchTerm,
        register_id: this.filters.register_id || undefined,
        user_id: this.filters.user_id || undefined,
        warehouse_id: this.filters.warehouse_id || undefined,
        closing_status: this.filters.closing_status || undefined,
        from: this.fmt(this.dateRange.startDate),
        to: this.fmt(this.dateRange.endDate),
      }
      return axios.get('report/cash_registers', { params }).then(res => {
        // Mirrors sales_report payload style
        const payload = res.data || {}
        const data = payload.registers || []
        if (!preloadOnly) {
          this.rows[0].children = data
          this.totalRows = payload.totalRows || data.length
        }
        // Preload users & warehouses for filters
        if (Array.isArray(payload.users)) {
          const users = payload.users.map(x => ({ value: x.id, text: (x.firstname && x.lastname) ? (x.firstname + ' ' + x.lastname) : (x.username || x.name || ('User #' + x.id)) }))
          this.userOptions = [{ value: '', text: this.$t('All') }, ...users]
        }
        if (Array.isArray(payload.warehouses)) {
          const warehouses = payload.warehouses.map(x => ({ value: x.id, text: x.name }))
          this.warehouseOptions = [{ value: '', text: this.$t('All') }, ...warehouses]
        }
      }).catch(() => {
        if (this.$bvToast && this.$bvToast.toast) this.$bvToast.toast(this.$t('OperationFailed'), { title: this.$t('Failed'), variant: 'danger', solid: true })
      }).finally(() => {
        this.isLoading = false
        setTimeout(() => NProgress.done(), 300)
      })
    },
    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing toFixed behavior to preserve current behavior.
    formatMoney(x) {
      try {
        const n = parseFloat(x || 0);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(n, this.priceDecimals, effectiveKey);
      } catch (e) {
        const n = parseFloat(x || 0);
        return n.toFixed(this.priceDecimals);
      }
    },
    formatDate(x) {
      if (!x) return '-'
      // Get date format from Vuex store (loaded from database) or fallback
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store)
      // formatDisplayDate now handles time preservation automatically
      return Util.formatDisplayDate(x, dateFormat)
    },
    formatDateOnly(x) {
      if (!x) return '-'
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store)
      return moment(x).format(dateFormat || 'YYYY-MM-DD')
    },
    formatTime(x) {
      if (!x) return '-'
      const parsed = moment(String(x), ['HH:mm:ss', 'HH:mm'])
      return parsed.isValid() ? parsed.format('hh:mm A') : x
    },
    formatCashier(row) {
      return row.cashier_name || row.opened_by_user_name || row.closed_by_user_name || ('User #' + (row.user_id || row.opened_by_user_id || '-'))
    },
    auditBadgeClass(status) {
      if (status === 'balanced') return 'badge-success'
      if (status === 'over') return 'badge-warning'
      if (status === 'short') return 'badge-danger'
      return 'badge-secondary'
    },
    showDetail(row) {
      this.selectedRegister = row
      this.$bvModal.show('cash-register-detail')
    },
    snapshotValue(key, fallback = null) {
      const snapshot = this.selectedRegister && this.selectedRegister.closing_snapshot ? this.selectedRegister.closing_snapshot : {}
      return snapshot[key] !== undefined && snapshot[key] !== null ? snapshot[key] : fallback
    },
    denominationRows(row) {
      const counted = row && row.counted_denominations ? row.counted_denominations : {}
      const denominations = row && row.closing_snapshot && row.closing_snapshot.denominations ? row.closing_snapshot.denominations : {}
      const symbol = denominations.currency_symbol || ''
      const order = []
      if (Array.isArray(denominations.bills)) order.push(...denominations.bills)
      if (Array.isArray(denominations.coins)) order.push(...denominations.coins)
      const keys = order.length ? order : Object.keys(counted).map(Number).sort((a, b) => b - a)

      return keys.map(value => {
        const quantity = Number(counted[value] || counted[String(value)] || 0)
        const denomination = Number(value) || 0
        return {
          denomination,
          quantity,
          subtotal: denomination * quantity,
          label: `${symbol ? symbol + ' ' : ''}${this.formatDenomination(denomination)}`
        }
      })
    },
    formatDenomination(value) {
      const n = Number(value) || 0
      return n < 1 ? n.toFixed(2) : n.toFixed(0)
    },

    // Group footer helpers for vue-good-table
    sumOpeningBalance(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].opening_balance || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumCashIn(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].cash_in || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumCashOut(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].cash_out || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumCashSales(rowObj) {
      return this.sumField(rowObj, 'cash_sales');
    },

    sumCardSales(rowObj) {
      return this.sumField(rowObj, 'card_sales');
    },

    sumTransferSales(rowObj) {
      return this.sumField(rowObj, 'transfer_sales');
    },

    sumOtherSales(rowObj) {
      return this.sumField(rowObj, 'other_sales');
    },

    sumExpectedCash(rowObj) {
      return this.sumField(rowObj, 'expected_cash');
    },

    sumCountedCash(rowObj) {
      return this.sumField(rowObj, 'counted_cash');
    },

    sumField(rowObj, field) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i][field] || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumTotalSales(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].total_sales || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumClosingBalance(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].closing_balance || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },

    sumDifference(rowObj) {
      if (!rowObj || !Array.isArray(rowObj.children)) {
        return this.formatMoney(0);
      }
      let sum = 0;
      for (let i = 0; i < rowObj.children.length; i++) {
        const value = parseFloat(rowObj.children[i].difference || 0);
        if (Number.isFinite(value)) {
          sum += value;
        }
      }
      return this.formatMoney(sum);
    },
    resetFilters() {
      this.filters = { register_id: '', user_id: '', warehouse_id: '', closing_status: '' }
      this.dateRange = { startDate: new Date(new Date().setDate(new Date().getDate()-6)), endDate: new Date() }
      this.getData(1)
    },

    //------ Print Table Only - Print ALL cash register data with all columns
    printTableOnly() {
      const title = `${this.$t("Reports")} / ${this.$t("Cash_Register_Report")}`;
      const registers = Array.isArray(this.rows[0]?.children) ? this.rows[0].children : [];
      
      // Build table header with all columns
      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';
      
      this.columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';
      
      // Build table rows with all data - format each cell according to column type
      registers.forEach(register => {
        tableHTML += '<tr>';
        this.columns.forEach(col => {
          let cellValue = '';
          
          if (col.field === 'actions') {
            cellValue = ''
          } else if (col.field === 'id') {
            cellValue = `#${register.id}`
          } else if (col.field === 'cashier') {
            cellValue = this.formatCashier(register);
          } else if (col.field === 'warehouse') {
            cellValue = register.warehouse_name || '';
          } else if (col.field === 'opened_at' || col.field === 'closed_at') {
            cellValue = this.formatDate(register[col.field]);
          } else if (['opening_balance','total_sales','cash_sales','card_sales','transfer_sales','other_sales','expected_cash','counted_cash','difference'].includes(col.field)) {
            cellValue = this.formatMoney(register[col.field]);
          } else if (col.field === 'closing_status') {
            cellValue = register.closing_status_label || '';
          } else {
            // Default: get value directly from register object
            cellValue = register[col.field] || '';
          }
          
          tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; text-align: left;">${cellValue}</td>`;
        });
        tableHTML += '</tr>';
      });
      
      tableHTML += '</tbody></table>';

      const w = window.open("", "_blank");
      if (!w) {
        alert("Please allow popups to print");
        return;
      }

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      /* Force visibility in print (some global POS print CSS hides body) */
      @media print { 
        body, body * { visibility: visible !important; }
        @page { size: A4 landscape; margin: 0.3cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 10px; font-size: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 10px; }
      th { background-color: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },
    printRegisterDetail(register) {
      if (!register) return
      const methods = (register.sales_by_payment_method || []).map(method => `
        <tr><td>${method.name || ''}</td><td style="text-align:right;">${this.formatMoney(method.total)}</td></tr>
      `).join('')
      const denominations = this.denominationRows(register).map(row => `
        <tr><td>${row.label}</td><td style="text-align:right;">${row.quantity}</td><td style="text-align:right;">${this.formatMoney(row.subtotal)}</td></tr>
      `).join('')
      const title = `Cierre de caja #${register.id}`
      const w = window.open("", "_blank");
      if (!w) {
        alert("Please allow popups to print");
        return;
      }
      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>${title}</title>
    <style>
      @media print { @page { size: A4; margin: 0.8cm; } }
      body { font-family: Arial, sans-serif; color: #222; }
      h1 { font-size: 20px; margin: 0 0 6px; }
      h2 { font-size: 14px; margin: 18px 0 8px; }
      .muted { color: #666; margin-bottom: 12px; }
      .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
      th, td { border: 1px solid #ddd; padding: 6px 8px; font-size: 12px; }
      th { background: #f5f5f5; text-align: left; }
      .status { display: inline-block; padding: 4px 8px; border-radius: 10px; background: #f1f1f1; font-weight: 700; }
      pre { white-space: pre-wrap; font-family: Arial, sans-serif; border: 1px solid #ddd; padding: 8px; }
    </style>
  </head>
  <body>
    <h1>${title}</h1>
    <div class="muted">${register.register_number || ''} · ${register.closing_status_label || ''}</div>
    <div class="grid">
      <table>
        <tr><th colspan="2">Identificación</th></tr>
        <tr><td>Caja</td><td>${register.register_number || '-'}</td></tr>
        <tr><td>Cajero</td><td>${this.formatCashier(register)}</td></tr>
        <tr><td>Abrió</td><td>${register.opened_by_user_name || '-'} (#${register.opened_by_user_id || '-'})</td></tr>
        <tr><td>Cerró</td><td>${register.closed_by_user_name || '-'} (#${register.closed_by_user_id || '-'})</td></tr>
        <tr><td>Almacén</td><td>${register.warehouse_name || '-'}</td></tr>
        <tr><td>Apertura</td><td>${this.formatDate(register.opened_at)}</td></tr>
        <tr><td>Cierre</td><td>${this.formatDate(register.closed_at)}</td></tr>
        <tr><td>Duración</td><td>${register.session_duration_human || '-'}</td></tr>
      </table>
      <table>
        <tr><th colspan="2">Control de efectivo</th></tr>
        <tr><td>Fondo inicial</td><td style="text-align:right;">${this.formatMoney(register.opening_balance)}</td></tr>
        <tr><td>Entradas</td><td style="text-align:right;">${this.formatMoney(register.cash_in)}</td></tr>
        <tr><td>Retiros/salidas</td><td style="text-align:right;">${this.formatMoney(register.cash_out)}</td></tr>
        <tr><td>Efectivo esperado</td><td style="text-align:right;">${this.formatMoney(register.expected_cash)}</td></tr>
        <tr><td>Efectivo contado</td><td style="text-align:right;">${this.formatMoney(register.counted_cash)}</td></tr>
        <tr><td>Diferencia</td><td style="text-align:right;">${this.formatMoney(register.difference)}</td></tr>
      </table>
    </div>
    <h2>Ventas por método de pago</h2>
    <table><tbody>${methods}</tbody></table>
    <h2>Arqueo por denominaciones</h2>
    <table><thead><tr><th>Denominación</th><th>Cantidad</th><th>Subtotal</th></tr></thead><tbody>${denominations}</tbody></table>
    <h2>Conciliación</h2>
    <table>
      <tr><td>Tarjeta según Prodex</td><td style="text-align:right;">${this.formatMoney(register.card_system_total)}</td></tr>
      <tr><td>Cierre terminal</td><td style="text-align:right;">${register.card_terminal_total === null ? '-' : this.formatMoney(register.card_terminal_total)}</td></tr>
      <tr><td>Diferencia tarjeta</td><td style="text-align:right;">${register.card_difference === null ? '-' : this.formatMoney(register.card_difference)}</td></tr>
      <tr><td>Transferencias</td><td style="text-align:right;">${this.formatMoney(register.transfer_total)}</td></tr>
      <tr><td>Fondo siguiente apertura</td><td style="text-align:right;">${register.next_opening_float === null ? '-' : this.formatMoney(register.next_opening_float)}</td></tr>
    </table>
    <h2>Notas</h2>
    <pre>${register.notes || '-'}</pre>
  </body>
</html>`);
      doc.close();
      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    }
  }
}
</script>

<style scoped>
.cr-history-detail {
  color: #1f2937;
}
.crd-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #e5e7eb;
}
.crd-kicker {
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
}
.crd-header h4 {
  margin: 4px 0;
  font-weight: 800;
}
.crd-header p {
  margin: 0;
  color: #6b7280;
}
.crd-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.crd-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  margin-top: 14px;
}
.crd-panel {
  min-width: 0;
  padding: 14px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #fff;
}
.crd-panel h5 {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 800;
  text-transform: uppercase;
}
.crd-line,
.crd-denom-row,
.crd-denom-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  padding: 7px 0;
  border-bottom: 1px solid #f1f3f5;
}
.crd-line span {
  color: #6b7280;
}
.crd-line strong,
.crd-denom-row strong {
  text-align: right;
  white-space: nowrap;
}
.crd-total {
  font-weight: 800;
}
.crd-denom-head,
.crd-denom-row {
  grid-template-columns: minmax(90px, 1fr) 80px minmax(90px, 1fr);
}
.crd-denom-head {
  font-size: 12px;
  font-weight: 800;
  color: #6b7280;
}
.crd-denom-head span:last-child,
.crd-denom-row span:nth-child(2),
.crd-denom-row strong {
  text-align: right;
}
.crd-note {
  margin-top: 10px;
}
.crd-note p {
  margin: 4px 0 0;
  color: #4b5563;
  white-space: pre-wrap;
}
@media (max-width: 768px) {
  .crd-header,
  .crd-actions {
    flex-direction: column;
    align-items: stretch;
  }
  .crd-grid {
    grid-template-columns: 1fr;
  }
}
</style>
