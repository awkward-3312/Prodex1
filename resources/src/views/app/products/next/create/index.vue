<template>
  <div class="px-next pxc">
    <!--
      C2C — Crear producto px-next (preview dev-only /app/_ui/producto/nuevo).
      /app/products/store sigue sirviendo la vista legacy (Add_product.vue).
      Conserva el contrato de Add_product.vue: endpoint GET products/create,
      POST products (SIN _method), variants como blob JSON, product_gallery_json
      = {main_index}, warehouses (qte + warehouse_location_id) JSON para is_single,
      quick-add de categoría/marca/unidad/ubicación, ?duplicate=:id. Solo cambia
      la composición visual (px-next). No muta datos ni el value enviado.
    -->

    <div v-if="!can('products_add')" class="pxc__denied">
      <px-empty-state
        icon="lock"
        title="No tienes permiso para crear productos"
        description="Pide a un administrador el permiso «products_add»."
      />
    </div>

    <template v-else>
      <div v-if="isLoading" class="pxc__pad">
        <px-skeleton variant="card" :rows="8" />
      </div>

      <px-alert v-else-if="loadError" tone="danger" title="No se pudo cargar el formulario" class="pxc__alert">
        {{ loadError }}
        <template #actions><px-button size="sm" variant="secondary" @click="GetElements()">Reintentar</px-button></template>
      </px-alert>

      <validation-observer v-else ref="Create_Product">
        <form @submit.prevent="Submit_Product" enctype="multipart/form-data">
          <px-page-header
            title="Nuevo producto"
            :breadcrumbs="[{ label: 'Inventario' }, { label: 'Productos' }, { label: isDuplicate ? 'Duplicar' : 'Nuevo' }]"
          >
            <template #meta>
              <span><lucide-icon name="shapes" :size="13" /> {{ typeLabel }}</span>
              <span v-if="isDuplicate" class="pxc__dupmeta"><lucide-icon name="copy" :size="13" /> duplicando producto</span>
              <span v-if="productGalleryItems.length" class="pxc__warnmeta"><lucide-icon name="upload" :size="13" /> {{ productGalleryItems.length }} imagen(es) por subir</span>
            </template>
            <template #actions>
              <px-button variant="ghost" icon="arrow-left" type="button" @click="goCancel">Cancelar</px-button>
              <px-button variant="primary" icon="check" type="submit" :loading="SubmitProcessing">
                {{ SubmitProcessing ? 'Guardando…' : 'Guardar producto' }}
              </px-button>
            </template>
          </px-page-header>

          <div class="pxc__grid">
            <!-- ================= COLUMNA PRINCIPAL ================= -->
            <div class="pxc__main">
              <!-- ===== Información básica ===== -->
              <px-card id="section-basic" title="Información básica" class="pxc__sec">
                <div class="pxc__row2">
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
                    <div class="pxc-inline">
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
                    <div class="pxc-inline">
                      <vs-px
                        class="pxc-vs--grow"
                        :input-id="id"
                        :invalid="invalid"
                        multiple
                        :close-on-select="false"
                        :reduce="o => o.value"
                        placeholder="Elegir categorías"
                        v-model="product.assigned_category_ids"
                        :options="categories.map(c => ({ label: c.name, value: c.id }))"
                      />
                      <px-button
                        v-if="can('category')"
                        type="button" variant="secondary" size="sm" icon-only icon="plus"
                        aria-label="Añadir categoría" @click="openQuickCategoryModal"
                      />
                    </div>
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
                      <div class="pxc-inline">
                        <vs-px
                          class="pxc-vs--grow"
                          :input-id="id"
                          placeholder="Elegir marca"
                          :reduce="o => o.value"
                          v-model="product.brand_id"
                          :options="brands.map(b => ({ label: b.name, value: b.id }))"
                        />
                        <px-button
                          v-if="can('brand')"
                          type="button" variant="secondary" size="sm" icon-only icon="plus"
                          aria-label="Añadir marca" @click="openQuickBrandModal"
                        />
                      </div>
                    </template>
                  </px-field>
                </div>

                <px-field label="Descripción" class="pxc__full">
                  <template #default="{ id }">
                    <px-textarea :id="id" v-model="product.note" :rows="4" placeholder="Unas palabras sobre el producto" />
                  </template>
                </px-field>
              </px-card>

              <!-- ===== Galería de imágenes ===== -->
              <px-card id="section-gallery" title="Galería de imágenes" class="pxc__sec">
                <p class="pxc__hint">Sube imágenes del producto. Haz clic en una miniatura para marcarla como principal; arrastra para reordenar.</p>

                <label class="pxc-drop">
                  <lucide-icon name="upload" :size="22" />
                  <span class="pxc-drop__title">Añadir imágenes</span>
                  <span class="pxc-drop__sub">Formatos: JPG, PNG, GIF</span>
                  <input type="file" accept="image/*" multiple class="pxc-drop__native" @change="onProductGalleryFilesSelected" />
                </label>

                <div v-if="!productGalleryItems.length" class="pxc__emptybox">Aún no hay imágenes.</div>

                <draggable
                  v-else
                  v-model="productGalleryItems"
                  handle=".pxc-gal__handle"
                  class="pxc-gal"
                  @end="touchProductGalleryOrder"
                >
                  <div
                    v-for="(row, idx) in productGalleryItems"
                    :key="row._uid"
                    class="pxc-gal__item"
                    :class="{ 'is-main': row.is_main }"
                  >
                    <span class="pxc-gal__handle" title="Reordenar"><lucide-icon name="grip-vertical" :size="16" /></span>
                    <button
                      type="button"
                      class="pxc-gal__thumb pxn-ring"
                      :class="{ 'is-main': row.is_main }"
                      title="Marcar como principal"
                      @click="setProductGalleryMain(row)"
                    >
                      <img :src="row.url" alt="" />
                    </button>
                    <div class="pxc-gal__meta">
                      <div class="pxc-gal__name">{{ row.image_path }}</div>
                      <px-badge v-if="row.is_main" tone="success" icon="check">Imagen principal</px-badge>
                    </div>
                    <px-button type="button" variant="danger" size="sm" icon-only icon="x" aria-label="Quitar" @click="removeProductGalleryRow(idx)" />
                  </div>
                </draggable>
              </px-card>

              <!-- ===== Inventario ===== -->
              <px-card id="section-inventory" title="Inventario" class="pxc__sec">
                <div class="pxc__row2">
                  <v-field name="Tipo" label="Tipo de producto" required :rules="{ required: true }" v-slot="{ invalid, id }">
                    <vs-px
                      :input-id="id"
                      :invalid="invalid"
                      v-model="product.type"
                      @input="Selected_Type_Product"
                      :reduce="o => o.value"
                      placeholder="Elegir tipo"
                      :options="typeOptions"
                    />
                  </v-field>

                  <template v-if="product.type != 'is_service'">
                    <v-field name="Unidad" label="Unidad del producto" required :rules="{ required: true }" v-slot="{ invalid, id }">
                      <div class="pxc-inline">
                        <vs-px
                          class="pxc-vs--grow"
                          :input-id="id"
                          :invalid="invalid"
                          v-model="product.unit_id"
                          @input="Selected_Unit"
                          placeholder="Elegir unidad"
                          :reduce="o => o.value"
                          :options="units.map(u => ({ label: u.name, value: u.id }))"
                        />
                        <px-button
                          v-if="can('unit')"
                          type="button" variant="secondary" size="sm" icon-only icon="plus"
                          aria-label="Añadir unidad" @click="openQuickUnitModal"
                        />
                      </div>
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
                  <h4 class="pxc__subhead">Dimensiones</h4>
                  <div class="pxc__row3">
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

              <!-- ===== Variantes ===== -->
              <px-card v-if="product.type == 'is_variant'" id="section-variants" title="Variantes" class="pxc__sec">
                <px-field label="Nueva variante">
                  <template #default="{ id }">
                    <div class="pxc-inline">
                      <px-input :id="id" v-model="tag" placeholder="Escribe el nombre de la variante" />
                      <px-button type="button" variant="primary" icon="plus" @click="add_variant(tag)">Añadir</px-button>
                    </div>
                  </template>
                </px-field>

                <div v-if="variants.length" class="pxc-tbl__wrap pxn-scroll pxc__mt">
                  <table class="pxc-tbl">
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
                          <label class="pxc-vimg">
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
                <px-alert v-else tone="info" bare class="pxc__mt">Sin datos.</px-alert>
              </px-card>

              <!-- ===== Precios e impuestos ===== -->
              <px-card id="section-pricing" title="Precios e impuestos" class="pxc__sec">
                <div class="pxc__row2">
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
              <px-card v-if="product.type == 'is_combo'" id="section-combo" title="Productos del combo" class="pxc__sec">
                <px-field label="Buscar producto">
                  <template #default="{ id }">
                    <div class="pxc-ac">
                      <input
                        :id="id"
                        class="pxc-ac__input pxn-ring"
                        placeholder="Escanear / buscar por código o nombre"
                        @input="e => search_input = e.target.value"
                        @keyup="search(search_input)"
                        @focus="handleFocus"
                        @blur="handleBlur"
                        ref="product_autocomplete"
                      />
                      <ul v-show="focused && product_filter.length" class="pxc-ac__list pxn-scroll">
                        <li v-for="pf in product_filter" :key="pf.id" class="pxc-ac__opt" @mousedown="SearchProduct(pf)">{{ getResultValue(pf) }}</li>
                      </ul>
                    </div>
                  </template>
                </px-field>

                <div class="pxc-tbl__wrap pxn-scroll">
                  <table class="pxc-tbl">
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
                      <tr v-if="!materiels.length"><td colspan="5" class="pxc-tbl__empty">Sin datos</td></tr>
                      <tr v-for="materiel in materiels" :key="materiel.product_id">
                        <td>
                          <span class="pxc-tbl__name">{{ materiel.name }}</span>
                          <span class="pxc-tbl__sub pxn-mono">{{ materiel.code }}</span>
                        </td>
                        <td>
                          <div class="pxc-inline">
                            <px-input v-model.number="materiel.quantity" />
                            <span class="pxc-tbl__unit">{{ materiel.unit_name }}</span>
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

                <div v-if="materiels.length" class="pxc-total">
                  <span>Costo total</span>
                  <span class="pxn-num">{{ currentUser.currency }} {{ formatNumber(totalCost, priceDecimals) }}</span>
                </div>
              </px-card>

              <!-- ===== Garantía ===== -->
              <px-card id="section-warranty" title="Garantía y seguimiento" class="pxc__sec">
                <div class="pxc__row2">
                  <px-field label="Periodo de garantía">
                    <template #default="{ id }">
                      <div class="pxc-inline">
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

                  <px-field label="Términos de garantía" class="pxc__full">
                    <template #default="{ id }">
                      <px-textarea :id="id" v-model="product.warranty_terms" :rows="3" placeholder="Escribe los términos de garantía" />
                    </template>
                  </px-field>

                  <px-field v-if="product.has_guarantee" label="Periodo de garantía extendida">
                    <template #default="{ id }">
                      <div class="pxc-inline">
                        <px-input :id="id" v-model="product.guarantee_period" placeholder="0" />
                        <px-select v-model="product.guarantee_unit" :options="unitPeriodOptions" placeholder="Unidad" />
                      </div>
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Existencias iniciales por almacén ===== -->
              <px-card v-if="product.type == 'is_single'" id="section-opening-stock" title="Existencias iniciales" class="pxc__sec">
                <p class="pxc__hint">Cantidad con la que se dará de alta el producto en cada almacén. Opcional (0 si no aplica).</p>
                <div class="pxc__row2">
                  <px-field v-for="wh in warehouses" :key="wh.id" :label="wh.name">
                    <template #default="{ id }">
                      <px-input :id="id" type="number" v-model.number="product.warehouses[wh.id].qte" placeholder="0" />
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Ubicación interna por almacén ===== -->
              <px-card id="section-location" title="Ubicación interna (rack / estante)" class="pxc__sec">
                <p class="pxc__hint">Opcional. Sugiere la ubicación por defecto de este producto en cada almacén.</p>
                <div class="pxc__row2">
                  <px-field v-for="wh in warehouses" :key="wh.id" :label="wh.name">
                    <template #default="{ id }">
                      <div class="pxc-inline">
                        <vs-px
                          class="pxc-vs--grow"
                          :input-id="id"
                          :options="locationsByWarehouse[wh.id] || []"
                          label="label"
                          :reduce="o => o.id"
                          placeholder="Elegir ubicación"
                          v-model="product.warehouses[wh.id].warehouse_location_id"
                        />
                        <px-button
                          type="button" variant="secondary" size="sm" icon-only icon="plus"
                          aria-label="Añadir ubicación" @click="openQuickWarehouseLocationModal(wh.id)"
                        />
                      </div>
                    </template>
                  </px-field>
                </div>
              </px-card>

              <!-- ===== Opciones ===== -->
              <px-card id="section-options" title="Opciones" class="pxc__sec">
                <div class="pxc__opts">
                  <div v-if="show_serial_tracking" class="pxc__opt">
                    <px-check type="switch" v-model="product.is_imei">Seguimiento por serie / IMEI</px-check>
                    <small class="pxc__hint">Registra números de serie / IMEI por unidad.</small>
                  </div>
                  <div class="pxc__opt"><px-check type="switch" v-model="product.not_selling">Este producto no está a la venta</px-check></div>
                  <div class="pxc__opt"><px-check type="switch" v-model="product.is_active">Activo</px-check></div>
                  <div class="pxc__opt"><px-check type="switch" v-model="product.is_featured">Producto destacado</px-check></div>
                  <div class="pxc__opt"><px-check type="switch" v-model="product.hide_from_online_store">Ocultar de la tienda en línea</px-check></div>
                  <div class="pxc__opt"><px-check type="switch" v-model="product.is_preorder">Habilitar preventa</px-check></div>
                </div>

                <div v-if="product.is_preorder" class="pxc__row3 pxc__mt">
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
              <px-card id="section-pharmacy" title="Farmacia" class="pxc__sec">
                <div class="pxc__opt">
                  <px-check type="switch" v-model="product.is_batch_tracked">Seguimiento de lotes y caducidad</px-check>
                  <small class="pxc__hint">Activa el control de lotes y fechas de caducidad (FEFO en POS).</small>
                </div>

                <px-field v-if="product.is_batch_tracked" label="Vida útil (días)" class="pxc__mt">
                  <template #default="{ id }"><px-input :id="id" type="number" v-model="product.shelf_life_days" placeholder="Vida útil (días)" /></template>
                </px-field>

                <div class="pxc__opt pxc__mt"><px-check type="switch" v-model="product.prescription_required">Requiere receta</px-check></div>

                <div class="pxc__row2 pxc__mt">
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

              <!-- ===== Venta multi-paquete ===== -->
              <px-card
                v-if="enable_multi_pack_selling && product.type == 'is_single'"
                id="section-packs"
                title="Venta multi-paquete"
                class="pxc__sec"
              >
                <p class="pxc__hint">Vende este producto en varios paquetes. El paquete por defecto (multiplicador 1) vende en la unidad base; cada paquete adicional multiplica la cantidad base.</p>
                <div class="pxc-tbl__wrap pxn-scroll">
                  <table class="pxc-tbl">
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
                <px-button type="button" variant="secondary" size="sm" icon="plus" class="pxc__mt" @click="add_pack">Añadir paquete</px-button>
              </px-card>

              <div class="pxc__spacer" aria-hidden="true"></div>
            </div>

            <!-- ================= RAIL CONTEXTUAL ================= -->
            <aside class="pxc__rail">
              <div class="pxc__rail-sticky">
                <nav class="pxc__nav">
                  <div class="pxc__nav-title"><lucide-icon name="list" :size="14" /> En esta página</div>
                  <button
                    v-for="s in sections"
                    :key="s.id"
                    type="button"
                    class="pxc__nav-link pxn-ring"
                    :class="{ 'is-active': activeSection === s.id }"
                    @click="scrollToSection(s.id)"
                  >
                    <lucide-icon :name="s.icon" :size="14" />
                    <span>{{ s.label }}</span>
                  </button>
                </nav>

                <px-card class="pxc__summary" title="Resumen">
                  <div class="pxc__summary-pills">
                    <px-badge tone="info" icon="shapes">{{ typeLabel }}</px-badge>
                    <px-badge :tone="product.is_active ? 'success' : 'neutral'" :icon="product.is_active ? 'check' : 'minus'">
                      {{ product.is_active ? 'Activo' : 'Inactivo' }}
                    </px-badge>
                    <px-badge v-if="product.is_featured" tone="warning" icon="star">Destacado</px-badge>
                  </div>
                  <dl class="pxc__summary-dl">
                    <div><dt>Nombre</dt><dd>{{ product.name || '—' }}</dd></div>
                    <div><dt>Código</dt><dd class="pxn-mono">{{ product.code || '—' }}</dd></div>
                    <div v-if="product.type == 'is_single' || product.type == 'is_combo'"><dt>Costo</dt><dd class="pxn-num">{{ currentUser.currency }} {{ product.cost || '0.00' }}</dd></div>
                    <div v-if="product.type != 'is_variant'"><dt>Precio</dt><dd class="pxn-num">{{ currentUser.currency }} {{ product.price || '0.00' }}</dd></div>
                    <div><dt>Impuesto</dt><dd class="pxn-num">{{ product.TaxNet || 0 }} %</dd></div>
                  </dl>
                </px-card>

                <px-alert tone="info" bare class="pxc__tip">
                  <lucide-icon name="lightbulb" :size="13" />
                  El código y la unidad no podrán cambiarse cómodamente después: elige con cuidado antes de guardar.
                </px-alert>
              </div>
            </aside>
          </div>

          <!-- ===== Barra de acción fija ===== -->
          <div class="pxc__actionbar">
            <div class="pxc__actionbar-inner">
              <span class="pxc__actionbar-hint"><lucide-icon name="info" :size="14" /> Revisa el formulario y guarda tu producto.</span>
              <div class="pxc__actionbar-btns">
                <px-button variant="secondary" type="button" @click="goCancel">Cancelar</px-button>
                <px-button variant="primary" type="submit" icon="check" :loading="SubmitProcessing">
                  {{ SubmitProcessing ? 'Guardando…' : 'Guardar producto' }}
                </px-button>
              </div>
            </div>
          </div>
        </form>
      </validation-observer>

      <!-- ===== Modales (fuera del <form> y del observer principal) ===== -->
      <px-modal v-model="scanOpen" title="Escáner de código de barras" size="md">
        <qrcode-scanner :qrbox="250" :fps="10" style="width:100%;" @result="onScan" />
      </px-modal>

      <validation-observer ref="QuickCategory">
        <px-modal v-model="quickCategoryOpen" title="Añadir categoría" size="sm">
          <div class="pxc__qform" @keydown.enter.prevent="submitQuickCategory">
            <v-field name="Código categoría" label="Código de la categoría" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickCategory.code" :invalid="invalid" placeholder="Código" />
            </v-field>
            <v-field name="Nombre categoría" label="Nombre de la categoría" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickCategory.name" :invalid="invalid" placeholder="Nombre" />
            </v-field>
          </div>
          <template #footer="{ close }">
            <span class="pxc__grow" />
            <px-button variant="secondary" :disabled="quickCategorySubmitting" @click="close">Cancelar</px-button>
            <px-button variant="primary" icon="check" :loading="quickCategorySubmitting" @click="submitQuickCategory">Guardar</px-button>
          </template>
        </px-modal>
      </validation-observer>

      <validation-observer ref="QuickBrand">
        <px-modal v-model="quickBrandOpen" title="Añadir marca" size="sm">
          <div class="pxc__qform" @keydown.enter.prevent="submitQuickBrand">
            <v-field name="Nombre marca" label="Nombre de la marca" required :rules="{ required: true }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickBrand.name" :invalid="invalid" placeholder="Nombre" />
            </v-field>
            <px-field label="Descripción">
              <template #default="{ id }"><px-textarea :id="id" v-model="quickBrand.description" :rows="3" placeholder="Unas palabras" /></template>
            </px-field>
          </div>
          <template #footer="{ close }">
            <span class="pxc__grow" />
            <px-button variant="secondary" :disabled="quickBrandSubmitting" @click="close">Cancelar</px-button>
            <px-button variant="primary" icon="check" :loading="quickBrandSubmitting" @click="submitQuickBrand">Guardar</px-button>
          </template>
        </px-modal>
      </validation-observer>

      <validation-observer ref="QuickUnit">
        <px-modal v-model="quickUnitOpen" title="Añadir unidad" size="sm">
          <div class="pxc__qform" @keydown.enter.prevent="submitQuickUnit">
            <v-field name="Nombre unidad" label="Nombre" required :rules="{ required: true, max: 15 }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickUnit.name" :invalid="invalid" placeholder="Nombre" />
            </v-field>
            <v-field name="Nombre corto unidad" label="Nombre corto" required :rules="{ required: true, max: 15 }" v-slot="{ invalid, id }">
              <px-input :id="id" v-model="quickUnit.ShortName" :invalid="invalid" placeholder="Nombre corto" />
            </v-field>
            <px-field label="Unidad base (opcional)">
              <template #default="{ id }">
                <vs-px
                  :input-id="id"
                  v-model="quickUnit.base_unit"
                  @input="Selected_Base_Unit_Quick"
                  :reduce="o => o.value"
                  placeholder="Sin unidad base"
                  :options="units_base.map(u => ({ label: u.name, value: u.id }))"
                />
              </template>
            </px-field>
            <div v-if="show_operator_quick" class="pxc__row2">
              <px-field label="Operador">
                <template #default="{ id }">
                  <px-select :id="id" v-model="quickUnit.operator" :options="[
                    { value: '*', label: 'Multiplicar (×)' },
                    { value: '/', label: 'Dividir (÷)' }
                  ]" />
                </template>
              </px-field>
              <px-field label="Valor del operador">
                <template #default="{ id }"><px-input :id="id" type="number" v-model.number="quickUnit.operator_value" /></template>
              </px-field>
            </div>
          </div>
          <template #footer="{ close }">
            <span class="pxc__grow" />
            <px-button variant="secondary" :disabled="quickUnitSubmitting" @click="close">Cancelar</px-button>
            <px-button variant="primary" icon="check" :loading="quickUnitSubmitting" @click="submitQuickUnit">Guardar</px-button>
          </template>
        </px-modal>
      </validation-observer>

      <validation-observer ref="QuickWarehouseLocation">
        <px-modal v-model="quickLocOpen" title="Añadir ubicación de almacén" size="sm">
          <div class="pxc__qform" @keydown.enter.prevent="submitQuickWarehouseLocation">
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
            <span class="pxc__grow" />
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
import VField from "../edit/VField.vue";
import VsPx from "../edit/VsPx.vue";

// C2C — mismo contrato funcional que Add_product.vue: GET products/create,
// POST products, mismo FormData de Create_Product(), mismas reglas vee-validate,
// mismos quick-add, ?duplicate=:id. Solo cambia la presentación (px-next).
export default {
  name: "ProductCreateNext",
  metaInfo: { title: "Nuevo producto" },
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
      quickCategoryOpen: false,
      quickBrandOpen: false,
      quickUnitOpen: false,
      quickLocOpen: false,
      activeSection: "section-basic",
      // ---- idéntico a Add_product.vue ----
      focused: false,
      timer: null,
      search_input: "",
      product_filter: [],
      warehouses: [],
      warehouse_locations: [],
      locationsByWarehouse: {},
      tag: "",
      len: 8,
      change: false,
      isLoading: true,
      SubmitProcessing: false,
      data: new FormData(),
      show_product_gtin: true,
      show_serial_tracking: false,
      enable_multi_pack_selling: false,
      packs: [],
      categories: [],
      quickCategory: { name: "", code: "" },
      quickCategorySubmitting: false,
      quickBrand: { name: "", description: "" },
      quickBrandSubmitting: false,
      quickUnit: { name: "", ShortName: "", base_unit: "", operator: "*", operator_value: 1 },
      quickUnitSubmitting: false,
      quickWarehouseLocation: { warehouse_id: "", code: "", name: "", is_active: true },
      quickWarehouseLocationWarehouseLocked: false,
      quickWarehouseLocationSubmitting: false,
      show_operator_quick: false,
      allSubcategories: [],
      units: [],
      units_base: [],
      units_sub: [],
      brands: [],
      roles: {},
      variants: [],
      materiels: [],
      products_ing: [],
      product: {
        warehouses: {},
        type: "is_single",
        name: "",
        code: "",
        gtin: "",
        points: "",
        Type_barcode: "CODE128",
        cost: "",
        price: "",
        wholesale_price: "",
        min_price: "",
        brand_id: "",
        category_id: "",
        sub_category_id: "",
        assigned_category_ids: [],
        assigned_subcategory_ids: [],
        TaxNet: "0",
        tax_method: "1",
        discount_method: "1",
        discount: "0",
        unit_id: "",
        unit_sale_id: "",
        unit_purchase_id: "",
        stock_alert: "0",
        weight: "",
        length: "",
        width: "",
        height: "",
        image: "",
        note: "",
        is_variant: false,
        is_imei: false,
        not_selling: false,
        is_active: true,
        is_featured: false,
        hide_from_online_store: false,
        is_preorder: false,
        preorder_available_date: "",
        preorder_limit: "",
        preorder_note: "",
        is_batch_tracked: false,
        shelf_life_days: "",
        generic_name: "",
        strength: "",
        dosage_form: "",
        pack_size: "",
        manufacturer: "",
        prescription_required: false,
        drug_schedule: "",
        warranty_period: null,
        warranty_unit: "months",
        warranty_terms: "",
        has_guarantee: false,
        guarantee_period: null,
        guarantee_unit: "months"
      },
      code_exist: "",
      productGalleryItems: [],
      galleryUidSeed: 0
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
    isDuplicate() {
      return !!(this.$route && this.$route.query && this.$route.query.duplicate);
    },
    typeOptions() {
      return [
        { value: "is_single", label: "Simple" },
        { value: "is_variant", label: "Variable" },
        { value: "is_combo", label: "Combo" },
        { value: "is_service", label: "Servicio" }
      ];
    },
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
      s.push({ id: "section-warranty", label: "Garantía", icon: "shield" });
      if (this.product.type == "is_single") s.push({ id: "section-opening-stock", label: "Existencias iniciales", icon: "shopping-bag" });
      s.push(
        { id: "section-location", label: "Ubicación interna", icon: "map-pin" },
        { id: "section-options", label: "Opciones", icon: "database-zap" },
        { id: "section-pharmacy", label: "Farmacia", icon: "heart-pulse" }
      );
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
    }
  },
  created() {
    this.GetElements();

    // ?duplicate=:id → precarga desde products/:id/edit sin guardar (idéntico al legacy).
    const duplicateId = this.$route && this.$route.query ? this.$route.query.duplicate : null;
    if (duplicateId) {
      window.axios
        .get(`products/${duplicateId}/edit`)
        .then(response => {
          const p = response.data.product || {};
          this.product.type = p.type || this.product.type;
          this.product.name = p.name || "";
          this.product.code = p.code || "";
          this.product.gtin = p.gtin || "";
          this.product.points = p.points || "";
          this.product.Type_barcode = p.Type_barcode || this.product.Type_barcode;
          this.product.cost = p.cost || "";
          this.product.price = p.price || "";
          this.product.wholesale_price = p.wholesale_price || "";
          this.product.min_price = p.min_price || "";
          this.product.brand_id = p.brand_id || "";
          this.product.category_id = p.category_id || "";
          this.product.assigned_category_ids = Array.isArray(p.assigned_category_ids) ? p.assigned_category_ids.slice() : [];
          this.product.assigned_subcategory_ids = Array.isArray(p.assigned_subcategory_ids) ? p.assigned_subcategory_ids.slice() : [];
          if ((!this.product.assigned_category_ids || !this.product.assigned_category_ids.length) && p.category_id) {
            this.$set(this.product, "assigned_category_ids", [p.category_id]);
          }
          if ((!this.product.assigned_subcategory_ids || !this.product.assigned_subcategory_ids.length) && p.sub_category_id) {
            this.$set(this.product, "assigned_subcategory_ids", [p.sub_category_id]);
          }
          this.syncLegacyCategoryFields();
          this.product.TaxNet = p.TaxNet != null ? p.TaxNet : this.product.TaxNet;
          this.product.tax_method = p.tax_method != null ? String(p.tax_method) : this.product.tax_method;
          this.product.discount_method = p.discount_method != null ? String(p.discount_method) : this.product.discount_method;
          this.product.discount = p.discount != null ? String(p.discount) : this.product.discount;
          this.product.unit_id = p.unit_id || "";
          this.product.unit_sale_id = p.unit_sale_id || "";
          this.product.unit_purchase_id = p.unit_purchase_id || "";
          this.product.stock_alert = p.stock_alert != null ? String(p.stock_alert) : this.product.stock_alert;
          this.product.weight = p.weight != null ? String(p.weight) : this.product.weight;
          this.product.length = p.length != null ? String(p.length) : this.product.length;
          this.product.width = p.width != null ? String(p.width) : this.product.width;
          this.product.height = p.height != null ? String(p.height) : this.product.height;
          this.product.note = p.note || "";
          this.product.is_imei = !!p.is_imei;
          this.product.not_selling = !!p.not_selling;
          this.product.is_featured = !!p.is_featured;
          this.product.hide_from_online_store = !!p.hide_from_online_store;
          this.product.is_preorder = !!p.is_preorder;
          this.product.preorder_available_date = p.preorder_available_date || "";
          this.product.preorder_limit = p.preorder_limit != null ? p.preorder_limit : "";
          this.product.preorder_note = p.preorder_note || "";
          this.product.warranty_period = p.warranty_period != null ? p.warranty_period : null;
          this.product.warranty_unit = p.warranty_unit || this.product.warranty_unit;
          this.product.warranty_terms = p.warranty_terms || "";
          this.product.has_guarantee = !!p.has_guarantee;
          this.product.guarantee_period = p.guarantee_period != null ? p.guarantee_period : null;
          this.product.guarantee_unit = p.guarantee_unit || this.product.guarantee_unit;
          this.product.is_batch_tracked = !!p.is_batch_tracked;
          this.product.shelf_life_days = p.shelf_life_days != null && p.shelf_life_days !== "" ? p.shelf_life_days : "";
          this.product.generic_name = p.generic_name || "";
          this.product.strength = p.strength || "";
          this.product.dosage_form = p.dosage_form || "";
          this.product.pack_size = p.pack_size || "";
          this.product.manufacturer = p.manufacturer || "";
          this.product.prescription_required = !!p.prescription_required;
          this.product.drug_schedule = p.drug_schedule || "";
          if (this.product.unit_id) {
            const targetSaleId = p.unit_sale_id || "";
            const targetPurchaseId = p.unit_purchase_id || "";
            window.axios
              .get("get_sub_units_by_base?id=" + this.product.unit_id)
              .then(({ data }) => {
                this.units_sub = data;
                this.product.unit_sale_id = targetSaleId || "";
                this.product.unit_purchase_id = targetPurchaseId || "";
              })
              .catch(() => {});
          }
          if (Array.isArray(p.ProductVariant) && p.ProductVariant.length) {
            this.variants = p.ProductVariant.map((v, idx) => ({
              var_id: v.var_id != null ? v.var_id : idx + 1,
              text: v.text,
              code: v.code,
              gtin: v.gtin != null ? v.gtin : "",
              cost: v.cost,
              price: v.price,
              wholesale: v.wholesale != null ? v.wholesale : "",
              min_price: v.min_price != null ? v.min_price : "",
              image: v.image || "no-image.png",
              imagePreview: v.image && v.image !== "no-image.png" ? this.$imgUrl("products", v.image) : ""
            }));
          } else {
            this.variants = [];
          }
          if (this.product.type === "is_combo" && Array.isArray(response.data.materiels)) {
            this.materiels = response.data.materiels.slice();
          }
        })
        .catch(() => {
          // Falla en silencio: el usuario puede crear el producto manualmente.
        });
    }
  },
  mounted() {
    this._onScrollSpy = () => this.updateActiveSection();
    window.addEventListener("scroll", this._onScrollSpy, true);
  },
  beforeDestroy() {
    if (this._onScrollSpy) window.removeEventListener("scroll", this._onScrollSpy, true);
    (this.productGalleryItems || []).forEach(r => {
      if (r && r.url && r.url.indexOf("blob:") === 0) {
        try { URL.revokeObjectURL(r.url); } catch (e) { /* ignore */ }
      }
    });
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

    // ============ IDÉNTICO a Add_product.vue ============
    formatNumber(number, dec) {
      const value = (typeof number === "string" ? number : number.toString()).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec) return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    touchProductGalleryOrder() {
      (this.productGalleryItems || []).forEach((r, i) => { r.sort_order = i; });
    },
    setProductGalleryMain(row) {
      (this.productGalleryItems || []).forEach(r => { r.is_main = r === row; });
    },
    removeProductGalleryRow(index) {
      const row = this.productGalleryItems[index];
      if (row && row.url && row.url.indexOf("blob:") === 0) {
        try { URL.revokeObjectURL(row.url); } catch (e) { /* ignore */ }
      }
      this.productGalleryItems.splice(index, 1);
      this.touchProductGalleryOrder();
      if (!this.productGalleryItems.some(r => r.is_main) && this.productGalleryItems.length) {
        this.$set(this.productGalleryItems[0], "is_main", true);
      }
    },
    onProductGalleryFilesSelected(e) {
      const files = Array.from(e.target.files || []).filter(f => f.type && f.type.indexOf("image/") === 0);
      files.forEach(f => {
        this.galleryUidSeed += 1;
        this.productGalleryItems.push({
          _uid: "n-" + this.galleryUidSeed,
          url: URL.createObjectURL(f),
          image_path: f.name,
          is_main: false,
          sort_order: this.productGalleryItems.length,
          _file: f
        });
      });
      this.touchProductGalleryOrder();
      if (!this.productGalleryItems.some(r => r.is_main) && this.productGalleryItems.length) {
        this.$set(this.productGalleryItems[0], "is_main", true);
      }
      e.target.value = "";
    },

    Selected_Type_Product(value) {
      this.products_ing = [];
      if (value == "is_combo") {
        this.get_products_materiels();
      }
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
      this.$refs.Create_Product.validate().then(success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
        } else if (this.product.type == "is_variant" && this.variants.length <= 0) {
          this.makeToast("danger", "The variants array is required.", this.$t("Failed"));
        } else if (!this.validatePacks()) {
          // validatePacks already shows a toast
        } else {
          this.Create_Product();
        }
      });
    },
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
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

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },

    GetElements() {
      this.loadError = null;
      window.axios
        .get("products/create")
        .then(response => {
          this.categories = response.data.categories;
          this.allSubcategories = response.data.subcategories || [];
          this.brands = response.data.brands;
          this.units = response.data.units;
          this.warehouses = response.data.warehouses;
          this.show_product_gtin = response.data.show_product_gtin !== false;
          this.show_serial_tracking = response.data.show_serial_tracking === true;
          this.enable_multi_pack_selling = response.data.enable_multi_pack_selling === true;
          if (this.enable_multi_pack_selling) {
            this.ensureDefaultPack();
          }

          this.warehouse_locations = response.data.warehouse_locations || [];
          const byWh = {};
          (this.warehouse_locations || []).forEach(loc => {
            const wid = loc.warehouse_id;
            if (!byWh[wid]) byWh[wid] = [];
            const label = loc.name ? `${loc.code} - ${loc.name}` : loc.code;
            byWh[wid].push({ id: loc.id, label });
          });
          this.locationsByWarehouse = byWh;

          (response.data.warehouses || []).forEach(wh => {
            this.$set(this.product.warehouses, wh.id, {
              qte: wh.qte,
              warehouse_location_id: null
            });
          });

          this.isLoading = false;
        })
        .catch(error => {
          this.loadError =
            (error && error.response && error.response.data && (error.response.data.message || error.response.data.error)) ||
            (error && error.message) ||
            "Error de red.";
          setTimeout(() => { this.isLoading = false; }, 300);
        });

      this.loadBaseUnits();
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

    //------------------------------ Crear producto ------------------------------\\
    Create_Product() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.SubmitProcessing = true;

      self.syncLegacyCategoryFields();
      self.data = new FormData();

      if (self.product.type == "is_variant" && self.variants.length > 0) {
        self.product.is_variant = true;
      } else {
        self.product.is_variant = false;
      }

      if (self.materiels.length && self.product.type == "is_combo") {
        self.data.append("materiels", JSON.stringify(self.materiels));
      }

      const { assigned_category_ids, assigned_subcategory_ids, ...prodRest } = self.product;
      Object.entries(prodRest).forEach(([key, value]) => {
        self.data.append(key, value);
      });
      self.data.append("multi_category_ids", JSON.stringify(assigned_category_ids || []));
      self.data.append("multi_subcategory_ids", JSON.stringify(assigned_subcategory_ids || []));

      if (self.variants.length) {
        const variantsForJson = self.variants.map(v => {
          const { imageFile, imagePreview, ...rest } = v;
          return rest;
        });
        self.data.append("variants", JSON.stringify(variantsForJson));
        self.variants.forEach((v, i) => {
          if (v.imageFile) {
            self.data.append("variant_images[" + i + "]", v.imageFile);
          }
        });
      }

      if (self.enable_multi_pack_selling && self.product.type == "is_single") {
        self.ensureDefaultPack();
        self.data.append("packs", JSON.stringify(self.packs));
      }

      if (Object.keys(self.product.warehouses).length && self.product.type == "is_single") {
        self.data.append("warehouses", JSON.stringify(self.product.warehouses));
      }

      (self.productGalleryItems || []).forEach(r => {
        if (r && r._file) self.data.append("gallery_images[]", r._file);
      });
      const gItems = self.productGalleryItems || [];
      if (gItems.length > 0) {
        let mainIndex = gItems.findIndex(r => r && r.is_main);
        if (mainIndex < 0) mainIndex = 0;
        self.data.append("product_gallery_json", JSON.stringify({ main_index: mainIndex }));
      }

      window.axios
        .post("products", self.data)
        .then(() => {
          NProgress.done();
          self.SubmitProcessing = false;
          self.productGalleryItems.forEach(r => {
            if (r && r.url && r.url.indexOf("blob:") === 0) {
              try { URL.revokeObjectURL(r.url); } catch (e) { /* ignore */ }
            }
          });
          self.productGalleryItems = [];
          self.galleryUidSeed = 0;
          this.$router.push({ name: "index_products" });
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
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
    },

    //-------------------- Quick add: Categoría --------------------\\
    openQuickCategoryModal() {
      this.quickCategory = { name: "", code: "" };
      this.quickCategoryOpen = true;
    },
    async refreshCategories() {
      try {
        const { data } = await window.axios.get("categories?limit=-1");
        if (data && data.categories) this.categories = data.categories;
      } catch (e) { /* silent */ }
    },
    submitQuickCategory() {
      this.$refs.QuickCategory.validate().then(async success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.quickCategorySubmitting = true;
        try {
          const payload = {
            name: this.quickCategory.name,
            code: this.quickCategory.code || this.quickCategory.name
          };
          const { data } = await window.axios.post("categories", payload);
          const newCategory = data && data.category ? data.category : null;
          if (newCategory) {
            this.categories.push(newCategory);
            const arr = Array.isArray(this.product.assigned_category_ids) ? [...this.product.assigned_category_ids] : [];
            if (!arr.map(String).includes(String(newCategory.id))) arr.push(newCategory.id);
            this.$set(this.product, "assigned_category_ids", arr);
            this.syncLegacyCategoryFields();
          } else {
            await this.refreshCategories();
            const match = this.categories.find(c => c.name === payload.name && c.code === payload.code);
            if (match) {
              const arr = Array.isArray(this.product.assigned_category_ids) ? [...this.product.assigned_category_ids] : [];
              if (!arr.map(String).includes(String(match.id))) arr.push(match.id);
              this.$set(this.product, "assigned_category_ids", arr);
              this.syncLegacyCategoryFields();
            }
          }
          this.quickCategoryOpen = false;
          this.quickCategory = { name: "", code: "" };
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        } catch (e) {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        } finally {
          this.quickCategorySubmitting = false;
        }
      });
    },

    //-------------------- Quick add: Marca --------------------\\
    openQuickBrandModal() {
      this.quickBrand = { name: "", description: "" };
      this.quickBrandOpen = true;
    },
    async refreshBrands() {
      try {
        const { data } = await window.axios.get("brands?limit=-1");
        if (data && data.brands) this.brands = data.brands;
      } catch (e) { /* silent */ }
    },
    submitQuickBrand() {
      this.$refs.QuickBrand.validate().then(async success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.quickBrandSubmitting = true;
        try {
          const payload = { name: this.quickBrand.name, description: this.quickBrand.description || "" };
          const { data } = await window.axios.post("brands", payload);
          const newBrand = data && data.brand ? data.brand : null;
          if (newBrand) {
            this.brands.push(newBrand);
            this.product.brand_id = newBrand.id;
          } else {
            await this.refreshBrands();
            const match = this.brands.find(b => b.name === payload.name);
            if (match) this.product.brand_id = match.id;
          }
          this.quickBrandOpen = false;
          this.quickBrand = { name: "", description: "" };
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        } catch (e) {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        } finally {
          this.quickBrandSubmitting = false;
        }
      });
    },

    //-------------------- Quick add: Unidad --------------------\\
    openQuickUnitModal() {
      this.quickUnit = { name: "", ShortName: "", base_unit: "", operator: "*", operator_value: 1 };
      this.show_operator_quick = false;
      if (!this.units_base || this.units_base.length === 0) {
        this.loadBaseUnits();
      }
      this.quickUnitOpen = true;
    },
    loadBaseUnits() {
      window.axios
        .get("units?page=1&SortField=id&SortType=desc&limit=-1")
        .then(response => {
          if (response.data && response.data.Units_base) {
            this.units_base = response.data.Units_base;
          }
        })
        .catch(() => { /* silent */ });
    },
    Selected_Base_Unit_Quick(value) {
      this.show_operator_quick = !(value == null || value === "");
    },
    async refreshUnits() {
      try {
        const { data } = await window.axios.get("products/create");
        if (data && data.units) this.units = data.units;
      } catch (e) { /* silent */ }
      this.loadBaseUnits();
    },
    submitQuickUnit() {
      this.$refs.QuickUnit.validate().then(async success => {
        if (!success) {
          this.makeToast("danger", this.$t("Please_fill_the_form_correctly"), this.$t("Failed"));
          return;
        }
        this.quickUnitSubmitting = true;
        try {
          const base_unit = this.quickUnit.base_unit || "";
          const payload = {
            name: this.quickUnit.name,
            ShortName: this.quickUnit.ShortName,
            base_unit: base_unit,
            operator: this.quickUnit.operator || "*",
            operator_value: this.quickUnit.operator_value || 1
          };
          await window.axios.post("units", payload);
          await this.refreshUnits();
          await this.$nextTick();
          let match = this.units.find(u => u.name === payload.name && u.ShortName === payload.ShortName);
          if (!match) {
            await new Promise(resolve => setTimeout(resolve, 300));
            await this.refreshUnits();
            await this.$nextTick();
            match = this.units.find(u => u.name === payload.name && u.ShortName === payload.ShortName);
          }
          if (match) {
            this.product.unit_id = match.id;
            this.Selected_Unit(match.id);
          }
          this.quickUnitOpen = false;
          this.quickUnit = { name: "", ShortName: "", base_unit: "", operator: "*", operator_value: 1 };
          this.show_operator_quick = false;
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        } catch (e) {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        } finally {
          this.quickUnitSubmitting = false;
        }
      });
    },

    //-------------------- Quick add: Ubicación de almacén --------------------\\
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
            this.warehouse_locations.push(newLoc);
            if (!this.locationsByWarehouse[wid]) this.$set(this.locationsByWarehouse, wid, []);
            this.locationsByWarehouse[wid].push({ id: newLoc.id, label });
            if (this.product && this.product.warehouses && this.product.warehouses[wid]) {
              this.product.warehouses[wid].warehouse_location_id = newLoc.id;
            }
          }
          this.quickLocOpen = false;
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        } catch (e) {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        } finally {
          this.quickWarehouseLocationSubmitting = false;
        }
      });
    }
  }
};
</script>

<style lang="scss" src="@/assets/styles/sass/px-next/production.scss"></style>

<style lang="scss" scoped>
.pxc {
  min-height: 100%;
  background: var(--pxn-bg);
  padding: var(--pxn-space-8) var(--pxn-space-9) 0;
}
@media (max-width: 620px) { .pxc { padding: var(--pxn-space-6) var(--pxn-space-5) 0; } }
.pxc__denied { padding: var(--pxn-space-12) 0; }
.pxc__pad { padding: var(--pxn-space-6) 0; }
.pxc__alert { margin-top: var(--pxn-space-5); }

.pxc__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: var(--pxn-space-6);
  margin-top: var(--pxn-space-6);
  padding-bottom: calc(var(--pxn-space-12) + 40px);
}
@media (max-width: 1080px) { .pxc__grid { grid-template-columns: minmax(0, 1fr); } }
.pxc__main { display: flex; flex-direction: column; gap: var(--pxn-space-6); min-width: 0; }
.pxc__sec { scroll-margin-top: 90px; }

.pxc__row2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-5); }
.pxc__row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--pxn-space-5); }
@media (max-width: 720px) { .pxc__row2, .pxc__row3 { grid-template-columns: minmax(0, 1fr); } }
.pxc__full { grid-column: 1 / -1; margin-top: var(--pxn-space-5); }
.pxc__mt { margin-top: var(--pxn-space-5); }
.pxc__subhead { margin: var(--pxn-space-6) 0 var(--pxn-space-3); font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-semibold); color: var(--pxn-ink-2); }
.pxc__hint { margin: 0 0 var(--pxn-space-4); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); line-height: var(--pxn-lh-normal); }
.pxc__warnmeta { color: var(--pxn-warning-ink); }
.pxc__dupmeta { color: var(--pxn-info-ink); }
.pxc__emptybox {
  padding: var(--pxn-space-6); text-align: center; font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-3); border: 1px dashed var(--pxn-border); border-radius: var(--pxn-radius-md);
}

.pxc-inline { display: flex; align-items: flex-start; gap: var(--pxn-space-3); }
.pxc-inline > :deep(.pxn-input) { flex: 1 1 auto; }
.pxc-inline > .vspx,
.pxc-vs--grow { flex: 1 1 auto; min-width: 0; }
.pxc-vs--grow :deep(.vs__dropdown-toggle) { width: 100%; }

.pxc__opts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--pxn-space-4) var(--pxn-space-6); }
@media (max-width: 720px) { .pxc__opts { grid-template-columns: minmax(0, 1fr); } }
.pxc__opt { display: flex; flex-direction: column; gap: 2px; }

/* ---- dropzone / galería ---- */
.pxc-drop {
  display: flex; flex-direction: column; align-items: center; gap: var(--pxn-space-2);
  padding: var(--pxn-space-7); margin-bottom: var(--pxn-space-5);
  border: 1px dashed var(--pxn-border-strong); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface-2); color: var(--pxn-ink-3); cursor: pointer;
  transition: border-color var(--pxn-dur-1) var(--pxn-ease), background var(--pxn-dur-1) var(--pxn-ease);
}
.pxc-drop:hover { border-color: var(--pxn-primary); background: var(--pxn-primary-soft); }
.pxc-drop__title { font-size: var(--pxn-fs-body); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxc-drop__sub { font-size: var(--pxn-fs-xs); }
.pxc-drop__native { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }

.pxc-gal { display: flex; flex-direction: column; gap: var(--pxn-space-3); }
.pxc-gal__item {
  display: flex; align-items: center; gap: var(--pxn-space-3);
  padding: var(--pxn-space-3); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-md);
  background: var(--pxn-surface);
}
.pxc-gal__item.is-main { border-color: var(--pxn-primary-border); background: var(--pxn-primary-soft); }
.pxc-gal__handle { flex: none; color: var(--pxn-ink-3); cursor: grab; }
.pxc-gal__thumb {
  flex: none; width: 48px; height: 48px; padding: 0; overflow: hidden;
  border: 2px solid transparent; border-radius: var(--pxn-radius-sm); background: var(--pxn-surface-2); cursor: pointer;
}
.pxc-gal__thumb.is-main { border-color: var(--pxn-primary); }
.pxc-gal__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pxc-gal__meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.pxc-gal__name { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---- autocomplete (combo) ---- */
.pxc-ac { position: relative; }
.pxc-ac__input {
  width: 100%; height: var(--pxn-control-h-md);
  padding: 0 var(--pxn-space-4); border: 1px solid var(--pxn-border-control);
  border-radius: var(--pxn-radius-md); background: var(--pxn-surface); color: var(--pxn-ink);
  font: inherit; font-size: var(--pxn-fs-body);
}
.pxc-ac__list {
  position: absolute; z-index: var(--pxn-z-dropdown, 1200); left: 0; right: 0; top: calc(100% + 4px);
  max-height: 240px; overflow-y: auto; margin: 0; padding: var(--pxn-space-3); list-style: none;
  background: var(--pxn-surface); border: 1px solid var(--pxn-border);
  border-radius: var(--pxn-radius-md); box-shadow: var(--pxn-shadow-menu);
}
.pxc-ac__opt { padding: var(--pxn-space-3) var(--pxn-space-4); border-radius: var(--pxn-radius-sm); font-size: var(--pxn-fs-body); cursor: pointer; }
.pxc-ac__opt:hover { background: var(--pxn-surface-2); }

/* ---- tablas px-next editables ---- */
.pxc-tbl__wrap { border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); overflow-x: auto; background: var(--pxn-surface); }
.pxc-tbl { width: 100%; border-collapse: collapse; font-size: var(--pxn-fs-body); }
.pxc-tbl th {
  text-align: left; padding: var(--pxn-space-4);
  font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3);
  background: var(--pxn-surface-2); border-bottom: 1px solid var(--pxn-border); white-space: nowrap;
}
.pxc-tbl td { padding: var(--pxn-space-3) var(--pxn-space-4); border-bottom: 1px solid var(--pxn-border); vertical-align: top; }
.pxc-tbl tr:last-child td { border-bottom: 0; }
.pxc-tbl .is-right { text-align: right; }
.pxc-tbl .is-center { text-align: center; }
.pxc-tbl__empty { text-align: center; color: var(--pxn-ink-3); padding: var(--pxn-space-6); }
.pxc-tbl__name { display: block; font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); }
.pxc-tbl__sub { display: block; font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); }
.pxc-tbl__unit { font-size: var(--pxn-fs-xs); color: var(--pxn-ink-3); margin-left: var(--pxn-space-2); }
.pxc-tbl .is-strong { font-weight: var(--pxn-fw-semibold); }
.pxc-tbl :deep(.pxn-input),
.pxc-tbl :deep(.pxn-select) { height: var(--pxn-control-h-sm); font-size: var(--pxn-fs-sm); }
.pxc-tbl :deep(.pxn-input) { padding: 0 var(--pxn-space-4); }
.pxc-tbl td .pxc-inline { align-items: center; }

.pxc-total {
  display: flex; justify-content: flex-end; gap: var(--pxn-space-5);
  margin-top: var(--pxn-space-4); padding: var(--pxn-space-4) var(--pxn-space-5);
  border: 1px solid var(--pxn-primary-border); background: var(--pxn-primary-soft);
  border-radius: var(--pxn-radius-md); font-weight: var(--pxn-fw-semibold); color: var(--pxn-primary-ink);
}

.pxc-vimg { position: relative; display: inline-block; width: 44px; height: 44px; cursor: pointer; }
.pxc-vimg img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--pxn-radius-sm); border: 1px solid var(--pxn-border); }
.pxc-vimg input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

/* ---- rail ---- */
.pxc__rail { min-width: 0; }
@media (max-width: 1080px) {
  .pxc__rail { order: -1; }
  .pxc__rail-sticky { position: static; }
  .pxc__nav { flex-direction: row !important; overflow-x: auto; }
  .pxc__nav-title { display: none; }
  .pxc__summary, .pxc__tip { display: none; }
}
.pxc__rail-sticky { position: sticky; top: var(--pxn-space-6); display: flex; flex-direction: column; gap: var(--pxn-space-5); }
.pxc__nav { display: flex; flex-direction: column; gap: 2px; padding: var(--pxn-space-4); border: 1px solid var(--pxn-border); border-radius: var(--pxn-radius-lg); background: var(--pxn-surface); }
.pxc__nav-title { display: flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-xs); font-weight: var(--pxn-fw-semibold); text-transform: uppercase; letter-spacing: 0.04em; color: var(--pxn-ink-3); margin-bottom: var(--pxn-space-2); }
.pxc__nav-link {
  display: flex; align-items: center; gap: var(--pxn-space-3); width: 100%;
  padding: var(--pxn-space-3); border: 0; background: transparent;
  border-radius: var(--pxn-radius-sm); font: inherit; font-size: var(--pxn-fs-sm);
  color: var(--pxn-ink-2); text-align: left; cursor: pointer; white-space: nowrap;
}
.pxc__nav-link:hover { background: var(--pxn-surface-2); color: var(--pxn-ink); }
.pxc__nav-link.is-active { background: var(--pxn-primary-soft); color: var(--pxn-primary-ink); font-weight: var(--pxn-fw-medium); }

.pxc__summary-pills { display: flex; flex-wrap: wrap; gap: var(--pxn-space-2); margin-bottom: var(--pxn-space-4); }
.pxc__summary-dl { display: flex; flex-direction: column; }
.pxc__summary-dl > div { display: flex; justify-content: space-between; gap: var(--pxn-space-4); padding: var(--pxn-space-3) 0; border-bottom: 1px dashed var(--pxn-border); }
.pxc__summary-dl > div:last-child { border-bottom: 0; }
.pxc__summary-dl dt { font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxc__summary-dl dd { margin: 0; font-size: var(--pxn-fs-sm); font-weight: var(--pxn-fw-medium); color: var(--pxn-ink); text-align: right; word-break: break-word; }
.pxc__tip :deep(svg) { vertical-align: -2px; margin-right: var(--pxn-space-2); }

/* ---- barra de acción fija ---- */
.pxc__actionbar {
  position: sticky; bottom: 0; left: 0; right: 0; z-index: var(--pxn-z-sticky);
  margin: 0 calc(-1 * var(--pxn-space-9)); padding: var(--pxn-space-4) var(--pxn-space-9);
  background: var(--pxn-surface); border-top: 1px solid var(--pxn-border);
  box-shadow: 0 -4px 16px rgba(16, 24, 40, 0.06);
}
@media (max-width: 620px) { .pxc__actionbar { margin: 0 calc(-1 * var(--pxn-space-5)); padding: var(--pxn-space-4) var(--pxn-space-5); } }
.pxc__actionbar-inner { display: flex; align-items: center; justify-content: space-between; gap: var(--pxn-space-4); flex-wrap: wrap; }
.pxc__actionbar-hint { display: inline-flex; align-items: center; gap: var(--pxn-space-2); font-size: var(--pxn-fs-sm); color: var(--pxn-ink-3); }
.pxc__actionbar-btns { display: flex; gap: var(--pxn-space-3); }

.pxc__spacer { height: var(--pxn-space-6); }
.pxc__grow { flex: 1; }
.pxc__qform { display: flex; flex-direction: column; gap: var(--pxn-space-4); }
</style>
