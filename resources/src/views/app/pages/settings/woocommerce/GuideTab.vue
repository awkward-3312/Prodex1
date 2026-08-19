<template>
  <div class="woo-guide">
    <b-alert variant="info" show class="info-alert-modern mb-4">
      <div class="d-flex align-items-start">
        <lucide-icon class="mr-3 mt-1" name="info" />
        <div>
          Esta guía explica cómo conectar tu tienda WooCommerce y mantener sincronizados productos,
          inventario, clientes y pedidos. La primera vez, sigue los pasos en el orden indicado.
        </div>
      </div>
    </b-alert>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="rocket" />
        <strong>Inicio rápido (3 pasos)</strong>
      </div>
      <div class="guide-steps">
        <div class="guide-step">
          <div class="guide-step-num">1</div>
          <div>
            <div class="guide-step-title">Conecta tu tienda</div>
            <div class="guide-step-text">
              Abre la pestaña <strong>Configuración</strong>, ingresa la URL de la tienda y las claves
              de la API de WooCommerce. Luego presiona <strong>Probar conexión</strong> hasta que el
              indicador superior muestre <b-badge variant="success">Conectado</b-badge>.
            </div>
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">2</div>
          <div>
            <div class="guide-step-title">Sincroniza primero categorías y marcas</div>
            <div class="guide-step-text">
              En las pestañas <strong>Categorías</strong> y <strong>Marcas</strong>, ejecuta la
              sincronización para que los productos se asignen correctamente. Hazlo antes de
              sincronizar productos.
            </div>
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">3</div>
          <div>
            <div class="guide-step-title">Sincroniza productos e inventario</div>
            <div class="guide-step-text">
              Usa la pestaña <strong>Productos</strong> para enviar o importar productos y después
              <strong>Inventario</strong> para mantener actualizadas las existencias.
            </div>
          </div>
        </div>
      </div>
    </b-card>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="layout-grid" />
        <strong>Qué hace cada pestaña</strong>
      </div>
      <div class="guide-grid">
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="settings" />
          <div>
            <div class="guide-item-title">Configuración</div>
            <div class="guide-item-text">Conecta la tienda y verifica el estado de la conexión.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="barcode" />
          <div>
            <div class="guide-item-title">Productos</div>
            <div class="guide-item-text">Envía productos a WooCommerce o impórtalos a PRODEX.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="package" />
          <div>
            <div class="guide-item-title">Inventario</div>
            <div class="guide-item-text">Envía las existencias actuales a WooCommerce.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="folder" />
          <div>
            <div class="guide-item-title">Categorías</div>
            <div class="guide-item-text">Relaciona las categorías de PRODEX con las de WooCommerce.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="tag" />
          <div>
            <div class="guide-item-title">Marcas</div>
            <div class="guide-item-text">Relaciona las marcas de PRODEX con las de WooCommerce.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="user" />
          <div>
            <div class="guide-item-title">Clientes</div>
            <div class="guide-item-text">Sincroniza clientes en cualquiera de las dos direcciones.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="shopping-bag" />
          <div>
            <div class="guide-item-title">Pedidos</div>
            <div class="guide-item-text">Importa pedidos de WooCommerce a PRODEX como ventas.</div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon" name="clipboard-list" />
          <div>
            <div class="guide-item-title">Registros</div>
            <div class="guide-item-text">Consulta qué ocurrió en cada sincronización, incluidos los errores.</div>
          </div>
        </div>
      </div>
    </b-card>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="arrow-left-right" />
        <strong>Enviar e importar: ¿cuál es la diferencia?</strong>
      </div>
      <div class="guide-grid">
        <div class="guide-item">
          <lucide-icon class="guide-item-icon text-success" name="chevron-up" />
          <div>
            <div class="guide-item-title">Enviar (PRODEX → WooCommerce)</div>
            <div class="guide-item-text">
              Envía productos e inventario de PRODEX hacia la tienda en línea. Úsalo cuando PRODEX
              sea la fuente principal de información.
            </div>
          </div>
        </div>
        <div class="guide-item">
          <lucide-icon class="guide-item-icon text-primary" name="chevron-down" />
          <div>
            <div class="guide-item-title">Importar (WooCommerce → PRODEX)</div>
            <div class="guide-item-text">
              Trae productos, clientes y pedidos de la tienda hacia PRODEX. Úsalo para incorporar
              una tienda que ya tiene información.
            </div>
          </div>
        </div>
      </div>
      <b-alert variant="warning" show class="mt-3 mb-0">
        <div class="d-flex align-items-start">
          <lucide-icon class="mr-2 mt-1" name="lightbulb" />
          <div>
            <strong>Consejo:</strong> los productos se relacionan por <strong>SKU</strong>. Asegúrate
            de que cada producto tenga un SKU/código único en ambos sistemas para evitar duplicados.
          </div>
        </div>
      </b-alert>
    </b-card>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="activity" />
        <strong>Cómo funciona el progreso de sincronización</strong>
      </div>
      <ul class="guide-list">
        <li>Las sincronizaciones grandes se procesan en segundo plano por <strong>lotes</strong>; es normal que la barra de progreso haga pausas breves entre lotes.</li>
        <li>Mantén esta página abierta mientras se ejecuta una sincronización si no hay un worker de colas configurado en el servidor.</li>
        <li>Puedes detener una sincronización con el botón <strong>Detener</strong>; los artículos que ya fueron sincronizados se conservan.</li>
        <li>Las imágenes pueden hacer más lenta la sincronización porque cada archivo debe subirse a la tienda.</li>
      </ul>
    </b-card>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="server-cog" />
        <strong>Para administradores: mantener las sincronizaciones funcionando automáticamente</strong>
      </div>

      <b-alert variant="warning" show class="mb-4">
        <div class="d-flex align-items-start">
          <lucide-icon class="mr-2 mt-1" name="alert-triangle" />
          <div>
            Las sincronizaciones grandes se procesan por lotes en segundo plano. Debe existir un
            <strong>worker de colas</strong> en ejecución; de lo contrario, la sincronización puede
            detenerse en <code>queued_next_batch</code>. Puedes usar una de las siguientes opciones.
          </div>
        </div>
      </b-alert>

      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="success" class="guide-option-badge">Recomendado</b-badge>
          <span class="guide-option-title">Opción A — Worker persistente con Supervisor</span>
        </div>
        <div class="guide-option-text">
          Es la mejor opción para un VPS o servidor propio. Supervisor mantiene el worker activo y
          lo reinicia automáticamente. PRODEX incluye una configuración preparada en
          <code>deploy/supervisor/prodex-queue-worker.conf</code>.
        </div>
        <pre class="guide-code"><code>php artisan queue:work database \
  --queue=woocommerce-sync,woocommerce-stock,default \
  --sleep=1 --tries=1 --timeout=1200 --max-time=3600</code></pre>
        <div class="guide-option-text">Instalación y arranque:</div>
        <pre class="guide-code"><code>sudo cp deploy/supervisor/prodex-queue-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread &amp;&amp; sudo supervisorctl update
sudo supervisorctl start prodex-queue-worker:*</code></pre>
        <div class="guide-option-note">
          <lucide-icon class="mr-1" name="info" /> Después de desplegar código nuevo, ejecuta
          <code>php artisan queue:restart</code> para que el worker cargue los cambios.
        </div>
      </div>

      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="primary" class="guide-option-badge">Hosting compartido</b-badge>
          <span class="guide-option-title">Opción B — Programador Cron</span>
        </div>
        <div class="guide-option-text">
          Si no tienes acceso root o Supervisor, configura una entrada cron que ejecute el
          programador de Laravel cada minuto. La aplicación procesa las colas desde allí.
        </div>
        <pre class="guide-code"><code>* * * * * cd /ruta-de-la-app &amp;&amp; php artisan schedule:run >> /dev/null 2>&amp;1</code></pre>
        <div class="guide-option-note">
          <lucide-icon class="mr-1" name="info" /> Es más lento que Supervisor, pero no requiere software adicional.
        </div>
      </div>

      <div class="guide-option">
        <div class="guide-option-head">
          <b-badge variant="secondary" class="guide-option-badge">Manual</b-badge>
          <span class="guide-option-title">Opción C — Sin configuración adicional</span>
        </div>
        <div class="guide-option-text">
          Sin worker ni cron, las sincronizaciones pueden avanzar mientras esta página permanezca
          abierta. Es útil para sincronizaciones pequeñas y ocasionales. También puedes ejecutar:
        </div>
        <pre class="guide-code"><code>php artisan woocommerce:sync --scope=all
php artisan woocommerce:sync --scope=products --only-unsynced</code></pre>
      </div>

      <div class="guide-option-note mt-2">
        <lucide-icon class="mr-1" name="book-open" /> La guía técnica completa está en
        <code>deploy/QUEUE_WORKER_SETUP.md</code>.
      </div>
    </b-card>

    <b-card class="action-card shadow-sm mb-4">
      <div class="d-flex align-items-center mb-3">
        <lucide-icon class="mr-2" name="life-buoy" />
        <strong>Solución de problemas</strong>
      </div>
      <div class="guide-faq">
        <div class="guide-faq-item">
          <div class="guide-faq-q"><lucide-icon class="mr-2" name="help-circle" />El indicador de conexión muestra “Desconectado”</div>
          <div class="guide-faq-a">Revisa la URL y las claves de API en Configuración. La clave y el secreto deben tener permisos de <strong>Lectura/Escritura</strong> y la tienda debe ser accesible desde internet.</div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q"><lucide-icon class="mr-2" name="help-circle" />La sincronización se detuvo después de pocos productos</div>
          <div class="guide-faq-a">Para sincronizaciones grandes o desatendidas, verifica que el worker de colas esté activo en el servidor.</div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q"><lucide-icon class="mr-2" name="help-circle" />Las imágenes no aparecen en WooCommerce</div>
          <div class="guide-faq-a">La carga de imágenes requiere un <strong>usuario</strong> de WordPress y una <strong>contraseña de aplicación</strong> configurados en la pestaña Configuración.</div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q"><lucide-icon class="mr-2" name="help-circle" />Aparecen productos duplicados en WooCommerce</div>
          <div class="guide-faq-a">Normalmente ocurre cuando los SKU no coinciden. Corrige los SKU y luego restablece la sincronización de productos antes de ejecutarla otra vez.</div>
        </div>
        <div class="guide-faq-item">
          <div class="guide-faq-q"><lucide-icon class="mr-2" name="help-circle" />Algo salió mal, ¿dónde reviso?</div>
          <div class="guide-faq-a">Abre la pestaña <strong>Registros</strong>. Cada sincronización guarda lo que realizó y los errores encontrados.</div>
        </div>
      </div>
    </b-card>

    <b-alert variant="secondary" show class="mb-0">
      <div class="d-flex align-items-start">
        <lucide-icon class="mr-2 mt-1" name="shield-check" />
        <div><strong>Es seguro reintentar:</strong> ejecutar nuevamente una sincronización no duplica los artículos ya vinculados; actualiza lo existente o agrega lo que falta.</div>
      </div>
    </b-alert>
  </div>
</template>

<script>
export default {
  name: 'GuideTab',
  mounted() {
    this.$emit('ready');
  },
};
</script>

<style scoped>
.guide-steps {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.guide-step {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
}
.guide-step-num {
  flex: 0 0 auto;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #7f54b3;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.9rem;
}
.guide-step-title {
  font-weight: 600;
  margin-bottom: 2px;
}
.guide-step-text {
  color: #6b7280;
  font-size: 0.9rem;
}
.guide-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}
.guide-item {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.85rem;
  border: 1px solid #eef0f4;
  border-radius: 10px;
  background: #fbfbfd;
}
.guide-item-icon {
  flex: 0 0 auto;
  margin-top: 2px;
  color: #7f54b3;
}
.guide-item-title {
  font-weight: 600;
  margin-bottom: 2px;
}
.guide-item-text {
  color: #6b7280;
  font-size: 0.88rem;
}
.guide-list {
  margin: 0;
  padding-left: 1.1rem;
}
.guide-list li {
  margin-bottom: 0.5rem;
  color: #4b5563;
}
.guide-faq {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}
.guide-faq-q {
  display: flex;
  align-items: center;
  font-weight: 600;
}
.guide-faq-a {
  color: #6b7280;
  font-size: 0.9rem;
  margin-top: 3px;
  padding-left: 1.6rem;
}
.guide-option {
  padding: 1rem 1.1rem;
  border: 1px solid #eef0f4;
  border-left: 3px solid #7f54b3;
  border-radius: 10px;
  background: #fbfbfd;
  margin-bottom: 1rem;
}
.guide-option:last-of-type {
  margin-bottom: 0;
}
.guide-option-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.guide-option-badge {
  font-size: 0.7rem;
}
.guide-option-title {
  font-weight: 600;
}
.guide-option-text {
  color: #4b5563;
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
}
.guide-code {
  background: #1e1e2e;
  color: #e5e7eb;
  padding: 0.75rem 0.9rem;
  border-radius: 8px;
  font-size: 0.82rem;
  line-height: 1.5;
  overflow-x: auto;
  margin-bottom: 0.6rem;
  white-space: pre;
}
.guide-code code {
  background: transparent;
  color: inherit;
  padding: 0;
}
.guide-option-note {
  color: #6b7280;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.guide-option-note code,
.guide-option-text code,
.guide-faq-a code {
  background: #f1edf8;
  color: #7f54b3;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 0.85em;
}
</style>
