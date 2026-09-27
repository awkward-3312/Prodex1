<?php
/**
 * Provisiona el tenant DEMO de los E2E en la base central/tenant AISLADA (ver docker-compose.yml).
 *
 * No es código de producto: solo lo usan `scripts/setup.sh` y CI. Reutiliza los mecanismos
 * reales del proyecto (`ProvisionTenantWorkspace`, seeders centrales, `DemoDataSeeder`) en lugar
 * de inventar datos. Es idempotente: si el tenant ya existe y está activo, no lo recrea.
 *
 * Variables (todas obligatorias, sin valores por defecto para credenciales):
 *   E2E_TENANT_SUBDOMAIN, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD,
 *   E2E_RESTRICTED_EMAIL, E2E_RESTRICTED_PASSWORD
 */

use App\Jobs\ProvisionTenantWorkspace;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function need(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "Falta la variable de entorno {$name}\n");
        exit(2);
    }

    return $v;
}

if (app()->environment('production')) {
    fwrite(STDERR, "Se niega a ejecutar con APP_ENV=production.\n");
    exit(3);
}

$host = config('database.connections.central.host') ?? config('database.connections.mysql.host');
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Se niega a provisionar en un host de BD que no es local ({$host}).\n");
    exit(3);
}

$domain = need('E2E_TENANT_SUBDOMAIN');
$adminEmail = need('E2E_ADMIN_EMAIL');
$adminPassword = need('E2E_ADMIN_PASSWORD');
$restrictedEmail = need('E2E_RESTRICTED_EMAIL');
$restrictedPassword = need('E2E_RESTRICTED_PASSWORD');

// 1) Datos centrales (planes, idiomas) con los seeders reales del proyecto.
foreach (['PlansSeeder', 'CentralLanguagesSeeder', 'PlanLimitsSyncSeeder'] as $seeder) {
    Artisan::call('db:seed', ['--class' => "Database\\Seeders\\Central\\{$seeder}", '--force' => true]);
}

// Correo de plataforma inerte (el seeder real de tenants exige usuario/clave SMTP no nulos).
\App\Models\Central\MailSetting::instance()->forceFill([
    'mail_mailer' => 'log',
    'mail_host' => '127.0.0.1',
    'mail_port' => 25,
    'mail_username' => 'e2e',
    'mail_password' => 'e2e',
    'mail_from_address' => 'e2e@example.test',
    'mail_from_name' => 'PRODEX E2E',
])->save();

// 2) Tenant + dominio + suscripción (plan Enterprise = todas las funciones).
$existing = Tenant::whereHas('domains', fn ($q) => $q->where('domain', $domain))->first();

if (! $existing) {
    $plan = Plan::where('slug', 'enterprise')->firstOrFail();
    $tenant = Tenant::create([
        'id' => Str::uuid()->toString(),
        'company_name' => 'PRODEX E2E',
        'admin_email' => $adminEmail,
        'admin_password_hash' => Hash::make($adminPassword),
        'status' => Tenant::STATUS_PENDING,
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'amount' => 0,
        'currency' => 'USD',
        'status' => TenantSubscription::STATUS_ACTIVE,
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
    ]);

    (new ProvisionTenantWorkspace($tenant->id))->handle();
    $existing = Tenant::find($tenant->id);
}

if ($existing->status === Tenant::STATUS_FAILED) {
    // Reintento tras un fallo parcial: el propio job limpia la BD del tenant (solo local, ver guardas arriba).
    (new ProvisionTenantWorkspace($existing->id))->handle();
    $existing = Tenant::find($existing->id);
}

if ($existing->status !== Tenant::STATUS_ACTIVE) {
    fwrite(STDERR, "El tenant quedó en estado '{$existing->status}': " . ($existing->provisioning_error ?? 'sin detalle') . "\n");
    exit(4);
}

// El permiso `transfer_receive` (pantalla Recepciones) NO se concede a ningún rol por defecto: el propio proyecto documenta
// que hay que asignarlo explícitamente a un rol. La suite necesita recorrer esa pantalla, así que se asigna al rol del
// administrador de pruebas, como haría un administrador desde la UI de roles. Es un dato de prueba, no un parche.
$existing->run(function () {
    $adminRoleId = DB::table('users')->where('id', 1)->value('role_id') ?? 1;
    foreach (['transfer_receive', 'transfer_issue_manage'] as $name) {
        $permissionId = DB::table('permissions')->where('name', $name)->value('id');
        if ($permissionId && ! DB::table('permission_role')->where(['permission_id' => $permissionId, 'role_id' => $adminRoleId])->exists()) {
            DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $adminRoleId]);
        }
    }
});

// 3) Usuario restringido (sin permisos operativos) para la prueba de permisos.
$existing->run(function () use ($restrictedEmail, $restrictedPassword) {
    if (DB::table('users')->where('email', $restrictedEmail)->exists()) {
        return;
    }

    $roleId = DB::table('roles')->where('name', 'e2e_restricted')->value('id');
    if (! $roleId) {
        $role = ['name' => 'e2e_restricted', 'label' => 'E2E restringido', 'description' => 'Rol de prueba sin permisos operativos'];
        $cols = Schema::getColumnListing('roles');
        $roleId = DB::table('roles')->insertGetId(array_intersect_key($role + ['created_at' => now(), 'updated_at' => now()], array_flip($cols)));
    }

    $user = [
        'firstname' => 'E2E',
        'lastname' => 'Restringido',
        'username' => 'e2e_restringido',
        'email' => $restrictedEmail,
        'password' => Hash::make($restrictedPassword),
        'avatar' => 'no_avatar.png',
        'phone' => '0000000000',
        'role_id' => $roleId,
        'statut' => 1,
        'is_all_warehouses' => 0,
        'record_view' => 0,
    ];
    $cols = Schema::getColumnListing('users');
    DB::table('users')->insert(array_intersect_key($user + ['created_at' => now(), 'updated_at' => now()], array_flip($cols)));
});

// 4) Caja física de prueba (el POS exige seleccionar una para abrir caja). Usa el modelo real del proyecto.
$existing->run(function () {
    if (\App\Models\CashDrawer::whereNull('deleted_at')->where('code', 'E2E-01')->exists()) {
        return;
    }
    \App\Models\CashDrawer::create([
        'warehouse_id' => DB::table('warehouses')->orderBy('id')->value('id'),
        'name' => 'Caja E2E',
        'code' => 'E2E-01',
        'description' => 'Caja física para pruebas E2E',
        'is_active' => true,
    ]);
});

// 5) Datos demo con el seeder oficial (idempotente; se niega a correr en producción) y existencias holgadas:
//    los E2E venden unidades reales en cada corrida y no deben agotar el stock del catálogo demo.
Artisan::call('prodex:seed-demo-tenant', ['tenant' => $domain, '--force' => true]);
$existing->run(function () {
    DB::table('product_warehouse')->update(['qte' => 100000]);

    // Los E2E también venden con lote y con serial: el demo trae 10 lotes de 10 unidades y 10 seriales, que se
    // agotarían tras unas corridas. Se amplían con copias de las filas reales (mismos datos, otro número).
    DB::table('product_batches')->update(['qty' => 100000]);
    $serial = DB::table('product_serials')->where('serial_number', 'SN-DEMO2-001')->first();
    if ($serial && ! DB::table('product_serials')->where('serial_number', 'SN-E2E-0001')->exists()) {
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $row = (array) $serial;
            unset($row['id']);
            $row['serial_number'] = sprintf('SN-E2E-%04d', $i);
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('product_serials')->insert($chunk);
        }
    }
});

echo "OK tenant={$existing->id} domain={$domain}\n";
