<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $connection = 'central';

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'slug',
        'price',
        'yearly_price',
        'billing_interval',
        'limits',
        'features',
        'is_active',
        'is_private',
        'is_trial',
        'trial_days',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'limits'       => 'array',
        'features'     => 'array',
        'is_active'    => 'boolean',
        'is_private'   => 'boolean',
        'is_trial'     => 'boolean',
        'trial_days'   => 'integer',
    ];

    /**
     * Todos los límites de uso configurables y sus metadatos.
     *
     * `limits` y `features` tienen funciones diferentes:
     *
     * - `limits` es un mapa de cantidades numéricas:
     *   ['max_products' => 500, ...].
     *   -1 (o una clave inexistente) significa ilimitado.
     *
     * - `features` es una lista de funciones activadas:
     *   ['pos', 'hrm'].
     *   Las funciones que se pueden activar o desactivar pertenecen aquí.
     *
     * WhatsApp se maneja como un límite mensual y no como una función:
     * todos los planes pueden enviar mensajes, pero cada plan tiene un
     * límite mensual diferente.
     */
    public const AVAILABLE_LIMITS = [
        'max_products' => [
            'label' => 'Productos',
            'icon' => 'bi-box-seam',
            'model' => \App\Models\Product::class,
        ],

        'max_users' => [
            'label' => 'Usuarios',
            'icon' => 'bi-people',
            'model' => \App\Models\User::class,
        ],

        'max_warehouses' => [
            'label' => 'Almacenes',
            'icon' => 'bi-building',
            'model' => \App\Models\Warehouse::class,
        ],

        'max_customers' => [
            'label' => 'Clientes',
            'icon' => 'bi-person-lines-fill',
            'model' => \App\Models\Client::class,
        ],

        'max_suppliers' => [
            'label' => 'Proveedores',
            'icon' => 'bi-truck',
            'model' => \App\Models\Provider::class,
        ],

        // Contador que se reinicia cada mes.
        // El uso se registra mediante whatsapp_usages.
        'max_whatsapp_messages' => [
            'label' => 'Mensajes de WhatsApp al mes',
            'icon' => 'bi-whatsapp',
            'model' => null,
            'monthly' => true,
        ],
    ];

    public const AVAILABLE_FEATURES = [

        'pos' => [
            'label' => 'Punto de venta',
            'icon' => 'bi-shop-window',
            'description' => 'Terminal para ventas en el establecimiento',
        ],

        'online_orders' => [
            'label' => 'Pedidos en línea',
            'icon' => 'bi-cart3',
            'description' => 'Gestión de pedidos de la tienda en línea',
        ],

        'hrm' => [
            'label' => 'Gestión de personal',
            'icon' => 'bi-person-badge',
            'description' => 'Empleados, asistencia y nómina',
        ],

        'accounting' => [
            'label' => 'Contabilidad',
            'icon' => 'bi-calculator',
            'description' => 'Contabilidad financiera y registro contable',
        ],

        'woocommerce' => [
            'label' => 'WooCommerce',
            'icon' => 'bi-bag-check',
            'description' => 'Integración con tiendas WooCommerce',
        ],

        'shopify' => [
            'label' => 'Shopify',
            'icon' => 'bi-bag',
            'description' => 'Integración con tiendas Shopify',
        ],

        'transfers' => [
            'label' => 'Traslados de inventario',
            'icon' => 'bi-arrow-left-right',
            'description' => 'Traslado de existencias entre almacenes',
        ],

        'service_maintenance' => [
            'label' => 'Servicios y mantenimiento',
            'icon' => 'bi-wrench',
            'description' => 'Gestión de servicios y mantenimiento',
        ],

        'ai_reports' => [
            'label' => 'Reportes con IA',
            'icon' => 'bi-robot',
            'description' => 'Análisis y reportes generados con inteligencia artificial',
        ],

        'promotions' => [
            'label' => 'Promociones',
            'icon' => 'bi-tag',
            'description' => 'Descuentos, códigos promocionales y ofertas',
        ],

        'contracts' => [
            'label' => 'Contratos',
            'icon' => 'bi-file-earmark-text',
            'description' => 'Contratos, plantillas y renovaciones',
        ],

        'projects' => [
            'label' => 'Proyectos y tareas',
            'icon' => 'bi-kanban',
            'description' => 'Gestión de proyectos, tareas y tableros Kanban',
        ],

        'bookings' => [
            'label' => 'Reservas',
            'icon' => 'bi-calendar-check',
            'description' => 'Citas y calendario de reservas',
        ],

        'assets' => [
            'label' => 'Activos',
            'icon' => 'bi-pc-display',
            'description' => 'Control y mantenimiento de los activos de la empresa',
        ],

        'quotations' => [
            'label' => 'Cotizaciones',
            'icon' => 'bi-file-earmark-ruled',
            'description' => 'Cotizaciones y presupuestos para clientes',
        ],

        'commissions' => [
            'label' => 'Comisiones',
            'icon' => 'bi-percent',
            'description' => 'Agentes de ventas y programas de comisiones',
        ],

        'recruitment' => [
            'label' => 'Reclutamiento',
            'icon' => 'bi-person-plus',
            'description' => 'Vacantes, candidatos y entrevistas',
        ],

        'meetings' => [
            'label' => 'Reuniones',
            'icon' => 'bi-camera-video',
            'description' => 'Reuniones, calendario y asistencia',
        ],

        'marketing' => [
            'label' => 'Marketing',
            'icon' => 'bi-megaphone',
            'description' => 'Campañas, segmentos y actividades de marketing',
        ],

        'knowledge_base' => [
            'label' => 'Base de conocimientos',
            'icon' => 'bi-journal-text',
            'description' => 'Artículos de ayuda y documentación',
        ],

        'quickbooks' => [
            'label' => 'QuickBooks',
            'icon' => 'bi-cloud-arrow-up',
            'description' => 'Sincronización contable con QuickBooks',
        ],

        'zatca' => [
            'label' => 'Facturación electrónica ZATCA',
            'icon' => 'bi-qr-code',
            'description' => 'Facturación electrónica ZATCA Fase 2 (Fatoora)',
        ],

        'webhooks' => [
            'label' => 'Webhooks',
            'icon' => 'bi-broadcast',
            'description' => 'Webhooks salientes e integraciones',
        ],
    ];

    public function tenantSubscriptions()
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }

    /**
     * Solo planes visibles públicamente:
     * activos y no privados.
     */
    public function scopePublic($query)
    {
        return $query
            ->where('is_active', true)
            ->where('is_private', false);
    }

    /**
     * Un plan es gratuito cuando el precio mensual y anual son cero.
     */
    public function isFree(): bool
    {
        return (float) $this->price <= 0
            && (float) ($this->yearly_price ?? 0) <= 0;
    }

    /**
     * Indica si el plan ofrece un período de prueba gratuito.
     */
    public function hasTrial(): bool
    {
        return ! $this->isFree()
            && $this->is_trial
            && $this->getTrialDays() > 0;
    }

    /**
     * Obtiene la cantidad de días de prueba.
     * Si el plan no tiene un valor propio, utiliza la configuración global.
     */
    public function getTrialDays(): int
    {
        if ($this->trial_days > 0) {
            return $this->trial_days;
        }

        return (int) config('tenancy.trial_days', 0);
    }

    /**
     * Indica si el plan requiere pago antes de crear la cuenta.
     */
    public function requiresPayment(): bool
    {
        return ! $this->isFree() && ! $this->hasTrial();
    }

    /**
     * Obtiene el valor de un límite específico.
     * Devuelve -1 cuando el límite es ilimitado.
     */
    public function getLimit(string $key, int $default = -1): int
    {
        $limits = $this->limits ?? [];

        if (
            ! isset($limits[$key])
            || $limits[$key] === ''
            || $limits[$key] === null
        ) {
            return $default;
        }

        return (int) $limits[$key];
    }

    /**
     * Comprueba si una función está habilitada en este plan.
     *
     * El formato principal es una lista de claves habilitadas:
     * ['pos', 'hrm'].
     *
     * También se admite el formato:
     * ['pos' => true].
     *
     * Los límites numéricos no pertenecen aquí; deben gestionarse mediante
     * getLimit().
     */
    public function hasFeature(string $key): bool
    {
        $features = $this->features ?? [];

        if (in_array($key, $features, true)) {
            return true;
        }

        return array_key_exists($key, $features)
            && filter_var($features[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Comprueba si un límite específico está establecido.
     */
    public function hasLimit(string $key): bool
    {
        return $this->getLimit($key) > 0;
    }

    /**
     * Obtiene todos los límites configurados en formato listo para mostrar.
     */
    public function getFormattedLimits(): array
    {
        $result = [];

        foreach (self::AVAILABLE_LIMITS as $key => $meta) {
            $value = $this->getLimit($key);

            $result[$key] = [
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'value' => $value,
                'display' => $value < 0
                    ? 'Ilimitado'
                    : number_format($value),
            ];
        }

        return $result;
    }

    /**
     * Obtiene las funciones activas del plan.
     */
    public function getActiveFeatures(): array
    {
        $result = [];

        foreach (self::AVAILABLE_FEATURES as $key => $meta) {
            if ($this->hasFeature($key)) {
                $result[$key] = $meta;
            }
        }

        return $result;
    }

    /**
     * Cuenta cuántos límites están configurados.
     */
    public function getConfiguredLimitsCount(): int
    {
        $count = 0;

        foreach (array_keys(self::AVAILABLE_LIMITS) as $key) {
            if ($this->getLimit($key) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Obtiene el precio según el ciclo de facturación.
     * Si no existe un precio anual, calcula el equivalente a 12 meses.
     */
    public function getPriceForCycle(string $cycle = 'monthly'): float
    {
        if ($cycle === 'yearly') {
            return (float) ($this->yearly_price ?? $this->price * 12);
        }

        return (float) $this->price;
    }

    /**
     * Calcula el porcentaje de ahorro al pagar anualmente.
     */
    public function getYearlySavingsPercent(): int
    {
        $monthly12 = (float) $this->price * 12;
        $yearly = (float) ($this->yearly_price ?? $monthly12);

        if ($monthly12 <= 0) {
            return 0;
        }

        return (int) round(
            (($monthly12 - $yearly) / $monthly12) * 100
        );
    }
}