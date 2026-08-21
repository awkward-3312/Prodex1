<template>
  <div class="main-content">
    <breadcumb page="Plantillas de roles" folder="Usuarios y accesos"/>

    <b-card class="mb-3">
      <div class="d-flex flex-wrap align-items-start justify-content-between">
        <div>
          <h4 class="mb-1">Roles operativos recomendados</h4>
          <p class="text-muted mb-0">Usa una plantilla como punto de partida. El puesto laboral no concede permisos automáticamente y puedes modificar el rol antes o después de crearlo.</p>
        </div>
        <b-button variant="outline-primary" class="mt-2 mt-md-0" @click="$router.push('/app/organization/employee-access')">
          <lucide-icon name="users" class="mr-1"/> Acceso de empleados
        </b-button>
      </div>
    </b-card>

    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else>
      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <b-row v-if="templates.length">
        <b-col v-for="template in templates" :key="template.key" lg="4" md="6" sm="12" class="mb-3">
          <b-card class="h-100 shadow-sm role-template-card">
            <div class="d-flex align-items-start justify-content-between mb-2">
              <div>
                <h5 class="mb-1">{{ template.name }}</h5>
                <small class="text-muted">{{ template.permissions.length }} permisos sugeridos</small>
              </div>
              <span class="role-key">{{ template.key }}</span>
            </div>
            <p class="text-muted text-13 mb-3">{{ template.description }}</p>
            <div class="permission-preview mb-3">
              <span v-for="permission in template.permissions.slice(0, 5)" :key="permission" class="permission-pill">{{ friendlyPermission(permission) }}</span>
              <span v-if="template.permissions.length > 5" class="permission-pill muted">+{{ template.permissions.length - 5 }}</span>
            </div>
            <b-button variant="primary" size="sm" @click="openTemplate(template)">
              Usar plantilla
            </b-button>
          </b-card>
        </b-col>
      </b-row>

      <b-card v-else class="text-center py-5 text-muted">
        No hay plantillas disponibles para los permisos instalados en este tenant.
      </b-card>
    </template>

    <b-modal id="role-template-modal" hide-footer size="lg" title="Crear rol desde plantilla">
      <template v-if="activeTemplate">
        <div class="alert alert-light border">
          <strong>{{ activeTemplate.name }}</strong>
          <div class="text-muted text-12 mt-1">{{ activeTemplate.description }}</div>
        </div>

        <b-form @submit.prevent="createRole">
          <b-row>
            <b-col md="6">
              <b-form-group label="Nombre del rol *">
                <b-form-input v-model.trim="form.name" required maxlength="120"/>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Descripción">
                <b-form-input v-model.trim="form.description" maxlength="500"/>
              </b-form-group>
            </b-col>
          </b-row>

          <div class="d-flex align-items-center justify-content-between mb-2">
            <div>
              <strong>Permisos incluidos</strong>
              <div class="text-muted text-12">Desmarca cualquier capacidad que esta empresa no necesite.</div>
            </div>
            <small class="text-muted">{{ form.permissions.length }} seleccionados</small>
          </div>

          <div class="permission-grid">
            <label v-for="permission in activeTemplate.permissions" :key="permission" class="permission-option">
              <input type="checkbox" v-model="form.permissions" :value="permission">
              <span>
                <strong>{{ friendlyPermission(permission) }}</strong>
                <small>{{ permission }}</small>
              </span>
            </label>
          </div>

          <div class="alert alert-info mt-3 mb-3 py-2">
            Esta plantilla define <strong>qué puede hacer</strong> el rol. La sucursal y las bodegas del usuario seguirán definiendo <strong>dónde puede hacerlo</strong>.
          </div>

          <div v-if="modalError" class="alert alert-danger">{{ modalError }}</div>

          <div class="d-flex justify-content-end">
            <b-button variant="outline-secondary" class="mr-2" @click="$bvModal.hide('role-template-modal')">Cancelar</b-button>
            <b-button variant="primary" type="submit" :disabled="saving">{{ saving ? 'Creando…' : 'Crear rol' }}</b-button>
          </div>
        </b-form>
      </template>
    </b-modal>
  </div>
</template>

<script>
export default {
  metaInfo: { title: 'Plantillas de roles' },
  data() {
    return {
      loading: true,
      saving: false,
      error: '',
      modalError: '',
      templates: [],
      activeTemplate: null,
      form: { name: '', description: '', permissions: [] },
    };
  },
  created() {
    this.loadTemplates();
  },
  methods: {
    async loadTemplates() {
      this.loading = true;
      this.error = '';
      try {
        const { data } = await axios.get('/organization/role-permission-templates', { meta: { skipErrorRedirect: true } });
        this.templates = data.templates || [];
      } catch (e) {
        const data = e && e.response && e.response.data;
        this.error = (data && data.message) || 'No se pudieron cargar las plantillas de roles.';
      } finally {
        this.loading = false;
      }
    },
    openTemplate(template) {
      this.activeTemplate = template;
      this.form = {
        name: template.name,
        description: template.description,
        permissions: [...template.permissions],
      };
      this.modalError = '';
      this.$bvModal.show('role-template-modal');
    },
    async createRole() {
      if (!this.form.name) return;
      this.saving = true;
      this.modalError = '';
      try {
        await axios.post('/permissions', {
          role: {
            name: this.form.name,
            description: this.form.description,
          },
          permissions: this.form.permissions,
        }, { meta: { skipErrorRedirect: true } });

        this.$bvModal.hide('role-template-modal');
        this.$root.$bvToast.toast('Rol creado desde la plantilla. Puedes ajustar sus permisos cuando lo necesites.', {
          title: 'Éxito', variant: 'success', solid: true
        });
        this.$router.push('/app/User_Management/permissions');
      } catch (e) {
        const data = e && e.response && e.response.data;
        if (data && data.errors) {
          const first = Object.values(data.errors)[0];
          this.modalError = Array.isArray(first) ? first[0] : first;
        } else {
          this.modalError = (data && data.message) || 'No se pudo crear el rol.';
        }
      } finally {
        this.saving = false;
      }
    },
    friendlyPermission(permission) {
      const labels = {
        dashboard: 'Tablero', Pos_view: 'Punto de venta', Sales_view: 'Ver ventas', Sales_add: 'Crear ventas', Sales_edit: 'Editar ventas',
        payment_sales_view: 'Ver pagos de ventas', payment_sales_add: 'Registrar pagos', payment_sales_edit: 'Editar pagos',
        Customers_view: 'Ver clientes', Customers_add: 'Crear clientes', Customers_edit: 'Editar clientes',
        products_view: 'Ver inventario', products_add: 'Crear productos', products_edit: 'Editar productos',
        transfer_view: 'Ver transferencias', transfer_add: 'Crear transferencias', transfer_edit: 'Recibir/editar transferencias',
        adjustment_view: 'Ver ajustes', adjustment_add: 'Crear ajustes', adjustment_edit: 'Editar ajustes', count_stock: 'Conteo de stock',
        stock_report: 'Reporte de stock', Warehouse_report: 'Reporte por bodega', inventory_valuation: 'Valuación de inventario',
        Purchases_view: 'Ver compras', Purchases_add: 'Crear compras', Purchases_edit: 'Editar compras',
        Suppliers_view: 'Ver proveedores', Suppliers_add: 'Crear proveedores', Suppliers_edit: 'Editar proveedores',
        Quotations_view: 'Ver cotizaciones', Quotations_add: 'Crear cotizaciones', Quotations_edit: 'Editar cotizaciones',
        Reports_sales: 'Reporte de ventas', Reports_purchase: 'Reporte de compras', Reports_profit: 'Reporte de utilidad',
        view_employee: 'Ver empleados', add_employee: 'Crear empleados', edit_employee: 'Editar empleados', attendance: 'Asistencia', leave: 'Vacaciones y permisos', payroll: 'Planilla',
        department: 'Departamentos', designation: 'Puestos', office_shift: 'Turnos', holiday: 'Feriados', branches_view: 'Ver sucursales',
        account: 'Cuentas', expense_view: 'Ver gastos', expense_add: 'Registrar gastos', expense_edit: 'Editar gastos', deposit_view: 'Ver depósitos', deposit_add: 'Registrar depósitos', deposit_edit: 'Editar depósitos',
        transfer_money: 'Transferencias de dinero', expenses_report: 'Reporte de gastos', deposits_report: 'Reporte de depósitos', report_transactions: 'Reporte de transacciones',
        shipment: 'Envíos', tasks: 'Tareas', Top_products: 'Productos principales', Top_customers: 'Clientes principales',
      };
      return labels[permission] || permission.replace(/_/g, ' ');
    },
  },
};
</script>

<style scoped>
.role-template-card { border: 1px solid #edf0f4; border-radius: 12px; }
.role-key { background: #f3f4f6; color: #667085; font-size: 10px; border-radius: 999px; padding: 3px 7px; }
.permission-preview { display: flex; flex-wrap: wrap; gap: 5px; min-height: 48px; }
.permission-pill { background: #eef2ff; color: #4f46e5; border-radius: 999px; padding: 3px 8px; font-size: 11px; }
.permission-pill.muted { background: #f3f4f6; color: #667085; }
.permission-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; max-height: 360px; overflow-y: auto; padding-right: 4px; }
.permission-option { display: flex; align-items: flex-start; gap: 9px; border: 1px solid #e6e9ef; border-radius: 9px; padding: 9px 10px; margin: 0; cursor: pointer; }
.permission-option input { margin-top: 3px; }
.permission-option span { display: flex; flex-direction: column; min-width: 0; }
.permission-option strong { font-size: 12px; color: #344054; }
.permission-option small { font-size: 10px; color: #98a2b3; }
@media (max-width: 767px) { .permission-grid { grid-template-columns: 1fr; } }
</style>
