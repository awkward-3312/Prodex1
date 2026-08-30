<template>
  <div class="px-next pxe">
    <!--
      C2B — Edición de producto px-next (preview).
      Accesible por alias dev-only /app/_ui/producto/:id/edicion mientras
      /app/products/edit/:id sigue sirviendo la vista legacy. Conserva TODOS los
      campos y comportamientos de Edit_product.vue: mismos endpoints, mismo
      FormData, mismo vee-validate, mismo v-select para multi-categoría, mismas
      variantes / galería / combo / packs / lotes / ubicaciones. Solo cambia la
      composición visual (px-next).
    -->

    <div v-if="!can('products_edit')" class="pxe__denied">
      <px-empty-state
        icon="lock"
        title="No tienes permiso para editar productos"
        description="Pide a un administrador el permiso «products_edit»."
      />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxe__pad">
        <px-skeleton variant="card" :rows="8" />
      </div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el producto" class="pxe__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="GetElements()">Reintentar</px-button></template>
      </px-alert>

      <validation-observer v-else ref="Edit_Product">
        <form @submit.prevent="Submit_Product" enctype="multipart/form-data">
          <px-page-header
            title="Editar producto"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Productos' }, { label: product.name || '—' }]"
          >
            <template #meta>
              <span class="pxn-mono">{{ product.code || '—' }}</span>
              <span><lucide-icon name="shapes" :size="13" /> {{ typeLabel }}</span>
              <span v-if="hasPendingGalleryUploads" class="pxe__warnmeta"><lucide-icon name="upload" :size="13" /> imágenes sin guardar</span>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="goCancel">Cancelar</px-button>
              <px-button
                variant="primary"
                icon="check"
                type="submit"
                :loading="SubmitProcessing"
              >{{ SubmitProcessing ? 'Guardando…' : 'Guardar cambios' }}</px-button>
            </template>
          </px-page-header>

          <div class="pxe__grid">
            <!-- ================= COLUMNA PRINCIPAL ================= -->
            <div class="pxe__main">
              <!-- ===== Información básica ===== -->
              <px-card id="section-basic" title="Información básica" class="pxe__sec">
                <div class="pxe__row2">
                  <v-field name="Nombre" label="Nombre del producto" required :rules="{ required: true, min: 3, max: 55 }" v-slot="{ invalid, id }">
                    <px-input :id="id" v-model="product.name" :invalid="invalid" placeholder="Nombre del producto" />
                  </v-field>

                  <v-field name="Simbología" label="Simbología de código de barras" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      v-model="product.Type_barcode"
                      :reduce="o => o.value"
                      placeholder="Elegir simbología"
                      :options="[
                        { label: 'Code 128', value: 'CODE128' },
                        { label: 'Code 39', value: 'CODE39' },
                        { label: 'EAN8', value: 'EAN8' },
                        { label: 'EAN13', value: 'EAN13' },
                        { label: 'UPC', value: 'UPC' }
                      ]"
                    />
                  </v-field>

                  <v-field name="Código" label="Código del producto" required :rules="{ required: true }" :force-error="code_exist || null" v-slot="{ invalid, id }">
                    <div class="pxe-inline">
                      <px-button type="button" variant="secondary" size="sm" icon-only icon="scan-line" aria-label="Escanear" @click="showModal" />
                      <px-input :id="id" v-model="product.code" :invalid="invalid" placeholder="Código" />
                      <px-button type="button" variant="secondary" size="sm" icon-only icon="barcode" aria-label="Generar" @click="generateNumber" />
                    </div>
                  </v-field>

                  <px-field v-if="show_product_gtin" label="Código de barras (GTIN / UPC / EAN)">
                    <template #default="{ id }">
                      <px-input :id="id" v-model="product.gtin" placeholder="GTIN / UPC / EAN / ISBN" />
                    </template>
                  </px-field>

                  <v-field name="Categoría" label="Categorías (la primera es la principal)" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      multiple
                      :close-on-select="false"
                      :reduce="o => o.value"
                      placeholder="Elegir categorías"
                      v-model="product.assigned_category_ids"
                      :options="categories.map(c => ({ label: c.name, value: c.id }))"
                    />
                  </v-field>

                  <px-field label="Subcategorías (la primera es la principal)">
                    <template #default="{ id }">
                      <vs-px
                        :input-id="id"
                        multiple
                        :close-on-select="false"
                        :reduce="o => o.value"
                        v-model="product.assigned_subcategory_ids"
                        :options="subcategoryOptionsFiltered"
                        placeholder="Elegir subcategorías"
                        :disabled="!(product.assigned_category_ids && product.assigned_category_ids.length)"
                      />
                    </template>
                  </px-field>

                  <px-field label="Marca">
                    <template #default="{ id }">
                      <vs-px
                        :input-id="id"
                        placeholder="Elegir marca"
                        :reduce="o => o.value"
                        v-model="product.brand_id"
                        :options="brands.map(b => ({ label: b.name, value: b.id }))"
                      />
                    </template>
                  </px-field>
                </div>

                <px-field label="Descripción" class="pxe__full">
                  <template #default="{ id }">
                    <px-textarea :id="id" v-model="product.note" :rows="4" placeholder="Unas palabras sobre el producto" />
                  </template>
                </px-field>
              </px-card>

              <!-- ===== Galería de imágenes ===== -->
              <px-card id="section-gallery" title="Galería de imágenes" class="pxe__sec">
                <p class="pxe__hint">Sube imágenes del producto. Haz clic en una miniatura para marcarla como principal; arrastra para reordenar.</p>

                <label class="pxe-drop" :class="{ 'is-disabled': hasPendingGalleryUploads }">
                  <lucide-icon name="upload" :size="22" />
                  <span class="pxe-drop__title">Añadir imágenes</span>
                  <span class="pxe-drop__sub">Formatos: JPG, PNG, GIF</span>
                  <input
                    type="file"
                    accept="image/*"
                    multiple
                    class="pxe-drop__native"
                    :disabled="hasPendingGalleryUploads"
                    @change="onGalleryExtraSelected"
                  />
                </label>

                <div v-if="!product_images.length" class="pxe__emptybox">Aún no hay imágenes.</div>

                <draggable
                  v-else
                  v-model="product_images"
                  handle=".pxe-gal__handle"
                  :disabled="hasPendingGalleryUploads"
                  class="pxe-gal"
                  @end="touchGalleryOrder"
                >
                  <div
                    v-for="(row, idx) in product_images"
                    :key="row._uid || ('id-' + row.id)"
                    class="pxe-gal__item"
                    :class="{ 'is-main': row.is_main }"
                  >
                    <span class="pxe-gal__handle" title="Reordenar"><lucide-icon name="grip-vertical" :size="16" /></span>
                    <button
                      type="button"
                      class="pxe-gal__thumb pxn-ring"
                      :class="{ 'is-main': row.is_main }"
                      :title="'Marcar como principal'"
                      @click="setGalleryMain(row)"
                    >
                      <img :src="row.url || (row.image_path ? $imgUrl('products', row.image_path) : '')" alt="" />
                    </button>
                    <div class="pxe-gal__meta">
                      <div class="pxe-gal__name">{{ row.image_path }}</div>
                      <px-badge v-if="row.is_main" tone="success" icon="check">Imagen principal</px-badge>
                    </div>
                    <px-button type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar" @click="removeGalleryRow(idx)" />
                  </div>
                </draggable>
              </px-card>

              <!-- ===== Inventario ===== -->
              <px-card id="section-inventory" title="Inventario" class="pxe__sec">
                <div class="pxe__row2">
                  <px-field label="Tipo">
                    <template #default="{ id }">
                      <px-input :id="id" :value="typeLabel" disabled />
                    </template>
                  </px-field>

                  <template v-if="product.type != 'is_service'">
                    <v-field name="Unidad" label="Unidad del producto" required :rules="{ required: true }" v-slot="{ invalid, id }">
                      <vs-px
                        :input-id="id"
                        :invalid="invalid"
                        v-model="product.unit_id"
                        @input="Selected_Unit"
                        placeholder="Elegir unidad"
                        :reduce="o => o.value"
                        :options="units.map(u => ({ label: u.name, value: u.id }))"
                      />
                    </v-field>

                    <v-field name="Unidad de venta" label="Unidad de venta" required :rules="{ required: true }" v-slot="{ invalid, id }">
                      <vs-px
                        :input-id="id"
                        :invalid="invalid"
                        v-model="product.unit_sale_id"
                        placeholder="Elegir unidad de venta"
                        :reduce="o => o.value"
                        :options="units_sub.map(u => ({ label: u.name, value: u.id }))"
                      />
                    </v-field>

                    <v-field name="Unidad de compra" label="Unidad de compra" required :rules="{ required: true }" v-slot="{ invalid, id }">
                      <vs-px
                        :input-id="id"
                        :invalid="invalid"
                        v-model="product.unit_purchase_id"
                        placeholder="Elegir unidad de compra"
                        :reduce="o => o.value"
                        :options="units_sub.map(u => ({ label: u.name, value: u.id }))"
                      />
                    </v-field>

                    <v-field name="Alerta de stock" label="Alerta de stock" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.stock_alert" :invalid="invalid" placeholder="0" />
                    </v-field>

                    <v-field name="Peso" label="Peso" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.weight" :invalid="invalid" placeholder="0.00" />
                    </v-field>
                  </template>
                </div>

                <template v-if="product.type != 'is_service'">
                  <h4 class="pxe__subhead">Dimensiones</h4>
                  <div class="pxe__row3">
                    <v-field name="Largo" label="Largo" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.length" :invalid="invalid" placeholder="0.00" />
                    </v-field>
                    <v-field name="Ancho" label="Ancho" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.width" :invalid="invalid" placeholder="0.00" />
                    </v-field>
                    <v-field name="Alto" label="Alto" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.height" :invalid="invalid" placeholder="0.00" />
                    </v-field>
                  </div>
                </template>
              </px-card>

              <!-- ===== Precios e impuestos ===== -->
              <px-card id="section-pricing" title="Precios e impuestos" class="pxe__sec">
                <div class="pxe__row2">
                  <v-field
                    v-if="product.type == 'is_single' || product.type == 'is_combo'"
                    name="Costo" label="Costo del producto" required
                    :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }"
                  >
                    <px-input :id="id" v-model="product.cost" :invalid="invalid" placeholder="0.00" />
                  </v-field>

                  <template v-if="product.type == 'is_single' || product.type == 'is_service' || product.type == 'is_combo'">
                    <v-field name="Precio" label="Precio de venta" required :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.price" :invalid="invalid" placeholder="Precio de venta" />
                    </v-field>
                    <v-field name="Precio mayoreo" label="Precio mayoreo" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.wholesale_price" :invalid="invalid" placeholder="Precio mayoreo" />
                    </v-field>
                    <v-field name="Precio mínimo" label="Precio mínimo de venta" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                      <px-input :id="id" v-model="product.min_price" :invalid="invalid" placeholder="Precio mínimo de venta" />
                    </v-field>
                  </template>

                  <v-field name="Impuesto" label="Impuesto (Order Tax)" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                    <px-input :id="id" v-model.number="product.TaxNet" :invalid="invalid" placeholder="0" icon-trail="percent" />
                  </v-field>

                  <v-field name="Método de impuesto" label="Método de impuesto" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <px-select :id="id" v-model="product.tax_method" :options="[
                      { value: '1', label: 'Exclusivo' },
                      { value: '2', label: 'Inclusivo' }
                    ]" :invalid="invalid" placeholder="Elegir método" />
                  </v-field>

                  <v-field name="Método de descuento" label="Método de descuento" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <px-select :id="id" v-model="product.discount_method" :options="[
                      { value: '1', label: 'Porcentaje %' },
                      { value: '2', label: 'Fijo' }
                    ]" :invalid="invalid" placeholder="Elegir método" />
                  </v-field>

                  <v-field name="Descuento" label="Descuento" required :rules="{ required: true, regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                    <px-input :id="id" v-model.number="product.discount" :invalid="invalid" placeholder="0.00" />
                  </v-field>

                  <v-field name="Puntos" label="Puntos" :rules="{ regex: /^\d*\.?\d*$/ }" v-slot="{ invalid, id }">
                    <px-input :id="id" v-model.number="product.points" :invalid="invalid" placeholder="0" />
                  </v-field>
                </div>
              </px-card>

              <!-- ===== Productos del combo ===== -->
              <px-card v-if="product.type == 'is_combo'" id="section-combo" title="Productos del combo" class="pxe__sec">
                <px-field label="Buscar producto">
                  <template #default="{ id }">
                    <div class="pxe-ac">
                      <input
                        :id="id"
                        class="pxe-ac__input pxn-ring"
                        :placeholder="'Escanear / buscar por código o nombre'"
                        @input="e => search_input = e.target.value"
                        @keyup="search(search_input)"
                        @focus="handleFocus"
                        @blur="handleBlur"
                        ref="product_autocomplete"
                      />
                      <ul v-show="focused && product_filter.length" class="pxe-ac__list pxn-scroll">
                        <li
                          v-for="pf in product_filter"
                          :key="pf.id"
                          class="pxe-ac__opt"
                          @mousedown="SearchProduct(pf)"
                        >{{ getResultValue(pf) }}</li>
                      </ul>
                    </div>
                  </template>
                </px-field>

                <div class="pxe-tbl__wrap pxn-scroll">
                  <table class="pxe-tbl">
                    <thead>
                      <tr>
                        <th>Nombre</th>
                        <th style="width: 140px;">Cantidad</th>
                        <th class="is-right">Costo</th>
                        <th class="is-right">Subtotal</th>
                        <th style="width: 44px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-if="!materiels.length"><td colspan="5" class="pxe-tbl__empty">Sin datos</td></tr>
                      <tr v-for="materiel in materiels" :key="materiel.product_id">
                        <td>
                          <span class="pxe-tbl__name">{{ materiel.name }}</span>
                          <span class="pxe-tbl__sub pxn-mono">{{ materiel.code }}</span>
                        </td>
                        <td>
                          <div class="pxe-inline">
                            <px-input v-model.number="materiel.quantity" />
                            <span class="pxe-tbl__unit">{{ materiel.unit_name }}</span>
                          </div>
                        </td>
                        <td class="is-right pxn-num">{{ currentUser.currency }} {{ materiel.cost }}</td>
                        <td class="is-right pxn-num is-strong">{{ currentUser.currency }} {{ formatNumber(materiel.cost * materiel.quantity, priceDecimals) }}</td>
                        <td class="is-right">
                          <px-button type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar" @click="delete_materiel(materiel.product_id)" />
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div v-if="materiels.length" class="pxe-total">
                  <span>Costo total</span>
                  <span class="pxn-num">{{ currentUser.currency }} {{ formatNumber(totalCost, priceDecimals) }}</span>
                </div>
              </px-card>

              <!-- ===== Garantía ===== -->
              <px-card id="section-warranty" title="Garantía y seguimiento" class="pxe__sec">
                <div class="pxe__row2">
                  <px-field label="Periodo de garantía">
                    <template #default="{ id }">
                      <div class="pxe-inline">
                        <px-input :id="id" v-model="product.warranty_period" placeholder="0" />
                        <px-select v-model="product.warranty_unit" :options="unitPeriodOptions" placeholder="Unidad" />
                      </div>
                    </template>
                  </px-field>

                  <px-field label="Garantía extendida">
                    <template #default>
                      <px-check type="switch" v-model="product.has_guarantee">Tiene garantía extendida</px-check>
                    </template>
                  </px-field>

                  <px-field label="Términos de garantía" class="pxe__full">
                    <template #default="{ id }">
                      <px-textarea :id="id" v-model="product.warranty_terms" :rows="3" placeholder="Escribe los términos de garantía" />
                    </template>
                  </px-field>

                  <px-field v-if="product.has_guarantee" label="Periodo de garantía extendida">
                    <template #default="{ id }">
                      <div class="pxe-inline">
                        <px-input :id="id" v-model="product.guarantee_period" placeholder="0" />
                        <px-select v-model="product.guarantee_unit" :options="unitPeriodOptions" placeholder="Unidad" />
                      </div>
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Ubicación interna por almacén ===== -->
              <px-card id="section-location" title="Ubicación interna (rack / estante)" class="pxe__sec">
                <p class="pxe__hint">Opcional. Sugiere la ubicación por defecto de este producto en cada almacén.</p>
                <div class="pxe__row2">
                  <px-field v-for="wh in warehouses" :key="wh.id" :label="wh.name">
                    <template #default="{ id }">
                      <div class="pxe-inline">
                        <vs-px
                          class="pxe-vs--grow"
                          :input-id="id"
                          :options="locationsByWarehouse[wh.id] || []"
                          label="label"
                          :reduce="o => o.id"
                          placeholder="Elegir ubicación"
                          v-model="warehouse_location_map[wh.id]"
                        />
                        <px-button
                          type="button"
                          variant="secondary"
                          size="sm"
                          icon-only
                          icon="plus"
                          :aria-label="'Añadir ubicación'"
                          @click="openQuickWarehouseLocationModal(wh.id)"
                        />
                      </div>
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Opciones ===== -->
              <px-card id="section-options" title="Opciones" class="pxe__sec">
                <div class="pxe__opts">
                  <div v-if="show_serial_tracking" class="pxe__opt">
                    <px-check type="switch" v-model="product.is_imei">Seguimiento por serie / IMEI</px-check>
                    <small class="pxe__hint">Registra números de serie / IMEI por unidad.</small>
                  </div>
                  <div class="pxe__opt"><px-check type="switch" v-model="product.not_selling">Este producto no está a la venta</px-check></div>
                  <div class="pxe__opt"><px-check type="switch" v-model="product.is_active">Activo</px-check></div>
                  <div class="pxe__opt"><px-check type="switch" v-model="product.is_featured">Producto destacado</px-check></div>
                  <div class="pxe__opt"><px-check type="switch" v-model="product.hide_from_online_store">Ocultar de la tienda en línea</px-check></div>
                  <div class="pxe__opt"><px-check type="switch" v-model="product.is_preorder">Habilitar preventa</px-check></div>
                </div>

                <div v-if="product.is_preorder" class="pxe__row3 pxe__mt">
                  <px-field label="Fecha disponible de preventa">
                    <template #default="{ id }"><px-input :id="id" type="date" v-model="product.preorder_available_date" /></template>
                  </px-field>
                  <px-field label="Límite de preventa">
                    <template #default="{ id }"><px-input :id="id" type="number" v-model="product.preorder_limit" placeholder="Límite de preventa" /></template>
                  </px-field>
                  <px-field label="Nota de preventa">
                    <template #default="{ id }"><px-input :id="id" v-model="product.preorder_note" placeholder="Nota de preventa" /></template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Farmacia ===== -->
              <px-card id="section-pharmacy" title="Farmacia" class="pxe__sec">
                <div class="pxe__opt">
                  <px-check type="switch" v-model="product.is_batch_tracked">Seguimiento de lotes y caducidad</px-check>
                  <small class="pxe__hint">Activa el control de lotes y fechas de caducidad (FEFO en POS).</small>
                </div>

                <px-field v-if="product.is_batch_tracked" label="Vida útil (días)" class="pxe__mt">
                  <template #default="{ id }"><px-input :id="id" type="number" v-model="product.shelf_life_days" placeholder="Vida útil (días)" /></template>
                </px-field>

                <div class="pxe__opt pxe__mt"><px-check type="switch" v-model="product.prescription_required">Requiere receta</px-check></div>

                <div class="pxe__row2 pxe__mt">
                  <px-field label="Nombre genérico">
                    <template #default="{ id }"><px-input :id="id" v-model="product.generic_name" placeholder="Nombre genérico" /></template>
                  </px-field>
                  <px-field label="Concentración">
                    <template #default="{ id }"><px-input :id="id" v-model="product.strength" placeholder="Concentración" /></template>
                  </px-field>
                  <px-field label="Forma farmacéutica">
                    <template #default="{ id }"><px-input :id="id" v-model="product.dosage_form" placeholder="Forma farmacéutica" /></template>
                  </px-field>
                  <px-field label="Tamaño del paquete">
                    <template #default="{ id }"><px-input :id="id" v-model="product.pack_size" placeholder="Tamaño del paquete" /></template>
                  </px-field>
                  <px-field label="Fabricante">
                    <template #default="{ id }"><px-input :id="id" v-model="product.manufacturer" placeholder="Fabricante" /></template>
                  </px-field>
                  <px-field label="Lista / clasificación del fármaco">
                    <template #default="{ id }"><px-input :id="id" v-model="product.drug_schedule" placeholder="Lista / clasificación" /></template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Lotes de apertura ===== -->
              <px-card
                v-if="product.is_batch_tracked && canManageBatches && opening_rows.length"
                id="section-opening-batches"
                title="Lotes de apertura y caducidad"
                class="pxe__sec"
              >
                <p class="pxe__hint">Etiqueta el stock que existía antes del control de lotes para que POS/FEFO pueda venderlo. Guardar aquí NO cambia el stock del almacén.</p>
                <div class="pxe-tbl__wrap pxn-scroll">
                  <table class="pxe-tbl">
                    <thead>
                      <tr>
                        <th>Almacén</th>
                        <th v-if="openingHasVariants">Variante</th>
                        <th class="is-right">Stock actual</th>
                        <th class="is-right">En lotes</th>
                        <th class="is-right">Sin lotear</th>
                        <th class="is-right"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <template v-for="row in opening_rows">
                        <tr :key="openingRowKey(row)">
                          <td>{{ row.warehouse_name }}</td>
                          <td v-if="openingHasVariants">{{ row.variant_name || '—' }}</td>
                          <td class="is-right pxn-num">{{ row.stock_qty }}</td>
                          <td class="is-right pxn-num">{{ row.batched_qty }}</td>
                          <td class="is-right pxn-num" :class="row.unbatched_qty > 0 ? 'pxe-tbl__warn' : 'pxe-tbl__ok'">{{ row.unbatched_qty }}</td>
                          <td class="is-right">
                            <px-button v-if="row.unbatched_qty > 0" type="button" size="sm" variant="secondary" @click="openOpeningEditor(row)">Asignar lotes</px-button>
                            <px-badge v-else tone="success">Todo loteado</px-badge>
                          </td>
                        </tr>
                        <tr v-if="isOpeningEditorFor(row)" :key="openingRowKey(row) + '-editor'">
                          <td :colspan="openingHasVariants ? 6 : 5">
                            <div class="pxe-oed">
                              <div v-for="(b, idx) in opening_editor.batches" :key="idx" class="pxe-oed__row">
                                <px-field label="Nº de lote"><template #default="{ id }"><px-input :id="id" v-model="b.batch_no" placeholder="Nº de lote" /></template></px-field>
                                <px-field label="Caducidad"><template #default="{ id }"><px-input :id="id" type="date" v-model="b.expiry_date" /></template></px-field>
                                <px-field label="Fabricación"><template #default="{ id }"><px-input :id="id" type="date" v-model="b.mfg_date" /></template></px-field>
                                <px-field label="Cantidad"><template #default="{ id }"><px-input :id="id" type="number" v-model.number="b.qty" /></template></px-field>
                                <px-field label="Costo unit."><template #default="{ id }"><px-input :id="id" type="number" v-model.number="b.unit_cost" /></template></px-field>
                                <px-button
                                  type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar línea"
                                  :disabled="opening_editor.batches.length <= 1"
                                  @click="opening_editor.batches.splice(idx, 1)"
                                />
                              </div>
                              <div class="pxe-oed__foot">
                                <px-button type="button" size="sm" variant="secondary" icon="plus" @click="addOpeningBatchLine">Añadir</px-button>
                                <div class="pxe-oed__foot-r">
                                  <span :class="openingRemaining < 0 ? 'pxe-tbl__warn' : 'pxn-muted'">Por asignar: {{ openingRemaining }}</span>
                                  <px-button type="button" size="sm" variant="primary" :disabled="openingSaveDisabled" @click="saveOpeningBatches">Guardar</px-button>
                                  <px-button type="button" size="sm" variant="ghost" @click="opening_editor = null">Cancelar</px-button>
                                </div>
                              </div>
                              <p v-if="opening_error" class="pxe__err">{{ opening_error }}</p>
                            </div>
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </px-card>

              <!-- ===== Venta multi-paquete ===== -->
              <px-card
                v-if="enable_multi_pack_selling && product.type == 'is_single'"
                id="section-packs"
                title="Venta multi-paquete"
                class="pxe__sec"
              >
                <p class="pxe__hint">Vende este producto en varios paquetes. El paquete por defecto (multiplicador 1) vende en la unidad base; cada paquete adicional multiplica la cantidad base.</p>
                <div class="pxe-tbl__wrap pxn-scroll">
                  <table class="pxe-tbl">
                    <thead>
                      <tr>
                        <th>Nombre del paquete</th>
                        <th style="width: 150px;">Multiplicador</th>
                        <th style="width: 170px;">Precio de venta</th>
                        <th class="is-center" style="width: 90px;">Activo</th>
                        <th style="width: 44px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(pack, index) in packs" :key="'pack-' + index">
                        <td>
                          <px-input v-model="pack.name" placeholder="Nombre del paquete" size="sm" />
                          <px-badge v-if="pack.is_default" tone="info">Por defecto</px-badge>
                        </td>
                        <td><px-input v-model.number="pack.multiplier" type="number" size="sm" :disabled="pack.is_default" /></td>
                        <td><px-input v-model.number="pack.price" type="number" size="sm" /></td>
                        <td class="is-center"><px-check type="switch" v-model="pack.is_active" :disabled="pack.is_default" /></td>
                        <td class="is-center">
                          <px-button v-if="!pack.is_default" type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar" @click="delete_pack(index)" />
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <px-button type="button" variant="secondary" size="sm" icon="plus" class="pxe__mt" @click="add_pack">Añadir paquete</px-button>
              </px-card>

              <!-- ===== Variantes ===== -->
              <px-card v-if="product.type == 'is_variant'" id="section-variants" title="Variantes" class="pxe__sec">
                <px-field label="Nueva variante">
                  <template #default="{ id }">
                    <div class="pxe-inline">
                      <px-input :id="id" v-model="tag" placeholder="Escribe el nombre de la variante" />
                      <px-button type="button" variant="primary" icon="plus" @click="add_variant(tag)">Añadir</px-button>
                    </div>
                  </template>
                </px-field>

                <div v-if="variants.length" class="pxe-tbl__wrap pxn-scroll pxe__mt">
                  <table class="pxe-tbl">
                    <thead>
                      <tr>
                        <th class="is-center" style="width: 70px;">Imagen</th>
                        <th>Código</th>
                        <th v-if="show_product_gtin">GTIN</th>
                        <th>Nombre</th>
                        <th>Costo</th>
                        <th>Precio</th>
                        <th>Mayoreo</th>
                        <th>Precio mín.</th>
                        <th style="width: 44px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="variant in variants" :key="variant.var_id">
                        <td class="is-center">
                          <label class="pxe-vimg">
                            <img
                              :src="variant.imagePreview || (variant.image && variant.image !== 'no-image.png' ? $imgUrl('products', variant.image) : $imgUrl('products', 'no-image.png'))"
                              alt="variante"
                            />
                            <input type="file" accept="image/*" @change="onVariantImage($event, variant)" />
                          </label>
                        </td>
                        <td><px-input v-model="variant.code" size="sm" /></td>
                        <td v-if="show_product_gtin"><px-input v-model="variant.gtin" size="sm" /></td>
                        <td><px-input v-model="variant.text" size="sm" /></td>
                        <td><px-input v-model="variant.cost" size="sm" /></td>
                        <td><px-input v-model="variant.price" size="sm" /></td>
                        <td><px-input v-model="variant.wholesale" size="sm" /></td>
                        <td><px-input v-model="variant.min_price" size="sm" /></td>
                        <td class="is-center">
                          <px-button type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar" @click="delete_variant(variant.var_id)" />
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <px-alert v-else tone="info" bare class="pxe__mt">Sin datos.</px-alert>
              </px-card>

              <div class="pxe__spacer" aria-hidden="true"></div>
            </div>

            <!-- ================= RAIL CONTEXTUAL ================= -->
            <aside class="pxe__rail">
              <div class="pxe__rail-sticky">
                <nav class="pxe__nav">
                  <div class="pxe__nav-title"><lucide-icon name="list" :size="14" /> En esta página</div>
                  <button
                    v-for="s in sections"
                    :key="s.id"
                    type="button"
                    class="pxe__nav-link pxn-ring"
                    :class="{ 'is-active': activeSection === s.id }"
                    @click="scrollToSection(s.id)"
                  >
                    <lucide-icon :name="s.icon" :size="14" />
                    <span>{{ s.label }}</span>
                  </button>
                </nav>

                <px-card class="pxe__summary">
                  <template #header><h3 class="pxn-card__title">Resumen</h3></template>
                  <div class="pxe__summary-pills">
                    <px-badge tone="info" icon="shapes">{{ typeLabel }}</px-badge>
                    <px-badge :tone="product.is_active ? 'success' : 'neutral'" :icon="product.is_active ? 'check' : 'minus'">
                      {{ product.is_active ? 'Activo' : 'Inactivo' }}
                    </px-badge>
                    <px-badge v-if="product.is_featured" tone="warning" icon="star">Destacado</px-badge>
                  </div>
                  <dl class="pxe__summary-dl">
                    <div><dt>Nombre</dt><dd>{{ product.name || '—' }}</dd></div>
                    <div><dt>Código</dt><dd class="pxn-mono">{{ product.code || '—' }}</dd></div>
                    <div v-if="product.type == 'is_single' || product.type == 'is_combo'"><dt>Costo</dt><dd class="pxn-num">{{ currentUser.currency }} {{ product.cost || '0.00' }}</dd></div>
                    <div v-if="product.type != 'is_variant'"><dt>Precio</dt><dd class="pxn-num">{{ currentUser.currency }} {{ product.price || '0.00' }}</dd></div>
                    <div><dt>Impuesto</dt><dd class="pxn-num">{{ product.TaxNet || 0 }} %</dd></div>
                  </dl>
                </px-card>

                <px-alert tone="info" bare class="pxe__tip">
                  <lucide-icon name="lightbulb" :size="13" />
                  Ten cuidado al cambiar el código o la unidad: las transacciones históricas siguen apuntando a los valores originales.
                </px-alert>
              </div>
            </aside>
          </div>

          <!-- ===== Barra de acción fija ===== -->
          <div class="pxe__actionbar">
            <div class="pxe__actionbar-inner">
              <span class="pxe__actionbar-hint"><lucide-icon name="info" :size="14" /> Revisa tus cambios y guárdalos.</span>
              <div class="pxe__actionbar-btns">
                <px-button variant="secondary" type="button" @click="goCancel">Cancelar</px-button>
                <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">
                  {{ SubmitProcessing ? 'Guardando…' : 'Guardar cambios' }}
                </px-button>
              </div>
            </div>
          </div>
        </form>
      </validation-observer>

      <!-- ===== Modales (fuera del <form> y del observer principal: no deben
           registrar sus campos en la validación del producto ni anidar <form>) ===== -->
      <px-modal v-model="scanOpen" title="Escáner de código de barras" size="md">
        <qrcode-scanner :qrbox="250" :fps="10" style="width:100%;" @result="onScan" />
      </px-modal>

      <validation-observer ref="QuickWarehouseLocation">
        <px-modal v-model="quickLocOpen" :title="'Añadir ubicación de almacén'" size="sm">
          <div class="pxe__qform" @keydown.enter.prevent="submitQuickWarehouseLocation">
            <v-field name="Almacén" label="Almacén" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-select
                :id="id"
                v-model="quickWarehouseLocation.warehouse_id"
                :options="warehouses.map(w => ({ value: w.id, label: w.name }))"
                :disabled="quickWarehouseLocationWarehouseLocked"
                :invalid="invalid"
                placeholder="Elegir almacén"
              />
            </v-field>
            <v-field name="Código de rack/ubicación" label="Código de rack / ubicación" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickWarehouseLocation.code" :invalid="invalid" placeholder="Código de rack / ubicación" />
            </v-field>
            <px-field label="Nombre de la ubicación">
              <template #default="{ id }"><px-input :id="id" v-model="quickWarehouseLocation.name" placeholder="Nombre de la ubicación" /></template>
            </px-field>
          </div>
          <template #footer="{ close }">
            <span class="pxe__grow" />
            <px-button variant="secondary" :disabled="quickWarehouseLocationSubmitting" @click="close">Cancelar</px-button>
            <px-button variant="primary" icon="check" :loading="quickWarehouseLocationSubmitting" @click="submitQuickWarehouseLocation">Guardar</px-button>
          </template>
        </px-modal>
      </validation-observer>
    </template>
  </div>
</template>

<script>
import draggable from "vuedraggable";
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import { getPriceDecimals } from "@/utils/priceFormat";
import PxPageHeader from "@/components/px-next/PxPageHeader.vue";
import PxCard from "@/components/px-next/PxCard.vue";
import PxField from "@/components/px-next/PxField.vue";
import PxInput from "@/components/px-next/PxInput.vue";
import PxTextarea from "@/components/px-next/PxTextarea.vue";
import PxSelect from "@/components/px-next/PxSelect.vue";
import PxCheck from "@/components/px-next/PxCheck.vue";
import PxButton from "@/components/px-next/PxButton.vue";
import PxBadge from "@/components/px-next/PxBadge.vue";
import PxAlert from "@/components/px-next/PxAlert.vue";
import PxEmptyState from "@/components/px-next/PxEmptyState.vue";
import PxModal from "@/components/px-next/PxModal.vue";
import VField from "./VField.vue";
import VsPx from "./VsPx.vue";

// C2B — mismo contrato funcional que Edit_product.vue: mismos endpoints, mismo
// FormData, misma validación. Solo cambia la presentación (px-next).
export default {
  name: "ProductEditNext",
  metaInfo: { title: "Editar producto" },
  components: {
    draggable,
    PxPageHeader, PxCard, PxField, PxInput, PxTextarea, PxSelect, PxCheck,
    PxButton, PxBadge, PxAlert, PxEmptyState, PxModal,
    "v-field": VField,
    "vs-px": VsPx
  },
  data() {
    return {
      // ---- preview shell ----
      loadError: null,
      scanOpen: false,
      quickLocOpen: false,
      activeSection: "section-basic",
      // ---- idéntico a Edit_product.vue ----
      focused: false,
      timer: null,
      search_input: "",
      product_filter: [],
      materiels: [],
      products_ing: [],
      warehouses: [],
      warehouse_locations: [],
      locationsByWarehouse: {},
      warehouse_location_map: {},
      quickWarehouseLocation: { warehouse_id: "", code: "", name: "", is_active: true },
      quickWarehouseLocationWarehouseLocked: false,
      quickWarehouseLocationSubmitting: false,
      tag: "",
      len: 8,
      change: false,
      isLoading: true,
      SubmitProcessing: false,
      data: new FormData(),
      categories: [],
      allSubcategories: [],
      units: [],
      units_sub: [],
      brands: [],
      roles: {},
      variants: [],
      show_product_gtin: true,
      show_serial_tracking: false,
      enable_multi_pack_selling: false,
      packs: [],
      product: {
        type: "", name: "", points: "", code: "", gtin: "", Type_barcode: "",
        cost: "", price: "", brand_id: "", category_id: "", sub_category_id: "",
        assigned_category_ids: [], assigned_subcategory_ids: [],
        TaxNet: "", tax_method: "1", unit_id: "", unit_sale_id: "", unit_purchase_id: "",
        stock_alert: "", weight: "", length: "", width: "", height: "", image: "", note: "",
        is_variant: false, is_imei: false, not_selling: false, is_active: true,
        is_featured: false, hide_from_online_store: false,
        is_preorder: false, preorder_available_date: "", preorder_limit: "", preorder_note: "",
        is_batch_tracked: false, shelf_life_days: "", generic_name: "", strength: "",
        dosage_form: "", pack_size: "", manufacturer: "", prescription_required: false, drug_schedule: ""
      },
      code_exist: "",
      product_images: [],
      galleryRemoveIds: [],
      galleryUidSeed: 0,
      opening_rows: [],
      opening_editor: null,
      opening_submitting: false,
      opening_error: ""
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    totalCost() {
      return this.materiels.reduce((total, materiel) => total + (materiel.cost * materiel.quantity), 0);
    },
    hasPendingGalleryUploads() {
      return (this.product_images || []).some(r => r && r._file);
    },
    canManageBatches() {
      const perms = this.currentUserPermissions || [];
      return perms.includes("batch_manage") || perms.includes("manage_batches");
    },
    openingHasVariants() {
      return (this.opening_rows || []).some(r => r.product_variant_id);
    },
    openingAssignedTotal() {
      if (!this.opening_editor) return 0;
      return this.opening_editor.batches.reduce((t, b) => t + (parseFloat(b.qty) || 0), 0);
    },
    openingRemaining() {
      if (!this.opening_editor) return 0;
      return Math.round((this.opening_editor.unbatched - this.openingAssignedTotal) * 10000) / 10000;
    },
    openingSaveDisabled() {
      if (!this.opening_editor || this.opening_submitting) return true;
      const batches = this.opening_editor.batches;
      if (!batches.length) return true;
      const seen = new Set();
      for (const b of batches) {
        const no = (b.batch_no || "").trim();
        if (!no || !(parseFloat(b.qty) > 0)) return true;
        const key = no.toLowerCase();
        if (seen.has(key)) return true;
        seen.add(key);
      }
      return this.openingRemaining < 0;
    },
    subcategoryOptionsFiltered() {
      const subs = this.allSubcategories || [];
      const cats = this.categories || [];
      const catName = id => {
        const c = cats.find(x => String(x.id) === String(id));
        return c ? c.name : "";
      };
      const selectedCats = this.product.assigned_category_ids || [];
      const set = new Set(selectedCats.map(id => String(id)));
      const filtered = !selectedCats.length ? [] : subs.filter(sc => set.has(String(sc.category_id)));
      return filtered.map(sc => {
        const cn = catName(sc.category_id);
        return { label: cn ? `${sc.name} (${cn})` : sc.name, value: sc.id };
      });
    },
    // ---- preview shell ----
    typeLabel() {
      return {
        is_single: "Simple",
        is_variant: "Variable",
        is_combo: "Combo",
        is_service: "Servicio"
      }[this.product.type] || "—";
    },
    unitPeriodOptions() {
      // Solo presentación; el value enviado sigue siendo days/months/years.
      return [
        { value: "days", label: "Días" },
        { value: "months", label: "Meses" },
        { value: "years", label: "Años" }
      ];
    },
    sections() {
      const s = [
        { id: "section-basic", label: "Información básica", icon: "file" },
        { id: "section-gallery", label: "Galería de imágenes", icon: "upload" },
        { id: "section-inventory", label: "Inventario", icon: "package" }
      ];
      if (this.product.type == "is_variant") s.push({ id: "section-variants", label: "Variantes", icon: "settings" });
      s.push({ id: "section-pricing", label: "Precios e impuestos", icon: "tag" });
      if (this.product.type == "is_combo") s.push({ id: "section-combo", label: "Productos del combo", icon: "shopping-bag" });
      s.push(
        { id: "section-warranty", label: "Garantía", icon: "shield" },
        { id: "section-location", label: "Ubicación interna", icon: "map-pin" },
        { id: "section-options", label: "Opciones", icon: "database-zap" },
        { id: "section-pharmacy", label: "Farmacia", icon: "heart-pulse" }
      );
      if (this.product.is_batch_tracked && this.canManageBatches && this.opening_rows.length) {
        s.push({ id: "section-opening-batches", label: "Lotes de apertura", icon: "layers" });
      }
      if (this.enable_multi_pack_selling && this.product.type == "is_single") {
        s.push({ id: "section-packs", label: "Venta multi-paquete", icon: "package" });
      }
      return s;
    }
  },
  watch: {
    "product.assigned_category_ids": {
      handler() {
        this.pruneInvalidSubcategories();
        this.syncLegacyCategoryFields();
      },
      deep: true
    },
    "product.assigned_subcategory_ids": {
      handler() {
        this.syncLegacyCategoryFields();
      },
      deep: true
    },
    "$route.params.id"() {
      this.isLoading = true;
      this.loadError = null;
      this.GetElements();
    }
  },
  created() {
    this.GetElements();
  },
  mounted() {
    this._onScrollSpy = () => this.updateActiveSection();
    window.addEventListener("scroll", this._onScrollSpy, true);
  },
  beforeDestroy() {
    if (this._onScrollSpy) window.removeEventListener("scroll", this._onScrollSpy, true);
  },
  methods: {
    can(p) {
      const list = Array.isArray(this.currentUserPermissions) ? this.currentUserPermissions : [];
      return list.includes(p);
    },
    goCancel() {
      this.$router.back();
    },
    scrollToSection(id) {
      const el = document.getElementById(id);
      if (el) {
        el.scrollIntoView({ behavior: "smooth", block: "start" });
        this.activeSection = id;
      }
    },
    updateActiveSection() {
      let current = this.activeSection;
      for (const s of this.sections) {
        const el = document.getElementById(s.id);
        if (el && el.getBoundingClientRect().top <= 140) current = s.id;
      }
      if (current !== this.activeSection) this.activeSection = current;
    },

    // ============ IDÉNTICO a Edit_product.vue ============
    formatNumber(number, dec) {
      const value = (typeof number === "string" ? number : number.toString()).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec) return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    touchGalleryOrder() {
      (this.product_images || []).forEach((r, i) => { r.sort_order = i; });
    },
    setGalleryMain(row) {
      (this.product_images || []).forEach(r => { r.is_main = r === row; });
    },
    removeGalleryRow(index) {
      const row = this.product_images[index];
      if (row && row.id) this.galleryRemoveIds.push(row.id);
      if (row && row._file && row.url && row.url.indexOf("blob:") === 0) {
        try { URL.revokeObjectURL(row.url); } catch (e) { /* ignore */ }
      }
      this.product_images.splice(index, 1);
      this.touchGalleryOrder();
      if (!this.product_images.some(r => r && r.is_main) && this.product_images.length) {
        this.$set(this.product_images[0], "is_main", true);
      }
    },
    onGalleryExtraSelected(e) {
      const files = Array.from(e.target.files || []).filter(f => f.type && f.type.indexOf("image/") === 0);
      files.forEach(f => {
        this.galleryUidSeed += 1;
        this.product_images.push({
          id: null,
          _uid: "n-" + this.galleryUidSeed,
          url: URL.createObjectURL(f),
          image_path: f.name,
          is_main: false,
          sort_order: this.product_images.length,
          _file: f
        });
      });
      this.touchGalleryOrder();
      if (!this.product_images.some(r => r && r.is_main) && this.product_images.length) {
        this.$set(this.product_images[0], "is_main", true);
      }
      e.target.value = "";
    },

    get_products_materiels() {
      window.axios.get("get_products_materiels").then(({ data }) => (this.products_ing = data));
    },
    handleFocus() { this.focused = true; },
    handleBlur() { this.focused = false; },
    search() {
      if (this.timer) { clearTimeout(this.timer); this.timer = null; }
      if (this.search_input.length < 1) return (this.product_filter = []);
      this.timer = setTimeout(() => {
        const product_filter = this.products_ing.filter(i => i.code === this.search_input);
        if (product_filter.length === 1) {
          this.SearchProduct(product_filter[0]);
        } else {
          this.product_filter = this.products_ing.filter(i =>
            i.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
            i.code.toLowerCase().includes(this.search_input.toLowerCase())
          );
        }
      }, 800);
    },
    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },
    SearchProduct(result) {
      this.ingredient = {};
      if (this.materiels.length > 0 && this.materiels.some(d => d.code === result.code)) {
        this.makeToast("danger", this.$t("Product_Already_added") || "Product already added", this.$t("Failed"));
      } else {
        this.materiels.push({
          product_id: result.product_id,
          name: result.name,
          code: result.code,
          unit_name: result.unit_name,
          cost: result.cost,
          quantity: 1
        });
      }
      this.search_input = "";
      if (this.$refs.product_autocomplete) this.$refs.product_autocomplete.value = "";
      this.product_filter = [];
    },
    delete_materiel(product_id) {
      for (let i = 0; i < this.materiels.length; i++) {
        if (product_id === this.materiels[i].product_id) this.materiels.splice(i, 1);
      }
    },

    showModal() { this.scanOpen = true; },
    onScan(decodedText) {
      this.product.code = decodedText;
      this.scanOpen = false;
    },
    generateNumber() {
      this.code_exist = "";
      this.product.code = Math.floor(
        Math.pow(10, 7) + Math.random() * (Math.pow(10, 8) - Math.pow(10, 7) - 1)
      );
    },

    syncLegacyCategoryFields() {
      const c = Array.isArray(this.product.assigned_category_ids) ? this.product.assigned_category_ids : [];
      const s = Array.isArray(this.product.assigned_subcategory_ids) ? this.product.assigned_subcategory_ids : [];
      const firstCat = c.length ? c[0] : "";
      const firstSub = s.length ? s[0] : "";
      this.$set(this.product, "category_id", firstCat === "" || firstCat == null ? "" : firstCat);
      this.$set(this.product, "sub_category_id", firstSub === "" || firstSub == null ? "" : firstSub);
    },
    pruneInvalidSubcategories() {
      const catSet = new Set((this.product.assigned_category_ids || []).map(id => String(id)));
      const subs = this.product.assigned_subcategory_ids || [];
      const all = this.allSubcategories || [];
      const filtered = subs.filter(sid => {
        const sc = all.find(x => String(x.id) === String(sid));
        return sc && catSet.has(String(sc.category_id));
      });
      if (filtered.length !== subs.length) {
        this.$set(this.product, "assigned_subcategory_ids", filtered);
      }
    },

    Submit_Product() {
      this.syncLegacyCategoryFields();
      this.$refs.Edit_Product.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else if (this.product.type == "is_variant" && this.variants.length <= 0) {
          this.makeToast("danger", "The variants array is required.", this.$t("Failed"));
        } else if (!this.validatePacks()) {
          // validatePacks already shows a toast
        } else {
          this.Update_Product();
        }
      });
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    openQuickWarehouseLocationModal(warehouseId) {
      this.quickWarehouseLocation = {
        warehouse_id: warehouseId || (this.warehouses[0] ? this.warehouses[0].id : ""),
        code: "",
        name: "",
        is_active: true
      };
      this.quickWarehouseLocationWarehouseLocked = true;
      this.quickLocOpen = true;
    },
    submitQuickWarehouseLocation() {
      this.$refs.QuickWarehouseLocation.validate().then(async success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.quickWarehouseLocationSubmitting = true;
        try {
          const payload = {
            warehouse_id: this.quickWarehouseLocation.warehouse_id,
            code: this.quickWarehouseLocation.code,
            name: this.quickWarehouseLocation.name || "",
            is_active: true
          };
          const { data } = await window.axios.post("products/warehouse_locations", payload);
          const newLoc = data && data.location ? data.location : null;
          if (newLoc && newLoc.id) {
            const wid = newLoc.warehouse_id;
            const label = newLoc.name ? `${newLoc.code} - ${newLoc.name}` : newLoc.code;
            if (!this.locationsByWarehouse[wid]) this.$set(this.locationsByWarehouse, wid, []);
            this.locationsByWarehouse[wid].push({ id: newLoc.id, label, is_active: true });
            this.$set(this.warehouse_location_map, wid, newLoc.id);
          }
          this.quickLocOpen = false;
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        } catch (e) {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        } finally {
          this.quickWarehouseLocationSubmitting = false;
        }
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },

    add_variant(tag) {
      if (this.variants.length > 0 && this.variants.some(v => v.text === tag)) {
        this.makeToast("warning", this.$t("VariantDuplicate"), this.$t("Warning"));
      } else if (this.tag != "") {
        this.variants.push({ var_id: this.variants.length + 1, text: tag });
        this.tag = "";
      } else {
        this.makeToast("warning", "Please Enter the Variant", this.$t("Warning"));
      }
    },
    delete_variant(var_id) {
      for (let i = 0; i < this.variants.length; i++) {
        if (var_id === this.variants[i].var_id) this.variants.splice(i, 1);
      }
    },

    ensureDefaultPack() {
      let defaultPack = this.packs.find(p => p.is_default);
      if (!defaultPack) {
        defaultPack = {
          id: null,
          name: this.$t("Default") || "Default",
          multiplier: 1,
          price: this.product.price || 0,
          is_active: true,
          is_default: true
        };
        this.packs.unshift(defaultPack);
      }
      defaultPack.multiplier = 1;
      defaultPack.is_active = true;
    },
    add_pack() {
      this.ensureDefaultPack();
      this.packs.push({ id: null, name: "", multiplier: 1, price: 0, is_active: true, is_default: false });
    },
    delete_pack(index) {
      if (this.packs[index] && this.packs[index].is_default) return;
      this.packs.splice(index, 1);
    },
    validatePacks() {
      if (!this.enable_multi_pack_selling || this.product.type != "is_single") return true;
      for (const pack of this.packs) {
        if (!pack.name || String(pack.name).trim() === "") {
          this.makeToast("danger", this.$t("Pack_Name_Required") || "Each pack needs a name.", this.$t("Failed"));
          return false;
        }
        if (!(Number(pack.multiplier) > 0)) {
          this.makeToast("danger", this.$t("Pack_Multiplier_Invalid") || "Pack multiplier must be greater than 0.", this.$t("Failed"));
          return false;
        }
        if (!(Number(pack.price) >= 0)) {
          this.makeToast("danger", this.$t("Pack_Price_Invalid") || "Pack price must be 0 or more.", this.$t("Failed"));
          return false;
        }
      }
      return true;
    },

    onVariantImage(event, variant) {
      const file = event.target.files && event.target.files[0];
      if (!file) return;
      if (!file.type || file.type.indexOf("image/") !== 0) {
        this.makeToast("warning", this.$t("Please_select_image"), this.$t("Warning"));
        event.target.value = "";
        return;
      }
      this.$set(variant, "imageFile", file);
      this.$set(variant, "imagePreview", URL.createObjectURL(file));
    },

    GetElements() {
      const id = this.$route.params.id;
      this.loadError = null;
      window.axios
        .get(`products/${id}/edit`)
        .then(response => {
          this.product = response.data.product;
          this.variants = response.data.product.ProductVariant;
          this.show_product_gtin = response.data.show_product_gtin !== false;
          this.show_serial_tracking = response.data.show_serial_tracking === true;
          this.enable_multi_pack_selling = response.data.enable_multi_pack_selling === true;
          this.packs = (response.data.product.packs || []).map(p => ({
            id: p.id,
            name: p.name,
            multiplier: Number(p.multiplier),
            price: Number(p.price),
            is_active: !!p.is_active,
            is_default: !!p.is_default
          }));
          if (this.enable_multi_pack_selling && this.product.type == "is_single") {
            this.ensureDefaultPack();
          }
          this.warehouses = response.data.warehouses || [];
          this.warehouse_locations = response.data.warehouse_locations || [];

          const byWh = {};
          (this.warehouse_locations || []).forEach(loc => {
            const wid = loc.warehouse_id;
            if (!byWh[wid]) byWh[wid] = [];
            const label = loc.name ? `${loc.code} - ${loc.name}` : loc.code;
            byWh[wid].push({ id: loc.id, label, is_active: !!loc.is_active });
          });
          this.locationsByWarehouse = byWh;

          const existing = {};
          (response.data.product_warehouse_locations || []).forEach(r => {
            existing[r.warehouse_id] = r.warehouse_location_id || null;
          });
          this.warehouses.forEach(wh => {
            this.$set(this.warehouse_location_map, wh.id, existing[wh.id] || null);
          });
          this.categories = response.data.categories;
          this.allSubcategories = response.data.all_subcategories || [];
          if (!Array.isArray(this.product.assigned_category_ids)) {
            this.$set(this.product, "assigned_category_ids", []);
          }
          if (!Array.isArray(this.product.assigned_subcategory_ids)) {
            this.$set(this.product, "assigned_subcategory_ids", []);
          }
          if (
            (!this.product.assigned_category_ids || !this.product.assigned_category_ids.length) &&
            this.product.category_id
          ) {
            this.$set(this.product, "assigned_category_ids", [this.product.category_id]);
          }
          if (
            (!this.product.assigned_subcategory_ids || !this.product.assigned_subcategory_ids.length) &&
            this.product.sub_category_id
          ) {
            this.$set(this.product, "assigned_subcategory_ids", [this.product.sub_category_id]);
          }
          this.$nextTick(() => {
            this.pruneInvalidSubcategories();
            this.syncLegacyCategoryFields();
          });
          this.brands = response.data.brands;
          this.units = response.data.units;
          this.units_sub = response.data.units_sub;
          this.galleryRemoveIds = [];
          this.galleryUidSeed = 0;
          const imgs = response.data.product.product_images || [];
          this.product_images = imgs.map(r => ({ ...r, _uid: "e-" + r.id }));
          if (this.product.type == "is_combo") {
            this.get_products_materiels();
            this.materiels = response.data.materiels;
          }
          if (this.canManageBatches) {
            this.fetchOpeningMeta();
          }
          this.isLoading = false;
        })
        .catch(error => {
          this.loadError =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            (error && error.message) ||
            "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });
    },

    fetchOpeningMeta() {
      const id = this.$route.params.id;
      window.axios
        .get(`products/${id}/opening_batches`)
        .then(({ data }) => {
          this.opening_rows = data && data.supported ? data.rows || [] : [];
        })
        .catch(() => { this.opening_rows = []; });
    },
    openingRowKey(row) {
      return "ob-" + row.warehouse_id + "-" + (row.product_variant_id || 0);
    },
    isOpeningEditorFor(row) {
      return this.opening_editor && this.opening_editor.key === this.openingRowKey(row);
    },
    newOpeningBatchLine(qty) {
      return {
        batch_no: "",
        expiry_date: "",
        mfg_date: "",
        qty: qty > 0 ? qty : "",
        unit_cost: this.product.cost !== "" && this.product.cost !== null ? this.product.cost : ""
      };
    },
    openOpeningEditor(row) {
      this.opening_error = "";
      this.opening_editor = {
        key: this.openingRowKey(row),
        warehouse_id: row.warehouse_id,
        product_variant_id: row.product_variant_id,
        unbatched: row.unbatched_qty,
        batches: [this.newOpeningBatchLine(row.unbatched_qty)]
      };
    },
    addOpeningBatchLine() {
      const remaining = this.openingRemaining;
      this.opening_editor.batches.push(this.newOpeningBatchLine(remaining > 0 ? remaining : ""));
    },
    saveOpeningBatches() {
      if (this.openingSaveDisabled) return;
      const id = this.$route.params.id;
      this.opening_submitting = true;
      this.opening_error = "";
      window.axios
        .post(`products/${id}/opening_batches`, {
          warehouse_id: this.opening_editor.warehouse_id,
          product_variant_id: this.opening_editor.product_variant_id,
          batches: this.opening_editor.batches.map(b => ({
            batch_no: (b.batch_no || "").trim(),
            expiry_date: b.expiry_date || null,
            mfg_date: b.mfg_date || null,
            qty: parseFloat(b.qty) || 0,
            unit_cost: b.unit_cost === "" || b.unit_cost === null ? null : parseFloat(b.unit_cost)
          }))
        })
        .then(() => {
          this.opening_submitting = false;
          this.opening_editor = null;
          this.makeToast("success", this.$t("Opening_Batches_Saved"), this.$t("Success"));
          this.fetchOpeningMeta();
        })
        .catch(error => {
          this.opening_submitting = false;
          let msg = this.$t("InvalidData");
          if (error && error.message) msg = error.message;
          else if (error && error.errors) {
            const first = Object.values(error.errors)[0];
            if (first && first.length) msg = first[0];
          }
          this.opening_error = msg;
        });
    },

    Get_Units_SubBase(value) {
      window.axios.get("get_sub_units_by_base?id=" + value).then(({ data }) => (this.units_sub = data));
    },
    Selected_Unit(value) {
      this.units_sub = [];
      this.product.unit_sale_id = "";
      this.product.unit_purchase_id = "";
      this.Get_Units_SubBase(value);
    },

    Update_Product() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.data = new FormData();
      self.SubmitProcessing = true;

      self.syncLegacyCategoryFields();

      if (self.product.type == "is_variant" && self.variants.length > 0) {
        self.product.is_variant = true;
      } else {
        self.product.is_variant = false;
      }

      const { assigned_category_ids, assigned_subcategory_ids, packs: _packs, ...prodRest } = self.product;
      Object.entries(prodRest).forEach(([key, value]) => {
        self.data.append(key, value);
      });
      self.data.append("multi_category_ids", JSON.stringify(assigned_category_ids || []));
      self.data.append("multi_subcategory_ids", JSON.stringify(assigned_subcategory_ids || []));

      if (self.materiels.length && self.product.type == "is_combo") {
        self.data.append("materiels", JSON.stringify(self.materiels));
      }

      if (self.variants.length) {
        for (var i = 0; i < self.variants.length; i++) {
          Object.entries(self.variants[i]).forEach(([key, value]) => {
            if (key === "imageFile" || key === "imagePreview") return;
            self.data.append("variants[" + i + "][" + key + "]", value);
          });
          if (self.variants[i].imageFile) {
            self.data.append("variant_images[" + i + "]", self.variants[i].imageFile);
          }
        }
      }

      const wlPayload = {};
      if (self.warehouses && self.warehouses.length) {
        self.warehouses.forEach(wh => {
          wlPayload[wh.id] = { warehouse_location_id: self.warehouse_location_map[wh.id] || null };
        });
      }
      self.data.append("warehouse_locations", JSON.stringify(wlPayload));

      if (self.enable_multi_pack_selling && self.product.type == "is_single") {
        self.ensureDefaultPack();
        self.data.append("packs", JSON.stringify(self.packs));
      }

      const orderPayload = [];
      (self.product_images || []).forEach((r, i) => {
        if (r && r.id) orderPayload.push({ id: r.id, sort_order: i });
      });
      const mainRow = (self.product_images || []).find(r => r && r.is_main);
      let main_id = null;
      let main_pending_index = null;
      if (mainRow) {
        if (mainRow.id) {
          main_id = mainRow.id;
        } else {
          const pending = (self.product_images || []).filter(r => r && r._file);
          const pi = pending.findIndex(r => r === mainRow);
          main_pending_index = pi >= 0 ? pi : null;
        }
      }
      const hasPersistedGallery = orderPayload.length > 0;
      const hasGalleryChanges =
        self.galleryRemoveIds.length > 0 ||
        hasPersistedGallery ||
        (self.product_images || []).some(r => r && r._file);
      if (hasGalleryChanges) {
        self.data.append(
          "product_gallery_json",
          JSON.stringify({
            remove: self.galleryRemoveIds,
            order: orderPayload,
            main_id: main_id,
            main_pending_index: main_pending_index
          })
        );
      }
      (self.product_images || []).forEach(r => {
        if (r && r._file) self.data.append("gallery_images[]", r._file);
      });

      self.data.append("_method", "put");

      window.axios
        .post("products/" + this.product.id, self.data)
        .then(() => {
          NProgress.done();
          self.SubmitProcessing = false;
          this.$router.push({ name: "index_products" });
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          NProgress.done();
          self.SubmitProcessing = false;
          const errs = (error && error.errors) || (error && error.response && error.response.data && error.response.data.errors) || {};
          if (errs.code && errs.code.length > 0) {
            self.code_exist = errs.code[0];
            this.makeToast("danger", errs.code[0], this.$t("Failed"));
          } else if (errs.variants && errs.variants.length > 0) {
            this.makeToast("danger", errs.variants[0], this.$t("Failed"));
          } else {
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          }
        });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxe {
  min-height: 100%;
  background: var(--pxn-bg);
  padding: var(--pxn-space-8) var(--pxn-space-9) 0;
}
@media (max-width: 620px) { .pxe { padding: var(--pxn-space-6) var(--pxn-space-5) 0; } }
.pxe__denied { padding: var(--pxn-space-12) 0; }
.pxe__pad { padding: var(--pxn-space-6) 0; }
.pxe__alert { margin-top: var(--pxn-space-5); }

.pxe__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6);
  padding-bottom: calc(var(--pxn-space-12) + 40px);
}
@media (max-width: 1080px) {
  .pxe__grid { grid-template-columns: minmax(0, 1fr); }
}
.pxe__main { display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.pxe__sec { scroll-margin-top: 90px; }

.pxe__row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
.pxe__row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) {
  .pxe__row2, .pxe__row3 { grid-template-columns: minmax(0, 1fr); }
}
.pxe__full { grid-column: 1 / -1; margin-top: var(--pxn-space-5); }
.pxe__mt { margin-top: var(--pxn-space-5); }
.pxe__subhead { margin: var(--pxn-space-6) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2); }
.pxe__hint { margin: 0 0 var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); line-height: var(--pxn-lh-normal); }
.pxe__err { margin: var(--pxn-space-2) 0 0; font-size: var(--pxn-fs-xs); color: var(--pxn-danger-ink); }
.pxe__warnmeta { color: var(--pxn-warning-ink); }
.pxe__emptybox {
  padding: var(--pxn-space-6); text-align: center; font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-3); border: 1px dashed var(--pxn-border); border-radius: var(--pxn-radius-md);
}

.pxe-inline { display: flex; align-items: flex-start; gap: var(--pxn-space-3); }
.pxe-inline > :deep(.pxn-input) { flex: 1 1 auto; }
.pxe-inline > .vspx,
.pxe-vs--grow { flex: 1 1 auto; min-width: 0; }

/* v-select (vue-select) integrado con px-next — el estilo del control y del menú
   vive en VsPx.vue (compartido). Aquí solo el encaje de layout. */
.pxe-vs--grow :deep(.vs__dropdown-toggle) { width: 100%; }

.pxe__opts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-6); }
@media (max-width: 720px) { .pxe__opts { grid-template-columns: minmax(0, 1fr); } }
.pxe__opt { display: flex; flex-direction: column; gap: 2px; }

/* ---- dropzone / galería ---- */
.pxe-drop {
  display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2);
  padding: var(--pxn-space-7); margin-bottom: var(--pxn-space-5);
  border: 1px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2); color: var(--pxn-ink-3); cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxe-drop:hover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pxe-drop.is-disabled { opacity: 0.5; pointer-events: none; }
.pxe-drop__title { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxe-drop__sub { font-size: var(--pxn-fs-xs); }
.pxe-drop__native { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }

.pxe-gal { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxe-gal__item {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
}
.pxe-gal__item.is-main { border-color: var(--pxn-primary-border); background: var(--pxn-primary-soft); }
.pxe-gal__handle { flex: none; color: var(--pxn-ink-3); cursor: grab; }
.pxe-gal__thumb {
  flex: none; width: 48px; height: 48px; padding: 0; overflow: hidden;
  border: 2px solid transparent; border-radius: var(--pxn-radius-sm); background: var(--pxn-surface-2); cursor: pointer;
}
.pxe-gal__thumb.is-main { border-color: var(--pxn-primary); }
.pxe-gal__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pxe-gal__meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.pxe-gal__name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---- autocomplete (combo) ---- */
.pxe-ac { position: relative; }
.pxe-ac__input {
  width: 100%; height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-4); border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md); background: var(--pxn-surface); color: var(--pxn-ink);
  font: inherit; font-size: var(--pxn-fs-body);
}
.pxe-ac__list {
  position: absolute; z-index: var(--pxn-z-dropdown, 1200); left: 0; right: 0; top: calc(100% + 4px);
  max-height: 240px; overflow-y: auto; margin: 0; padding: var(--pxn-space-3); list-style: none;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu);
}
.pxe-ac__opt { padding: var(--pxn-space-3) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxe-ac__opt:hover { background: var(--pxn-surface-2); }

/* ---- tablas px-next editables ---- */
.pxe-tbl__wrap {
  border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg);
  overflow-x: auto; background: var(--pxn-surface);
}
.pxe-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxe-tbl th {
  text-align: left; padding: var(--pxn-space-4) var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxe-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: top; }
.pxe-tbl tr:last-child td { border-bottom: 0; }
.pxe-tbl .is-right { text-align: right; }
.pxe-tbl .is-center { text-align: center; }
.pxe-tbl__empty { text-align: center; color: var(--pxn-ink-3); padding: var(--pxn-space-6); }
.pxe-tbl__name { display: block; font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxe-tbl__sub { display: block; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxe-tbl__unit { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-left: var(--pxn-space-2); }
.pxe-tbl__warn { color: var(--pxn-danger-ink); font-weight: var(--pxn-fw-semibold); }
.pxe-tbl__ok { color: var(--pxn-success-ink); }
.pxe-tbl .is-strong { font-weight: var(--pxn-fw-semibold); }

/* compact form controls inside editable tables */
.pxe-tbl :deep(.pxn-input),
.pxe-tbl :deep(.pxn-select) { height: var(--pxn-control-h-sm); font-size: var(--pxn-fs-sm); }
.pxe-tbl :deep(.pxn-input) { padding: 0 var(--pxn-space-4); }
.pxe-tbl td .pxe-inline { align-items: center; }

.pxe-total {
  display: flex; justify-content: flex-end; gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-primary-border); background: var(--pxn-primary-soft);
  border-radius: var(--pxn-radius-md); font-weight: var(--pxn-fw-semibold); color: var(--pxn-primary-ink);
}

.pxe-vimg { position: relative; display: inline-block; width: 44px; height: 44px; cursor: pointer; }
.pxe-vimg img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--pxn-radius-sm); border: 1px solid var(--pxn-border); }
.pxe-vimg input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

.pxe-oed { padding: var(--pxn-space-4); background: var(--pxn-surface-2); border-radius: var(--pxn-radius-md); }
.pxe-oed__row { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) 40px; gap: var(--pxn-space-3); align-items: end; margin-bottom: var(--pxn-space-3); }
@media (max-width: 900px) { .pxe-oed__row { grid-template-columns: minmax(0, 1fr); } }
.pxe-oed__foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--pxn-space-3); margin-top: var(--pxn-space-2); }
.pxe-oed__foot-r { display: flex; align-items: center; gap: var(--pxn-space-4); }

/* ---- rail ---- */
.pxe__rail { min-width: 0; }
@media (max-width: 1080px) {
  .pxe__rail { order: -1; }
  .pxe__rail-sticky { position: static; }
  .pxe__nav { flex-direction: row !important; overflow-x: auto; }
  .pxe__nav-title { display: none; }
  .pxe__summary, .pxe__tip { display: none; }
}
.pxe__rail-sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxe__nav { display: flex; flex-direction: column; gap: 2px; padding: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxe__nav-title { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pxe__nav-link {
  display: flex; align-items: center; gap: var(--pxn-space-3); width: 100%;
  padding: var(--pxn-space-3) var(--pxn-space-3); border: 0; background: transparent;
  border-radius: var(--pxn-radius-sm); font: inherit; font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2); text-align: left; cursor: pointer; white-space: nowrap;
}
.pxe__nav-link:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxe__nav-link.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }

.pxe__summary-pills { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); margin-bottom: var(--pxn-space-4); }
.pxe__summary-dl { display: flex; flex-direction: column; }
.pxe__summary-dl > div { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) 0; border-bottom: 1px dashed var(--pxn-border); }
.pxe__summary-dl > div:last-child { border-bottom: 0; }
.pxe__summary-dl dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxe__summary-dl dd { margin: 0; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); text-align: right; word-break: break-word; }
.pxe__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

/* ---- barra de acción fija ---- */
.pxe__actionbar {
  position: sticky; bottom: 0; left: 0; right: 0; z-index: var(--pxn-z-sticky);
  margin: 0 calc(-1 * var(--pxn-space-9)); padding: var(--pxn-space-4) var(--pxn-space-9);
  background: var(--pxn-surface); border-top: 1px solid var(--pxn-border);
  box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
}
@media (max-width: 620px) { .pxe__actionbar { margin: 0 calc(-1 * var(--pxn-space-5)); padding: var(--pxn-space-4) var(--pxn-space-5); } }
.pxe__actionbar-inner { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxe__actionbar-hint { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxe__actionbar-btns { display: flex; gap: var(--pxn-space-3); }

.pxe__spacer { height: var(--pxn-space-6); }
.pxe__grow { flex: 1; }
.pxe__qform { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
</style>
