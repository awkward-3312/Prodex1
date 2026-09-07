<template>
  <div class="px-next pxcfg">
    <px-page-header
      title="Facturación SAR"
      :breadcrumbs="[{ label: $t('Settings'), href: '#/app/settings/System_settings' }, { label: 'Facturación SAR' }]"
    />

    <div v-if="loading" class="pxcfg__pad">
      <px-skeleton variant="lines" :rows="10" />
    </div>

    <template v-else>
      <px-alert tone="info" class="pxcfg__alert">
        Registra tu autorización SAR una sola vez —CAI, establecimiento, punto de emisión, rango y fecha límite— y PRODEX numera las facturas automáticamente. El correlativo pertenece a la serie fiscal, no a la caja: todas las cajas de una serie consumen el mismo contador. Los cambios se aplican a facturas futuras; las facturas ya emitidas conservan una copia congelada de su información fiscal.
      </px-alert>

      <px-card title="Perfil fiscal" class="pxcfg__card">
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="RTN *"><template #default="{ id }"><px-input :id="id" :value="profile.rtn" @input="v => profile.rtn = tv(v)" /></template></px-field>
          <px-field label="Razón social *"><template #default="{ id }"><px-input :id="id" :value="profile.legal_name" @input="v => profile.legal_name = tv(v)" /></template></px-field>
          <px-field label="Nombre comercial"><template #default="{ id }"><px-input :id="id" :value="profile.trade_name" @input="v => profile.trade_name = tv(v)" /></template></px-field>
          <px-field label="Teléfono"><template #default="{ id }"><px-input :id="id" :value="profile.phone" @input="v => profile.phone = tv(v)" /></template></px-field>
          <px-field label="Correo"><template #default="{ id }"><px-input :id="id" type="email" :value="profile.email" @input="v => profile.email = tv(v)" /></template></px-field>
        </div>
        <px-field label="Dirección de casa matriz *" class="pxcfg__mt">
          <template #default="{ id }"><px-textarea :id="id" :rows="2" :value="profile.head_office_address" @input="v => profile.head_office_address = tv(v)" /></template>
        </px-field>
        <px-check type="switch" :modelValue="!!profile.enabled" @change="v => profile.enabled = v" class="pxcfg__mt">
          {{ profile.enabled ? "Facturación fiscal habilitada" : "Facturación fiscal deshabilitada" }}
        </px-check>
        <p class="pxcfg__cardnote pxcfg__mt">Al habilitarla, cada sucursal activa aparece abajo con su serie fiscal (inicialmente sin facturar). PRODEX gestiona la numeración; tú solo registras la autorización.</p>
      </px-card>

      <px-card title="Contenido y presentación de la factura" class="pxcfg__card">
        <p class="pxcfg__cardnote">Estos datos son administrables por el tenant y se congelan en cada factura al momento de emitirla.</p>
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="Título del documento"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.document_title" @input="v => profile.invoice_settings.document_title = tv(v)" placeholder="FACTURA" /></template></px-field>
          <px-field label="Tipo de venta"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.sale_type_label" @input="v => profile.invoice_settings.sale_type_label = tv(v)" placeholder="CONTADO" /></template></px-field>
          <px-field label="Sitio web"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.website" @input="v => profile.invoice_settings.website = tv(v)" placeholder="https://..." /></template></px-field>
        </div>
        <div class="pxcfg__grid pxcfg__mt">
          <px-field label="Texto de original"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.original_label" @input="v => profile.invoice_settings.original_label = tv(v)" /></template></px-field>
          <px-field label="Texto de copia"><template #default="{ id }"><px-input :id="id" :value="profile.invoice_settings.copy_label" @input="v => profile.invoice_settings.copy_label = tv(v)" /></template></px-field>
        </div>
        <px-field label="Mensaje al pie" class="pxcfg__mt">
          <template #default="{ id }"><px-textarea :id="id" :rows="2" :value="profile.invoice_settings.footer_message" @input="v => profile.invoice_settings.footer_message = tv(v)" /></template>
        </px-field>
        <div class="pxcfg__togglegrid">
          <px-check v-for="toggle in invoiceToggles" :key="toggle.key" type="switch"
            :modelValue="!!profile.invoice_settings[toggle.key]" @change="v => $set(profile.invoice_settings, toggle.key, v)">
            {{ toggle.label }}
          </px-check>
        </div>
        <template #footer>
          <px-button variant="primary" :disabled="saving" @click="saveProfile">Guardar configuración fiscal y factura</px-button>
        </template>
      </px-card>

      <!-- Fiscal series -->
      <template v-if="profile.enabled">
        <div class="pxcfg__summary">
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum pxcfg__summnum--ok">{{ readyCount }}</span>
            <span class="pxcfg__summlabel">{{ readyCount === 1 ? 'serie facturando' : 'series facturando' }}</span>
          </div>
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum pxcfg__summnum--warn">{{ attentionCount }}</span>
            <span class="pxcfg__summlabel">{{ attentionCount === 1 ? 'serie por revisar' : 'series por revisar' }}</span>
          </div>
          <div class="pxcfg__summitem">
            <span class="pxcfg__summnum">{{ disabledCount }}</span>
            <span class="pxcfg__summlabel">{{ disabledCount === 1 ? 'sucursal sin habilitar' : 'sucursales sin habilitar' }}</span>
          </div>
          <div class="pxcfg__summitem" v-if="totalRemaining !== null">
            <span class="pxcfg__summnum">{{ nfmt(totalRemaining) }}</span>
            <span class="pxcfg__summlabel">correlativos disponibles</span>
          </div>
        </div>

        <px-alert v-if="fiscalGaps.length" tone="warning" class="pxcfg__alert">
          <strong>{{ fiscalGaps.length }}</strong>
          {{ fiscalGaps.length === 1 ? 'caja física sin serie fiscal lista:' : 'cajas físicas sin serie fiscal lista:' }}
          <ul class="pxcfg__gaplist">
            <li v-for="gap in fiscalGaps" :key="gap.cash_drawer_id">
              <span class="pxcfg__gapwhere">{{ gap.branch_name || '—' }} · {{ gap.inventory_location_name || 'sin ubicación' }} · {{ gap.cash_drawer_name }}<span v-if="gap.cash_drawer_code" class="pxcfg__gapcode"> ({{ gap.cash_drawer_code }})</span></span>
              <span class="pxcfg__gapreason">{{ gap.reason === 'sin_punto_sar' ? 'sin serie fiscal' : 'sin CAI vigente' }}</span>
            </li>
          </ul>
        </px-alert>

        <px-card v-for="card in branchCards" :key="card.branch_id" class="pxcfg__card pxcfg__branch">
          <template #header>
            <div class="pxcfg__cardhead">
              <div class="pxcfg__serieident">
                <span class="pxcfg__serielabel pxn-num">{{ card.serie_label || 'Serie sin definir' }}</span>
                <small class="pxcfg__cardnote">{{ card.branch_name }}<span v-if="card.branch_code"> · {{ card.branch_code }}</span></small>
              </div>
              <px-badge :tone="healthTone(card)">{{ healthLabel(card) }}</px-badge>
            </div>
          </template>

          <div class="pxcfg__branchrow">
            <px-check type="switch" :modelValue="card.sar_enabled" :disabled="saving"
              @change="v => toggleBranch(card, v)">
              {{ card.sar_enabled ? 'Facturación SAR habilitada' : 'Facturación SAR deshabilitada' }}
            </px-check>
          </div>

          <template v-if="card.sar_enabled">
            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Serie autorizada por el SAR</span>
                <small class="pxcfg__cardnote">Establecimiento y punto de emisión de tu autorización oficial. El tipo de documento es 01 (factura).</small>
              </div>
              <div class="pxcfg__grid pxcfg__branchcodes">
                <px-field label="Código de establecimiento *" hint="3 dígitos.">
                  <template #default="{ id }"><px-input :id="id" maxlength="3" :value="draftFor(card).establishment_code" @input="v => setDraft(card, 'establishment_code', tv(v))" placeholder="000" /></template>
                </px-field>
                <px-field label="Código del punto de emisión *" hint="3 dígitos.">
                  <template #default="{ id }"><px-input :id="id" maxlength="3" :value="draftFor(card).point_code" @input="v => setDraft(card, 'point_code', tv(v))" placeholder="001" /></template>
                </px-field>
              </div>
              <div class="pxcfg__branchactions">
                <px-button size="sm" variant="secondary" :disabled="saving || !codesDirty(card) || !codesComplete(card)" @click="saveBranchCodes(card)">Guardar serie</px-button>
              </div>
            </div>

            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Cajas cubiertas por la serie</span>
                <small class="pxcfg__cardnote">Todas comparten el mismo CAI y el mismo contador. Ninguna caja elige rango. Las cajas nuevas aparecen aquí automáticamente.</small>
              </div>
              <div v-if="card.available_drawers.length" class="pxcfg__drawers">
                <px-check v-for="d in card.available_drawers" :key="d.id"
                  :modelValue="drawerChecked(card, d.id)" :disabled="saving"
                  @change="v => toggleDrawer(card, d.id, v)">
                  {{ d.name }}<span v-if="d.code" class="pxcfg__gapcode"> ({{ d.code }})</span>
                </px-check>
              </div>
              <p v-else class="pxcfg__cardnote">Esta sucursal todavía no tiene cajas físicas activas.</p>
              <div class="pxcfg__branchactions" v-if="card.available_drawers.length">
                <px-button size="sm" variant="secondary" :disabled="saving || !drawersDirty(card)" @click="saveBranchDrawers(card)">Guardar cajas</px-button>
              </div>
            </div>

            <div class="pxcfg__branchsection">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Autorización activa</span>
              </div>

              <div v-if="card.series && card.series.current" class="pxcfg__cai">
                <div class="pxcfg__caigrid">
                  <div><span class="pxcfg__cailbl">CAI</span><span class="pxcfg__caival pxn-num">{{ card.series.current.cai }}</span></div>
                  <div><span class="pxcfg__cailbl">Rango autorizado</span><span class="pxcfg__caival pxn-num">{{ pad8(card.series.current.range_start) }} – {{ pad8(card.series.current.range_end) }}</span></div>
                  <div><span class="pxcfg__cailbl">Último utilizado</span><span class="pxcfg__caival pxn-num">{{ card.series.current.last_used ? pad8(card.series.current.last_used) : '—' }}</span></div>
                  <div><span class="pxcfg__cailbl">Siguiente correlativo</span><span class="pxcfg__caival pxn-num">{{ pad8(card.series.current.next_number) }}</span></div>
                  <div><span class="pxcfg__cailbl">Disponibles</span><span class="pxcfg__caival pxn-num">{{ nfmt(card.series.current.remaining) }}</span></div>
                  <div><span class="pxcfg__cailbl">Fecha límite</span><span class="pxcfg__caival">{{ card.series.current.deadline || '—' }}</span></div>
                  <div>
                    <span class="pxcfg__cailbl">Estado</span>
                    <px-badge :tone="card.series.current.is_ready ? 'success' : healthTone(card)">{{ healthLabel(card) }}</px-badge>
                  </div>
                </div>
                <div class="pxcfg__meter" role="presentation">
                  <span class="pxcfg__meterfill" :class="'pxcfg__meterfill--' + (card.series.current.health || 'ready')"
                    :style="{ width: consumedPct(card.series.current) + '%' }"></span>
                </div>
                <div class="pxcfg__branchactions">
                  <px-button size="sm" variant="ghost" :disabled="saving" @click="openAuthorization(card, 'current')">Reemplazar CAI actual</px-button>
                  <px-button v-if="card.series.current.status === 'draft' || card.series.current.status === 'disabled'" size="sm" variant="primary" :disabled="saving" @click="activate(card.series.current.id)">Activar</px-button>
                </div>
              </div>
              <div v-else class="pxcfg__branchactions">
                <px-button size="sm" variant="primary" :disabled="saving || !card.has_codes" @click="openAuthorization(card, 'current')">Registrar autorización</px-button>
                <small v-if="!card.has_codes" class="pxcfg__cardnote">Guarda primero el establecimiento y el punto de emisión.</small>
              </div>
            </div>

            <div class="pxcfg__branchsection" v-if="card.series && card.series.current">
              <div class="pxcfg__branchsectionhead">
                <span class="pxcfg__branchsectiontitle">Siguiente autorización</span>
                <small class="pxcfg__cardnote">PRODEX cambia a esta autorización de forma automática y segura cuando la actual se agote —sin interrumpir las ventas.</small>
              </div>

              <div v-if="card.series.next" class="pxcfg__cai">
                <div class="pxcfg__caigrid">
                  <div><span class="pxcfg__cailbl">CAI</span><span class="pxcfg__caival pxn-num">{{ card.series.next.cai }}</span></div>
                  <div><span class="pxcfg__cailbl">Rango</span><span class="pxcfg__caival pxn-num">{{ pad8(card.series.next.range_start) }} – {{ pad8(card.series.next.range_end) }}</span></div>
                  <div><span class="pxcfg__cailbl">Fecha límite</span><span class="pxcfg__caival">{{ card.series.next.deadline || '—' }}</span></div>
                  <div>
                    <span class="pxcfg__cailbl">Estado</span>
                    <px-badge :tone="card.series.next.status === 'prepared' ? 'info' : 'neutral'">
                      {{ card.series.next.status === 'prepared' ? 'Preparada' : 'Borrador' }}
                    </px-badge>
                  </div>
                </div>
                <div class="pxcfg__branchactions">
                  <px-button v-if="card.series.next.status === 'draft'" size="sm" variant="primary" :disabled="saving" @click="activate(card.series.next.id)">Marcar como preparada</px-button>
                  <px-button size="sm" variant="ghost" :disabled="saving" @click="removeAuthorization(card.series.next.id)">Quitar</px-button>
                </div>
              </div>
              <div v-else class="pxcfg__branchactions">
                <px-button size="sm" variant="secondary" :disabled="saving" @click="openAuthorization(card, 'next')">Preparar siguiente autorización</px-button>
              </div>
            </div>

            <px-alert v-if="card.errors.length" tone="warning" class="pxcfg__branchalert">
              <ul class="pxcfg__errlist">
                <li v-for="(err, i) in card.errors" :key="i">{{ err }}</li>
              </ul>
            </px-alert>
          </template>
        </px-card>

        <px-card v-if="!branchCards.length" class="pxcfg__card">
          <p class="pxcfg__cardnote pxcfg__tc">No hay sucursales activas. Crea una sucursal para configurar su serie fiscal.</p>
        </px-card>
      </template>

      <px-alert v-else tone="neutral" class="pxcfg__alert">
        Habilita la facturación fiscal en el perfil de arriba para configurar tus series fiscales.
      </px-alert>

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div>
              <h3 class="pxcfg__cardtitle">Clasificación fiscal de productos</h3>
              <small class="pxcfg__cardnote">Define si cada producto es gravado, exento, exonerado o tasa cero y su ISV. Esta clasificación alimenta POS, A4, térmica y reimpresiones.</small>
            </div>
            <px-input class="pxcfg__inlinesearch" :value="productSearch" @input="v => productSearch = tv(v)" placeholder="Buscar producto..." icon-lead="search" />
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Código</th><th>Producto</th><th>Clasificación</th><th>ISV</th><th>Precio</th><th></th></tr></thead>
            <tbody>
              <tr v-for="product in filteredProducts" :key="product.id">
                <td>{{ product.code }}</td>
                <td>{{ product.name }}</td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.fiscal_tax_category" :reduce="o => o.value" :options="taxCategories" :clearable="false" /></td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.TaxNet" :reduce="o => o.value" :options="taxRateOptions(product)" :clearable="false" :disabled="product.fiscal_tax_category !== 'taxed'" /></td>
                <td class="pxcfg__cellsel"><vs-px v-model="product.tax_method" :reduce="o => o.value" :options="taxMethodOptions" :clearable="false" /></td>
                <td class="pxcfg__tr"><px-button size="sm" variant="secondary" :disabled="saving" @click="saveProductFiscal(product)">Guardar</px-button></td>
              </tr>
              <tr v-if="!filteredProducts.length"><td colspan="6" class="pxcfg__tc pxcfg__cardnote">No hay productos que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </px-card>

      <px-card class="pxcfg__card">
        <template #header>
          <div class="pxcfg__cardhead">
            <div>
              <h3 class="pxcfg__cardtitle">Datos fiscales de clientes</h3>
              <small class="pxcfg__cardnote">RTN, documento de identificación y registros de exoneración que podrán copiarse a la factura cuando correspondan.</small>
            </div>
            <px-input class="pxcfg__inlinesearch" :value="clientSearch" @input="v => clientSearch = tv(v)" placeholder="Buscar cliente..." icon-lead="search" />
          </div>
        </template>
        <div class="pxcfg__scrollbox">
          <table class="pxcfg__table">
            <thead><tr><th>Cliente</th><th>RTN</th><th>Identificación</th><th>Registro SAR/SAG</th><th>Registro exonerado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="client in filteredClients" :key="client.id">
                <td>{{ client.name }}</td>
                <td>{{ client.tax_number || '-' }}</td>
                <td>{{ client.identification_number || '-' }}</td>
                <td>{{ client.sar_registry_number || '-' }}</td>
                <td>{{ client.exoneration_registry_number || '-' }}</td>
                <td class="pxcfg__tr"><px-button size="sm" variant="secondary" @click="openClient(client)">Editar</px-button></td>
              </tr>
              <tr v-if="!filteredClients.length"><td colspan="6" class="pxcfg__tc pxcfg__cardnote">No hay clientes que coincidan.</td></tr>
            </tbody>
          </table>
        </div>
      </px-card>
    </template>

    <px-modal v-model="clientModalOpen" title="Datos fiscales del cliente" size="md">
      <div class="pxcfg__formgrid">
        <px-field label="Cliente"><template #default="{ id }"><px-input :id="id" :value="clientForm.name" disabled /></template></px-field>
        <px-field label="RTN"><template #default="{ id }"><px-input :id="id" :value="clientForm.tax_number" @input="v => clientForm.tax_number = tv(v)" /></template></px-field>
        <div class="pxcfg__grid">
          <px-field label="Tipo de identificación"><template #default="{ id }"><px-input :id="id" :value="clientForm.identification_type" @input="v => clientForm.identification_type = tv(v)" placeholder="DNI / Pasaporte" /></template></px-field>
          <px-field label="Número de identificación"><template #default="{ id }"><px-input :id="id" :value="clientForm.identification_number" @input="v => clientForm.identification_number = tv(v)" /></template></px-field>
        </div>
        <px-field label="No. Registro SAG/SAR"><template #default="{ id }"><px-input :id="id" :value="clientForm.sar_registry_number" @input="v => clientForm.sar_registry_number = tv(v)" /></template></px-field>
        <px-field label="No. Registro exonerado"><template #default="{ id }"><px-input :id="id" :value="clientForm.exoneration_registry_number" @input="v => clientForm.exoneration_registry_number = tv(v)" /></template></px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" :disabled="saving" @click="saveClientFiscal">Guardar</px-button>
      </template>
    </px-modal>

    <px-modal v-model="authModalOpen" :title="authModalTitle" size="md">
      <p class="pxcfg__cardnote pxcfg__mb">
        <template v-if="authForm.role === 'next'">Registra la próxima autorización SAR de la serie {{ authForm.serie_label }} ({{ authForm.branch_name }}). Quedará <strong>preparada</strong> y PRODEX cambiará a ella automáticamente cuando la actual se agote.</template>
        <template v-else>Registra el CAI exactamente como aparece en tu autorización del SAR para {{ authForm.branch_name }}. Se guarda como borrador; actívalo para empezar a facturar con él.</template>
      </p>
      <div class="pxcfg__formgrid">
        <px-field label="CAI *"><template #default="{ id }"><px-input :id="id" :value="authForm.cai" @input="v => authForm.cai = tv(v)" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX" /></template></px-field>
        <div class="pxcfg__grid pxcfg__grid--3">
          <px-field label="Tipo de documento *" hint="01 = factura"><template #default="{ id }"><px-input :id="id" maxlength="2" :value="authForm.document_type" @input="v => authForm.document_type = tv(v)" /></template></px-field>
          <px-field label="Rango: desde *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_start" @input="v => authForm.range_start = vnum(v)" /></template></px-field>
          <px-field label="Rango: hasta *"><template #default="{ id }"><px-input :id="id" type="number" :value="authForm.range_end" @input="v => authForm.range_end = vnum(v)" /></template></px-field>
        </div>
        <div class="pxcfg__grid">
          <px-field v-if="authForm.role !== 'next'" label="Siguiente correlativo *" hint="El próximo número a emitir con este CAI.">
            <template #default="{ id }"><px-input :id="id" type="number" :value="authForm.next_number" @input="v => authForm.next_number = vnum(v)" /></template>
          </px-field>
          <px-field label="Fecha límite de emisión *"><template #default="{ id }"><px-input :id="id" type="date" v-model="authForm.deadline" /></template></px-field>
        </div>
        <px-field label="Fecha de autorización"><template #default="{ id }"><px-input :id="id" type="date" v-model="authForm.authorization_date" /></template></px-field>
      </div>
      <template #footer="{ close }">
        <px-button variant="ghost" @click="close">Cancelar</px-button>
        <px-button variant="primary" :disabled="saving" @click="saveAuthorization">{{ authForm.role === 'next' ? 'Guardar como preparada' : 'Guardar como borrador' }}</px-button>
      </template>
    </px-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VsPx from "@/views/app/products/next/edit/VsPx.vue";

const invoiceDefaults = () => ({
  document_title: "FACTURA", sale_type_label: "CONTADO", website: "", footer_message: "Gracias por su compra.",
  original_label: "Original: Cliente", copy_label: "Copia: Obligado Tributario Emisor",
  show_logo: true, show_internal_reference: true, show_cashier: true, show_warehouse: true,
  show_payment_summary: true, show_customer_address: true, show_item_code: true, show_total_in_words: true, show_qr: true
});

export default {
  metaInfo: { title: "Facturación SAR" },
  components: { PxPageHeader, PxButton, PxCard, PxField, PxInput, PxTextarea, PxCheck, PxBadge, PxAlert, PxModal, "vs-px": VsPx },
  data() {
    return {
      loading: true, saving: false,
      branchCards: [], fiscalGaps: [], products: [], clients: [],
      productSearch: "", clientSearch: "", taxCategories: [], taxRates: [0, 15, 18],
      clientModalOpen: false, authModalOpen: false,
      profile: { enabled: false, rtn: "", legal_name: "", trade_name: "", head_office_address: "", phone: "", email: "", invoice_settings: invoiceDefaults() },
      drafts: {},
      authForm: {}, clientForm: {},
      taxMethodOptions: [{ label: "Exclusivo", value: "1" }, { label: "Incluido en precio", value: "2" }],
      invoiceToggles: [
        { key: "show_logo", label: "Mostrar logo" }, { key: "show_internal_reference", label: "Mostrar referencia interna" },
        { key: "show_cashier", label: "Mostrar cajero" }, { key: "show_warehouse", label: "Mostrar almacén" },
        { key: "show_payment_summary", label: "Mostrar resumen de pago" }, { key: "show_customer_address", label: "Mostrar dirección cliente" },
        { key: "show_item_code", label: "Mostrar código de producto" }, { key: "show_total_in_words", label: "Mostrar total en letras" },
        { key: "show_qr", label: "Mostrar QR" }
      ]
    };
  },
  computed: {
    readyCount() { return this.branchCards.filter(c => c.status === "ready").length; },
    attentionCount() { return this.branchCards.filter(c => c.status === "pending").length; },
    disabledCount() { return this.branchCards.filter(c => c.status === "disabled").length; },
    totalRemaining() {
      const live = this.branchCards.filter(c => c.series && c.series.current && c.series.current.is_ready);
      if (!live.length) return null;
      return live.reduce((sum, c) => sum + Number(c.series.current.remaining || 0), 0);
    },
    authModalTitle() {
      const s = this.authForm.serie_label ? "Serie " + this.authForm.serie_label : (this.authForm.branch_name || "");
      return (this.authForm.role === "next" ? "Siguiente autorización · " : "Autorización SAR · ") + s;
    },
    filteredProducts() { const q = this.productSearch.toLowerCase(); return this.products.filter(x => !q || String(x.name || "").toLowerCase().includes(q) || String(x.code || "").toLowerCase().includes(q)); },
    filteredClients() { const q = this.clientSearch.toLowerCase(); return this.clients.filter(x => !q || String(x.name || "").toLowerCase().includes(q) || String(x.tax_number || "").toLowerCase().includes(q)); }
  },
  methods: {
    tv(v) { return typeof v === "string" ? v.trim() : v; },
    vnum(v) { if (v === "" || v === null || typeof v === "undefined") return v; const n = parseFloat(v); return Number.isNaN(n) ? v : n; },
    toast(variant, message) { this.$root.$bvToast.toast(message, { title: variant === "success" ? "Éxito" : "Atención", variant, solid: true }); },
    errorMessage(error) { const data = error.response && error.response.data; if (data && data.errors) { const key = Object.keys(data.errors)[0]; return data.errors[key][0]; } return (data && data.message) || "No se pudo completar la operación."; },
    normalizeProduct(p) { const category = p.fiscal_tax_category || (Number(p.TaxNet) > 0 ? "taxed" : "exempt"); return Object.assign({}, p, { fiscal_tax_category: category, TaxNet: Number(p.TaxNet || 0), tax_method: String(p.tax_method || "1") }); },
    taxRateOptions(product) { return (product.fiscal_tax_category === "taxed" ? this.taxRates.filter(x => Number(x) > 0) : [0]).map(x => ({ label: x + "%", value: Number(x) })); },
    dateOnly(v) { return v ? String(v).slice(0, 10) : "-"; },
    todayStr() { return new Date().toISOString().slice(0, 10); },
    nfmt(n) { const v = Number(n || 0); return v.toLocaleString("es-HN"); },
    pad8(n) { const v = Math.max(0, parseInt(n, 10) || 0); return String(v).padStart(8, "0"); },
    consumedPct(auth) {
      if (!auth || !auth.total_range) return 0;
      const used = auth.total_range - Number(auth.remaining || 0);
      return Math.max(0, Math.min(100, Math.round((used / auth.total_range) * 100)));
    },
    healthLabel(card) {
      if (!card.sar_enabled) return "SAR deshabilitada";
      const h = card.series && card.series.health;
      return ({
        ready: "Lista para facturar",
        next_ready: "Lista · usa el siguiente CAI",
        expiring: "Por vencer",
        running_low: "Por agotarse",
        exhausted: "Agotada",
        expired: "Vencida",
        no_authorization: "Sin autorización",
        unconfigured: "Sin configurar"
      })[h] || (card.status === "ready" ? "Lista para facturar" : "Configuración pendiente");
    },
    healthTone(card) {
      if (!card.sar_enabled) return "neutral";
      const h = card.series && card.series.health;
      return ({
        ready: "success", next_ready: "success",
        expiring: "warning", running_low: "warning",
        exhausted: "danger", expired: "danger", no_authorization: "danger",
        unconfigured: "neutral"
      })[h] || (card.status === "ready" ? "success" : "warning");
    },

    // --- per-branch draft state (codes + drawer selection) ------------------
    draftFor(card) { return this.drafts[card.branch_id] || { establishment_code: "", point_code: "", drawer_ids: [] }; },
    setDraft(card, key, value) {
      const d = this.drafts[card.branch_id] || { establishment_code: "", point_code: "", drawer_ids: [] };
      this.$set(this.drafts, card.branch_id, Object.assign({}, d, { [key]: value }));
    },
    seedDrafts() {
      const next = {};
      this.branchCards.forEach(c => {
        next[c.branch_id] = {
          establishment_code: c.establishment_code || "",
          point_code: c.point_code || "",
          drawer_ids: (c.covered_drawer_ids || []).slice()
        };
      });
      this.drafts = next;
    },
    codesComplete(card) {
      const d = this.draftFor(card);
      return /^\d{3}$/.test(d.establishment_code || "") && /^\d{3}$/.test(d.point_code || "");
    },
    codesDirty(card) {
      const d = this.draftFor(card);
      return (d.establishment_code || "") !== (card.establishment_code || "")
        || (d.point_code || "") !== (card.point_code || "");
    },
    drawerChecked(card, drawerId) { return this.draftFor(card).drawer_ids.indexOf(drawerId) !== -1; },
    toggleDrawer(card, drawerId, checked) {
      const d = this.draftFor(card);
      const ids = d.drawer_ids.slice();
      const i = ids.indexOf(drawerId);
      if (checked && i === -1) ids.push(drawerId);
      if (!checked && i !== -1) ids.splice(i, 1);
      this.$set(this.drafts, card.branch_id, Object.assign({}, d, { drawer_ids: ids }));
    },
    drawersDirty(card) {
      const a = this.draftFor(card).drawer_ids.slice().sort();
      const b = (card.covered_drawer_ids || []).slice().sort();
      return a.length !== b.length || a.some((x, i) => x !== b[i]);
    },

    async load() {
      this.loading = true; NProgress.start();
      try {
        const r = await axios.get("sar-fiscal/settings");
        const incoming = r.data.profile || {};
        this.profile = Object.assign({}, this.profile, incoming, { invoice_settings: Object.assign(invoiceDefaults(), incoming.invoice_settings || {}) });
        this.branchCards = r.data.branch_cards || [];
        this.fiscalGaps = r.data.fiscal_gaps || [];
        this.products = (r.data.products || []).map(this.normalizeProduct);
        this.clients = (r.data.clients || []).map(x => Object.assign({}, x));
        this.taxCategories = r.data.tax_categories || [];
        this.taxRates = r.data.tax_rates || [0, 15, 18];
        this.seedDrafts();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.loading = false; NProgress.done(); }
    },
    applyCards(data) {
      if (data && data.branch_cards) {
        this.branchCards = data.branch_cards;
        this.seedDrafts();
      }
    },
    async saveProfile() {
      this.saving = true;
      try { const r = await axios.put("sar-fiscal/profile", this.profile); this.toast("success", "Configuración fiscal guardada."); this.applyCards(r.data); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async toggleBranch(card, enabled) {
      this.saving = true;
      try {
        const r = await axios.post("sar-fiscal/branches/" + card.branch_id + "/toggle", { enabled: !!enabled });
        this.toast("success", enabled ? "Facturación SAR habilitada para " + card.branch_name + "." : "Facturación SAR deshabilitada para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); await this.load(); } finally { this.saving = false; }
    },
    async saveBranchCodes(card) {
      const d = this.draftFor(card);
      this.saving = true;
      try {
        const r = await axios.put("sar-fiscal/branches/" + card.branch_id + "/point", { establishment_code: d.establishment_code, point_code: d.point_code });
        this.toast("success", "Códigos SAR guardados para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async saveBranchDrawers(card) {
      this.saving = true;
      try {
        const r = await axios.put("sar-fiscal/branches/" + card.branch_id + "/drawers", { cash_drawer_ids: this.draftFor(card).drawer_ids });
        this.toast("success", "Cajas cubiertas actualizadas para " + card.branch_name + ".");
        this.applyCards(r.data);
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    openAuthorization(card, role) {
      const nextStart = role === "next" && card.series && card.series.current
        ? Number(card.series.current.range_end) + 1
        : 1;
      this.authForm = {
        role: role || "current",
        branch_id: card.branch_id, branch_name: card.branch_name,
        serie_label: card.serie_label,
        document_type: "01", cai: "",
        range_start: nextStart, range_end: null, next_number: nextStart,
        authorization_date: "", deadline: ""
      };
      this.authModalOpen = true;
    },
    async saveAuthorization() {
      this.saving = true;
      try {
        await axios.post("sar-fiscal/authorizations", {
          role: this.authForm.role,
          branch_id: this.authForm.branch_id,
          document_type: this.authForm.document_type,
          cai: this.authForm.cai,
          range_start: this.authForm.range_start,
          range_end: this.authForm.range_end,
          next_number: this.authForm.role === "next" ? this.authForm.range_start : this.authForm.next_number,
          authorization_date: this.authForm.authorization_date || null,
          deadline: this.authForm.deadline
        });
        this.authModalOpen = false;
        this.toast("success", this.authForm.role === "next"
          ? "Autorización siguiente preparada. PRODEX cambiará a ella al agotarse la actual."
          : "Autorización guardada como borrador. Actívala para empezar a facturar.");
        await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    async activate(authId) {
      const result = await this.$swal({ title: "¿Activar esta autorización?", text: "Las futuras facturas fiscales de esta serie usarán este CAI y rango.", type: "warning", showCancelButton: true, confirmButtonText: "Activar", cancelButtonText: "Cancelar" });
      if (!result.value) return;
      try { await axios.post("sar-fiscal/authorizations/" + authId + "/activate"); this.toast("success", "Autorización activada."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); }
    },
    async removeAuthorization(authId) {
      const result = await this.$swal({ title: "¿Quitar esta autorización?", text: "Solo se puede quitar si aún no ha emitido documentos fiscales.", type: "warning", showCancelButton: true, confirmButtonText: "Quitar", cancelButtonText: "Cancelar" });
      if (!result.value) return;
      try { await axios.delete("sar-fiscal/authorizations/" + authId); this.toast("success", "Autorización eliminada."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); }
    },
    async saveProductFiscal(product) {
      this.saving = true;
      try {
        await axios.put("sar-fiscal/profile", { action: "product_fiscal", product_id: product.id, fiscal_tax_category: product.fiscal_tax_category, TaxNet: product.fiscal_tax_category === "taxed" ? Number(product.TaxNet) : 0, tax_method: String(product.tax_method || "1") });
        this.toast("success", "Clasificación fiscal del producto guardada."); await this.load();
      } catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    },
    openClient(client) { this.clientForm = Object.assign({}, client); this.clientModalOpen = true; },
    async saveClientFiscal() {
      this.saving = true;
      try { await axios.put("sar-fiscal/profile", Object.assign({ action: "client_fiscal", client_id: this.clientForm.id }, this.clientForm)); this.clientModalOpen = false; this.toast("success", "Datos fiscales del cliente guardados."); await this.load(); }
      catch (e) { this.toast("danger", this.errorMessage(e)); } finally { this.saving = false; }
    }
  },
  created() { this.load(); }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxcfg { min-height: 100%; background: var(--pxn-bg); padding: var(--pxn-space-8) var(--pxn-space-9) var(--pxn-space-9); }
@media (max-width: 620px) { .pxcfg { padding: var(--pxn-space-6) var(--pxn-space-5); } }
.pxcfg__pad { padding: var(--pxn-space-6) 0; }
.pxcfg__card { margin-top: var(--pxn-space-5); }
.pxcfg__alert { margin-top: var(--pxn-space-4); }
.pxcfg__cardnote { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxcfg__mb { margin: 0 0 var(--pxn-space-4); }
.pxcfg__mt { margin-top: var(--pxn-space-4); }
.pxcfg__cardhead { display: flex; flex-wrap: wrap; gap: var(--pxn-space-4); align-items: flex-start; justify-content: space-between; }
.pxcfg__cardtitle { margin: 0 0 var(--pxn-space-1); font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); }
.pxcfg__inlinesearch { max-width: 280px; }
.pxcfg__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-5); }
.pxcfg__grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 900px) { .pxcfg__grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .pxcfg__grid, .pxcfg__grid--3 { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__togglegrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--pxn-space-3); margin-top: var(--pxn-space-4); }
@media (max-width: 900px) { .pxcfg__togglegrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__togglegrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__formgrid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--pxn-space-4); }
.pxcfg__scrollbox { max-height: 420px; overflow: auto; border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxcfg__table { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-sm); }
.pxcfg__table th { position: sticky; top: 0; background: var(--pxn-surface-2); z-index: 1; text-align: left; font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); padding: var(--pxn-space-3) var(--pxn-space-4); }
.pxcfg__table td { padding: var(--pxn-space-3) var(--pxn-space-4); border-top: 1px solid var(--pxn-border); vertical-align: middle; }
.pxcfg__cellsel { min-width: 150px; }
.pxcfg__tr { text-align: right; white-space: nowrap; }
.pxcfg__tc { text-align: center; }

/* summary strip */
.pxcfg__summary { display: flex; flex-wrap: wrap; gap: var(--pxn-space-6); margin-top: var(--pxn-space-5); padding: var(--pxn-space-4) var(--pxn-space-5); background: var(--pxn-surface-2); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md); }
.pxcfg__summitem { display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__summnum { font-size: var(--pxn-fs-lg); font-weight: var(--pxn-fw-semibold); font-variant-numeric: tabular-nums; }
.pxcfg__summnum--ok { color: var(--pxn-success); }
.pxcfg__summnum--warn { color: var(--pxn-warning-ink, var(--pxn-warning)); }
.pxcfg__summlabel { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }

/* fiscal-series card */
.pxcfg__branch .pxcfg__cardhead { align-items: center; }
.pxcfg__branchcode { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); }
.pxcfg__serieident { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pxcfg__serielabel { font-size: var(--pxn-fs-md); font-weight: var(--pxn-fw-semibold); letter-spacing: 0.02em; font-variant-numeric: tabular-nums; }
.pxcfg__branchrow { padding-bottom: var(--pxn-space-3); }
.pxcfg__meter { height: 3px; border-radius: 999px; background: var(--pxn-border); overflow: hidden; }
.pxcfg__meterfill { display: block; height: 100%; border-radius: inherit; background: var(--pxn-success); transition: background-color 120ms cubic-bezier(0.25, 0.46, 0.45, 0.94); }
.pxcfg__meterfill--running_low, .pxcfg__meterfill--expiring { background: var(--pxn-warning-ink, var(--pxn-warning)); }
.pxcfg__meterfill--exhausted, .pxcfg__meterfill--expired { background: var(--pxn-danger-ink, var(--pxn-danger)); }
.pxcfg__branchsection { padding: var(--pxn-space-4) 0; border-top: 1px solid var(--pxn-border); }
.pxcfg__branchsectionhead { display: flex; flex-direction: column; gap: var(--pxn-space-1); margin-bottom: var(--pxn-space-3); }
.pxcfg__branchsectiontitle { font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); }
.pxcfg__branchcodes { max-width: 460px; }
.pxcfg__branchactions { display: flex; flex-wrap: wrap; gap: var(--pxn-space-3); align-items: center; margin-top: var(--pxn-space-3); }
.pxcfg__drawers { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-2) var(--pxn-space-4); }
@media (max-width: 900px) { .pxcfg__drawers { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pxcfg__drawers { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__cai { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxcfg__caigrid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-3) var(--pxn-space-5); }
@media (max-width: 780px) { .pxcfg__caigrid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .pxcfg__caigrid { grid-template-columns: minmax(0, 1fr); } }
.pxcfg__caigrid > div { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
.pxcfg__cailbl { font-size: var(--pxn-fs-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); }
.pxcfg__caival { font-size: var(--pxn-fs-sm); }
.pxcfg__branchalert { margin-top: var(--pxn-space-4); }
.pxcfg__errlist { margin: 0; padding-left: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__gaplist { margin: var(--pxn-space-2) 0 0; padding-left: var(--pxn-space-5); display: flex; flex-direction: column; gap: var(--pxn-space-1); }
.pxcfg__gaplist li { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); align-items: baseline; }
.pxcfg__gapwhere { font-weight: var(--pxn-fw-medium); }
.pxcfg__gapcode { color: var(--pxn-ink-3); font-weight: var(--pxn-fw-regular); }
.pxcfg__gapreason { font-size: var(--pxn-fs-xs); color: var(--pxn-danger-ink, var(--pxn-danger)); text-transform: uppercase; letter-spacing: 0.03em; }
</style>
