<template>
  <div>
    <b-card class="guide-card shadow-sm">
      <template #header>
        <div class="d-flex align-items-center">
          <lucide-icon class="mr-2 text-info" name="book" />
          <h5 class="mb-0 font-weight-bold">Guía de sincronización con Shopify</h5>
        </div>
      </template>
      <b-card-text>
        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="key" />
            1. Crea una aplicación personalizada y obtén las credenciales
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-primary" name="mouse-pointer" />En el administrador de Shopify: Configuración → Aplicaciones y canales de venta → Desarrollar aplicaciones → Crear una aplicación.</li>
            <li><lucide-icon class="mr-2 text-primary" name="shield" />Configura los permisos de Admin API: <code>read_products, write_products, read_inventory, write_inventory, read_customers, write_customers, read_orders, write_orders, read_locations</code>.</li>
            <li><lucide-icon class="mr-2 text-primary" name="key" />Instala la aplicación y copia el <strong>token de acceso de Admin API</strong> (<code>shpat_...</code>); Shopify lo muestra una sola vez.</li>
            <li><lucide-icon class="mr-2 text-primary" name="lock" />Copia también la <strong>clave secreta de la API</strong>; PRODEX la utiliza para verificar las firmas de los webhooks.</li>
            <li><lucide-icon class="mr-2 text-primary" name="globe" />Dominio de la tienda: usa tu dominio <code>*.myshopify.com</code> (por ejemplo, <code>mi-tienda.myshopify.com</code>).</li>
          </ul>
        </div>

        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="settings" />
            2. Conecta la tienda
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-success" name="plus" />En la pestaña Tiendas, agrega la tienda con su dominio, token de acceso y clave secreta de API; después usa Probar conexión.</li>
            <li><lucide-icon class="mr-2 text-success" name="map-pin" />Edita la tienda para seleccionar la ubicación de Shopify y el almacén de PRODEX que se usará para inventario y pedidos importados. Puedes agregar varias tiendas; cada sincronización se limita a la tienda seleccionada en el encabezado.</li>
            <li><lucide-icon class="mr-2 text-success" name="webhook" />Presiona Registrar webhooks para que los pedidos nuevos, cambios de clientes y actualizaciones de productos lleguen automáticamente a PRODEX. El sitio debe ser accesible públicamente mediante HTTPS.</li>
          </ul>
        </div>

        <div class="guide-section mb-4">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-primary" name="arrow-right-left" />
            3. Primera sincronización — orden recomendado
          </h6>
          <ul class="guide-list">
            <li><lucide-icon class="mr-2 text-primary" name="download" />Si la tienda de Shopify ya tiene productos, ejecuta primero <strong>Importar productos</strong>: PRODEX enlaza los artículos existentes por SKU en lugar de crear duplicados.</li>
            <li><lucide-icon class="mr-2 text-primary" name="upload" />Luego ejecuta <strong>Enviar productos</strong> para publicar los productos que todavía no estén sincronizados.</li>
            <li><lucide-icon class="mr-2 text-primary" name="package" />Ejecuta <strong>Sincronizar inventario</strong> para publicar las existencias en la ubicación vinculada.</li>
            <li><lucide-icon class="mr-2 text-primary" name="user" />Sincroniza los clientes en la dirección que necesites y luego <strong>Importa pedidos</strong>; cada pedido de Shopify se convierte en una venta de PRODEX y descuenta inventario.</li>
          </ul>
        </div>

        <div class="guide-section">
          <h6 class="guide-title">
            <lucide-icon class="mr-2 text-info" name="info" />
            Notas
          </h6>
          <ul class="guide-list mb-0">
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Mantén los SKU iguales entre PRODEX y Shopify; el SKU se utiliza como respaldo para vincular productos y líneas de los pedidos.</li>
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Cambiar el dominio de una tienda restablece sus vinculaciones; los artículos deberán sincronizarse nuevamente con la nueva tienda.</li>
            <li><lucide-icon class="mr-2 text-warning" name="alert-circle" />Los pedidos importados conservan los totales y el estado de pago de Shopify. Los reembolsos y cambios posteriores realizados en Shopify actualizan los estados de la venta mediante el webhook <code>orders/updated</code>.</li>
          </ul>
        </div>
      </b-card-text>
    </b-card>
  </div>
</template>

<script>
export default {
  created() {
    this.$emit('ready');
  }
};
</script>

<style scoped>
.guide-card {
  border-radius: 12px;
  border: none;
}

.guide-card ::v-deep .card-header {
  background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
  border-bottom: 2px solid #e9ecef;
  padding: 1.25rem 1.5rem;
  border-radius: 12px 12px 0 0;
}

.guide-section {
  padding-bottom: 1rem;
  border-bottom: 1px solid #f0f0f0;
}

.guide-section:last-child {
  border-bottom: none;
}

.guide-title {
  font-weight: 700;
  color: #2d3748;
  margin-bottom: 0.75rem;
  font-size: 15px;
  display: flex;
  align-items: center;
}

.guide-list {
  list-style: none;
  padding-left: 0;
  margin-bottom: 0;
}

.guide-list li {
  padding: 0.5rem 0;
  display: flex;
  align-items: flex-start;
  color: #4a5568;
  line-height: 1.6;
}

.guide-list code {
  background: #e2e8f0;
  color: #1e293b;
  padding: 0.2em 0.4em;
  border-radius: 4px;
  font-size: 12px;
  font-family: 'Courier New', monospace;
}
</style>