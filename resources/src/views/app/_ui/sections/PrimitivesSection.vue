<template>
  <section>
    <section-head
      num="03"
      title="Controles"
      desc="PageHeader, botones, campos y selección. Un solo acento por grupo de acciones; el resto en neutro. Todos los estados: hover, activo, foco visible, deshabilitado, carga y error."
    />

    <!-- PageHeader -->
    <h3 class="pr-h3">PageHeader</h3>
    <px-card flush>
      <div class="pr-inset">
        <px-page-header
          title="Ventas"
          subtitle="Documentos fiscales emitidos desde el POS y desde ventas manuales."
          :breadcrumbs="[{ label: 'Inicio', href: '#' }, { label: 'Ventas' }]"
        >
          <template #title-badge><px-badge tone="success" icon="check">Facturación activa</px-badge></template>
          <template #meta>
            <span><lucide-icon name="building-2" :size="13" /> Sucursal San Pedro Sula</span>
            <span><lucide-icon name="clock" :size="13" /> Al día · 14:02</span>
            <span><lucide-icon name="hash" :size="13" /> Próx. correlativo <b class="pxn-mono">001-001-01-00045214</b></span>
          </template>
          <template #actions>
            <px-button variant="ghost" icon="download">Exportar</px-button>
            <px-button variant="primary" icon="plus">Nueva venta</px-button>
          </template>
        </px-page-header>
      </div>
    </px-card>

    <!-- Buttons -->
    <h3 class="pr-h3">Botones</h3>
    <div class="pr-panel">
      <div class="pr-btnrow">
        <px-button variant="primary">Primario</px-button>
        <px-button variant="secondary">Secundario</px-button>
        <px-button variant="ghost">Ghost</px-button>
        <px-button variant="subtle">Sutil</px-button>
        <px-button variant="danger">Anular</px-button>
        <px-button variant="link">Ver detalle</px-button>
      </div>
      <div class="pr-btnrow">
        <px-button variant="primary" icon="plus">Con icono</px-button>
        <px-button variant="secondary" trailing-icon="chevron-down">Menú</px-button>
        <px-button variant="secondary" icon="printer" icon-only aria-label="Imprimir" />
        <px-button variant="primary" loading>Guardando</px-button>
        <px-button variant="secondary" disabled>Deshabilitado</px-button>
      </div>
      <div class="pr-btnrow pr-btnrow--sizes">
        <px-button size="sm" variant="secondary">Small 32</px-button>
        <px-button size="md" variant="secondary">Medium 38</px-button>
        <px-button size="lg" variant="secondary">Large 44</px-button>
      </div>
    </div>

    <!-- Fields -->
    <h3 class="pr-h3">Campos</h3>
    <div class="pr-panel">
      <div class="pr-formgrid">
        <px-field label="Nombre del producto" required>
          <template #default="{ id, describedby }">
            <px-input :id="id" v-model="f.name" :describedby="describedby" placeholder="p. ej. Acetaminofén 500 mg" />
          </template>
        </px-field>

        <px-field label="Código SKU" hint="Se usa en etiquetas y en el POS.">
          <template #default="{ id, describedby }">
            <px-input :id="id" v-model="f.sku" class="pxn-mono" :describedby="describedby" icon-lead="scan-barcode" />
          </template>
        </px-field>

        <px-field label="Precio de venta" required>
          <template #default="{ id, describedby }">
            <px-input :id="id" v-model="f.price" numeric inputmode="decimal" :describedby="describedby" prefix="L" />
          </template>
        </px-field>

        <px-field label="Categoría">
          <template #default="{ id }">
            <px-select :id="id" v-model="f.cat" :options="cats" placeholder="Seleccione…" />
          </template>
        </px-field>

        <px-field label="Identificación tributaria (RTN)" :error="rtnError" hint="Honduras: 14 dígitos. En otros países cambia el formato y la validación.">
          <template #default="{ id, invalid, describedby }">
            <px-input :id="id" v-model="f.rtn" class="pxn-mono" :invalid="invalid" :describedby="describedby" inputmode="numeric" />
          </template>
        </px-field>

        <px-field label="Almacén por defecto" disabled>
          <template #default="{ id }">
            <px-select :id="id" :options="['CD Zona Norte']" value="CD Zona Norte" disabled />
          </template>
        </px-field>
      </div>

      <px-field label="Notas internas" optional>
        <template #default="{ id }">
          <px-textarea :id="id" v-model="f.notes" rows="3" placeholder="Visible solo para el equipo…" />
        </template>
      </px-field>

      <hr class="pxn-divider" />

      <div class="pr-selection">
        <div class="pr-selection__group">
          <span class="pr-selection__label">Checkbox</span>
          <px-check v-model="f.overselling">Permitir sobreventa controlada</px-check>
          <px-check v-model="f.batch">Controlar por lote y vencimiento</px-check>
          <px-check :model-value="true" disabled>Rastrear números de serie</px-check>
        </div>
        <div class="pr-selection__group">
          <span class="pr-selection__label">Radio</span>
          <px-check type="radio" v-model="f.costing" native-value="promedio">Costo promedio</px-check>
          <px-check type="radio" v-model="f.costing" native-value="peps">PEPS</px-check>
        </div>
        <div class="pr-selection__group">
          <span class="pr-selection__label">Switch</span>
          <px-check type="switch" v-model="f.online">Visible en tienda en línea</px-check>
          <px-check type="switch" v-model="f.price_login">Mostrar precio solo a clientes con sesión</px-check>
          <px-check type="switch" :model-value="false" disabled>Deshabilitado (apagado)</px-check>
          <px-check type="switch" :model-value="true" disabled>Deshabilitado (encendido)</px-check>
          <px-check type="switch" v-model="sw.noLabel" aria-label="Sin etiqueta visible: notificaciones por correo" />
          <span class="pr-cb__out">Sin etiqueta visible (arriba): {{ sw.noLabel ? "activado" : "desactivado" }}</span>
          <px-check type="switch" :model-value="sw.pessimistic" :loading="sw.pessimisticLoading" @change="onPessimisticDemo">
            {{ sw.pessimistic ? "Activo" : "Inactivo" }} · demo pesimista (~700 ms, como el piloto de Promociones)
          </px-check>
        </div>
      </div>

      <hr class="pxn-divider" />

      <div class="pr-sq">
        <span class="pr-selection__label">PxSearchInput · búsqueda y filtro de texto</span>
        <div class="pr-sq__grid">
          <div class="pr-sq__cell">
            <span class="pr-sq__cap">Vacío · placeholder</span>
            <px-search-input v-model="sq.a" aria-label="Buscar productos" placeholder="Buscar por nombre o código…" clear-label="Limpiar búsqueda" />
          </div>
          <div class="pr-sq__cell">
            <span class="pr-sq__cap">Con valor · clear (X) y Escape · valor: “{{ sq.b }}”</span>
            <px-search-input v-model="sq.b" aria-label="Buscar clientes" placeholder="Buscar…" @clear="sq.clears++" />
            <span class="pr-sq__cap">Eventos clear recibidos: {{ sq.clears }}</span>
          </div>
          <div class="pr-sq__cell">
            <span class="pr-sq__cap">Táctil · size="lg" (44 px)</span>
            <px-search-input v-model="sq.c" size="lg" aria-label="Buscar (táctil)" placeholder="Buscar…" />
          </div>
          <div class="pr-sq__cell">
            <span class="pr-sq__cap">Deshabilitado (con texto: sin clear)</span>
            <px-search-input model-value="Reporte de ventas" disabled aria-label="Buscar deshabilitado" />
          </div>
          <div class="pr-sq__cell">
            <span class="pr-sq__cap">Solo lectura</span>
            <px-search-input model-value="SKU-000482" readonly aria-label="Buscar solo lectura" />
          </div>
          <div class="pr-sq__cell pr-sq__cell--narrow">
            <span class="pr-sq__cap">Ancho compacto (el padre define el ancho)</span>
            <px-search-input v-model="sq.d" aria-label="Buscar compacto" placeholder="Buscar" />
          </div>
          <div class="pr-sq__cell pr-sq__cell--wide">
            <span class="pr-sq__cap">Texto largo, acentos, SKU y correo (no choca con la X)</span>
            <px-search-input v-model="sq.long" aria-label="Buscar texto largo" placeholder="Buscar…" />
            <div class="pr-sq__samples">
              <px-button size="sm" variant="secondary" @click="sq.long = 'Ñandú café jalapeño — camión frigorífico Ámbito Ünico 5000 unidades'">Acentos</px-button>
              <px-button size="sm" variant="secondary" @click="sq.long = 'SKU-000482-AZ-XL-2026'">SKU</px-button>
              <px-button size="sm" variant="secondary" @click="sq.long = 'nombre.muy.largo.de.contacto@empresa-distribuidora-regional.example.com'">Correo</px-button>
            </div>
          </div>
        </div>
        <span class="pr-sq__cap">En una toolbar simple (input + select + botón; no rediseña la toolbar)</span>
        <div class="pr-sq__toolbar">
          <div class="pr-sq__toolbar-search">
            <px-search-input v-model="sq.tb" aria-label="Buscar en la tabla" placeholder="Buscar por nombre, código o marca…" />
          </div>
          <px-select v-model="sq.status" :options="sqStatus" aria-label="Estado" />
          <px-button variant="secondary" icon="filter">Filtros</px-button>
        </div>
      </div>

      <hr class="pxn-divider" />

      <div class="pr-cb">
        <span class="pr-selection__label">PxCheckbox · trazo animado</span>
        <div class="pr-cb__grid">
          <div class="pr-selection__group">
            <px-checkbox v-model="cb.a">Desmarcado / marcado (v-model: {{ cb.a }})</px-checkbox>
            <px-checkbox v-model="cb.b">Marcado inicial</px-checkbox>
            <px-checkbox :model-value="false" disabled>Deshabilitado sin marcar</px-checkbox>
            <px-checkbox :model-value="true" disabled>Deshabilitado marcado</px-checkbox>
            <px-checkbox v-model="cb.err" invalid>Acepto los términos (inválido)</px-checkbox>
            <px-checkbox v-model="cb.long">Etiqueta larga: permitir sobreventa controlada por almacén cuando el stock disponible sea insuficiente para cubrir el pedido completo</px-checkbox>
            <px-checkbox v-model="cb.a" aria-label="Sin etiqueta visible" />
          </div>
          <div class="pr-selection__group">
            <px-checkbox :model-value="cbAll" :indeterminate="cbSome" @change="toggleAll">Seleccionar todos</px-checkbox>
            <px-checkbox v-for="it in cbItems" :key="it" v-model="cb.list" :native-value="it" class="pr-cb__child">Ítem {{ it }}</px-checkbox>
            <span class="pr-cb__out">Seleccionados: {{ cb.list.join(", ") || "ninguno" }}</span>
            <px-button size="sm" variant="secondary" @click="cb.a = !cb.a">Alternar 1.º por código</px-button>
          </div>
        </div>
      </div>

      <hr class="pxn-divider" />

      <div class="pr-ld">
        <span class="pr-selection__label">PxLoader · operación en curso</span>
        <div class="pr-ld__grid">
          <div class="pr-ld__cell"><px-loader size="sm" label="Guardando…" /><small>sm · con texto</small></div>
          <div class="pr-ld__cell"><px-loader size="md" label="Generando PDF…" /><small>md · con texto</small></div>
          <div class="pr-ld__cell"><px-loader size="lg" label="Importando productos…" /><small>lg · con texto</small></div>
          <div class="pr-ld__cell"><px-loader size="md" label="Procesando venta" hide-label /><small>md · texto solo accesible</small></div>
          <div class="pr-ld__cell pr-ld__cell--static"><px-loader size="md" label="Procesando…" /><small>reposo (reduced-motion)</small></div>
        </div>
      </div>

      <hr class="pxn-divider" />

      <div class="pr-es">
        <span class="pr-selection__label">PxEmptyState · sin contenido</span>
        <div class="pr-es__grid">
          <div class="pr-es__cell">
            <px-empty-state character title="No hay clientes todavía" description="Agrega tu primer cliente para comenzar a vender y dar seguimiento.">
              <px-button variant="primary" icon="plus" size="sm">Agregar cliente</px-button>
            </px-empty-state>
            <small>Vacío inicial · cadencia original (0.5 s)</small>
          </div>
          <div class="pr-es__cell">
            <px-empty-state character calm title="No hay clientes todavía" description="Agrega tu primer cliente para comenzar a vender y dar seguimiento.">
              <px-button variant="primary" icon="plus" size="sm">Agregar cliente</px-button>
            </px-empty-state>
            <small>Vacío inicial · cadencia tranquila (1.2 s)</small>
          </div>
          <div class="pr-es__cell">
            <px-empty-state character calm title="No encontramos resultados para «Samsung»" description="Revisa la ortografía o prueba con otro término.">
              <px-button variant="secondary" size="sm">Limpiar búsqueda</px-button>
            </px-empty-state>
            <small>Sin resultados (búsqueda o filtros)</small>
          </div>
          <div class="pr-es__cell pr-es__cell--static">
            <px-empty-state character title="No hay ventas todavía" />
            <small>Sin descripción ni CTA (pose estática)</small>
          </div>
          <div class="pr-es__cell pr-es__cell--static">
            <px-empty-state character title="No hay proveedores todavía" description="Los proveedores que registres aparecerán aquí.">
              <px-button variant="primary" icon="plus" size="sm">Crear proveedor</px-button>
            </px-empty-state>
            <small>Reposo (reduced-motion)</small>
          </div>
          <div class="pr-es__cell">
            <px-empty-state icon="inbox" title="Sin movimientos" description="Variante existente con glifo (sin cambios)." />
            <small>PxEmptyState actual (glifo), intacto</small>
          </div>
        </div>
      </div>

      <hr class="pxn-divider" />

      <div class="pr-fu">
        <span class="pr-selection__label">PxFileUpload · arrastrar y soltar</span>
        <div class="pr-fu__grid">
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.a" accept=".xlsx,.xls" :max-size="20 * 1024 * 1024" help="XLSX o XLS · máx. 20 MB" />
            <small>Vacío · arrastra o selecciona un .xlsx/.xls real (valida tipo y tamaño)</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.b" accept=".xlsx,.xls" />
            <small>Archivo seleccionado (precargado) · Reemplazar / Quitar</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.c" accept=".pdf,application/pdf" help="Solo PDF — prueba soltar otro tipo" />
            <small>Error por extensión: solo PDF</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.d" accept="image/*" :max-size="50 * 1024" help="Imágenes hasta 50 KB — prueba una mayor" />
            <small>Error por tamaño: 50 KB</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.e" multiple accept=".csv,.xlsx" help="Varios archivos permitidos" label="Arrastra tus archivos aquí" browse-text="Seleccionar archivos" />
            <small>Múltiple</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload :model-value="fu.f" disabled help="Deshabilitado" />
            <small>Deshabilitado</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload :model-value="fu.b" uploading accept=".xlsx" />
            <small>Subiendo · indeterminado (usa PxLoader; el padre no conoce el %)</small>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload :model-value="fu.b" uploading :progress="fu.progress" accept=".xlsx" />
            <label class="pr-fu__demo">DEMO visual del progreso (controlado a mano, no es lógica del componente)
              <input type="range" min="0" max="100" v-model.number="fu.progress" aria-label="Progreso demo" />
            </label>
          </div>
          <div class="pr-fu__cell">
            <px-file-upload v-model="fu.g" accept=".xlsx" error="El servidor rechazó el archivo (422): falta la columna «name»." />
            <small>Error del backend (lo entrega el padre)</small>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import SectionHead from "./_SectionHead.vue";
import { PxCard, PxPageHeader, PxBadge, PxButton, PxField, PxInput, PxSelect, PxTextarea, PxCheck, PxCheckbox, PxSearchInput, PxLoader, PxEmptyState, PxFileUpload } from "@/components/px-next";
export default {
  name: "PrimitivesSection",
  components: { SectionHead, PxCard, PxPageHeader, PxBadge, PxButton, PxField, PxInput, PxSelect, PxTextarea, PxCheck, PxCheckbox, PxSearchInput, PxLoader, PxEmptyState, PxFileUpload },
  props: { density: String, country: String },
  data() {
    return {
      cats: ["Farmacia", "Abarrotes", "Bebidas", "Ferretería", "Cuidado personal", "Limpieza"],
      sq: { a: "", b: "Camiseta azul talla M", c: "", d: "", long: "", tb: "", status: "", clears: 0 },
      sqStatus: [{ value: "", label: "Todos los estados" }, { value: "1", label: "Activos" }, { value: "0", label: "Inactivos" }],
      cb: { a: false, b: true, err: false, long: false, list: ["B"] },
      cbItems: ["A", "B", "C"],
      sw: { noLabel: false, pessimistic: false, pessimisticLoading: false },
      fu: { a: null, b: new File([new Uint8Array(1887437)], "inventario.xlsx"), c: null, d: null, e: [], f: null, g: null, progress: 45 },
      f: {
        name: "", sku: "ACE-500-100", price: "62.00", cat: "", rtn: "0801-1995-12345",
        notes: "", overselling: false, batch: true, costing: "promedio", online: true, price_login: false
      }
    };
  },
  methods: {
    toggleAll(v) { this.cb.list = v ? this.cbItems.slice() : []; },
    // Demo pesimista (documenta el patrón del piloto real): el knob NO se mueve hasta que "el backend" resuelve.
    onPessimisticDemo(next) {
      if (this.sw.pessimisticLoading) return; // guarda contra doble clic, igual que el piloto
      this.sw.pessimisticLoading = true;
      setTimeout(() => { this.sw.pessimistic = next; this.sw.pessimisticLoading = false; }, 700);
    }
  },
  computed: {
    cbAll() { return this.cb.list.length === this.cbItems.length; },
    cbSome() { return this.cb.list.length > 0 && !this.cbAll; },
    rtnError() {
      const digits = (this.f.rtn || "").replace(/\D/g, "");
      return digits.length && digits.length !== 14 ? "El RTN de Honduras debe tener 14 dígitos." : null;
    }
  }
};
</script>

<style lang="scss" scoped>
.pr-h3 { font-size: var(--pxn-fs-h3); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink); margin: var(--pxn-space-9) 0 var(--pxn-space-5); }
.pr-h3:first-of-type { margin-top: 0; }
.pr-inset { padding: var(--pxn-space-7); }
.pr-panel { display: flex; flex-direction: column; gap: var(--pxn-space-6); padding: var(--pxn-space-7); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pr-btnrow { display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-4); }
.pr-btnrow--sizes { align-items: flex-end; }
.pr-formgrid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-6); }
@media (max-width: 900px) { .pr-formgrid { grid-template-columns: minmax(0, 1fr); } }
.pr-selection { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-7); }
@media (max-width: 900px) { .pr-selection { grid-template-columns: minmax(0, 1fr); } }
.pr-sq__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-7); margin: var(--pxn-space-4) 0; }
@media (max-width: 900px) { .pr-sq__grid { grid-template-columns: minmax(0, 1fr); } }
.pr-sq__cell { display: flex; flex-direction: column; gap: var(--pxn-space-3); min-width: 0; }
.pr-sq__cell--narrow > .pxn-search { max-width: 190px; }
.pr-sq__cell--wide { grid-column: 1 / -1; }
.pr-sq__cap { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pr-sq__samples { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); }
.pr-sq__toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: var(--pxn-space-4); margin-top: var(--pxn-space-3); }
.pr-sq__toolbar-search { flex: 1 1 260px; max-width: 360px; min-width: 0; }
.pr-cb__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-7); margin-top: var(--pxn-space-4); }
@media (max-width: 900px) { .pr-cb__grid { grid-template-columns: minmax(0, 1fr); } }
.pr-cb__child { margin-left: var(--pxn-space-7); }
.pr-cb__out { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pr-ld__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: var(--pxn-space-6); margin-top: var(--pxn-space-4); }
.pr-ld__cell { display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-4); padding: var(--pxn-space-6) var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pr-ld__cell small { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pr-ld__cell--static ::v-deep .pxn-loader__layer { animation: none; }
.pr-es__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--pxn-space-6); margin-top: var(--pxn-space-4); }
.pr-es__cell { display: flex; flex-direction: column; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); background: var(--pxn-surface); }
.pr-es__cell small { padding: 0 var(--pxn-space-4) var(--pxn-space-4); text-align: center; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pr-es__cell--static ::v-deep .pxn-ec__body, .pr-es__cell--static ::v-deep .pxn-ec__pupil, .pr-es__cell--static ::v-deep .pxn-ec__shadow, .pr-es__cell--static ::v-deep .pxn-ec__cell { animation: none !important; }
.pr-es__cell--static ::v-deep .pxn-ec__cell.is-f1 { background-color: transparent; }
.pr-fu__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--pxn-space-6); margin-top: var(--pxn-space-4); }
.pr-fu__cell { display: flex; flex-direction: column; gap: var(--pxn-space-3); min-width: 0; }
.pr-fu__cell small, .pr-fu__demo { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pr-fu__demo { display: flex; flex-direction: column; gap: var(--pxn-space-2); }
.pr-selection__group { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
.pr-selection__label { font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.05em; color: var(--pxn-ink-3); }
</style>
