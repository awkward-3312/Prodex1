<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Un usuario restringido (p. ej. Cajero) debe poder usar el shell px-next y el
 * Panel aunque NO tenga permisos administrativos.
 *
 * Blocker corregido: el chip de cuenta del shell resolvía el nombre del rol con
 * `GET /api/roles` (exige `permissions_view`); un rol restringido recibía 403 y
 * el interceptor global de `main.js` mandaba TODA la SPA a `/app/not_authorize`.
 *
 * Fix: el nombre del rol propio viaja en el endpoint self `Get_user_profile`
 * (`role_name`); el shell ya no llama a `/api/roles`. El interceptor NO se toca:
 * un 403 de navegación REAL sigue redirigiendo.
 *
 * NO se concedió ningún permiso al Cajero para ocultar el problema.
 */
class PxShellRestrictedUserProbesTest extends TestCase
{
    private function read(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
    }

    // --- 1. best-effort role lookup no puede derribar la SPA ---------------

    public function test_pxshell_no_longer_calls_the_privileged_roles_endpoint(): void
    {
        $shell = $this->read('resources/src/components/px-next/PxShell.vue');

        // Ya no se hace la llamada al catálogo de roles (sí puede mencionarse en
        // un comentario que explica por qué).
        $this->assertStringNotContainsString('.get("roles"', $shell);
        $this->assertStringNotContainsString(".get('roles'", $shell);
        $this->assertStringNotContainsString('.get(`roles', $shell);

        // El nombre del rol se toma del perfil self.
        $this->assertStringContainsString('this.profile.role_name', $shell);
        $this->assertMatchesRegularExpression(
            '/loadUserMeta\(\)\s*\{.*?Get_user_profile.*?role_name/s',
            $shell
        );
    }

    public function test_backend_serves_the_role_name_from_the_self_profile_endpoint(): void
    {
        $controller = $this->read('app/Http/Controllers/UserController.php');

        // GetInfoProfile expone el nombre del rol PROPIO (self), derivado de la
        // relación roles() del usuario autenticado — no de un catálogo.
        $this->assertMatchesRegularExpression(
            "/function GetInfoProfile\(.*?\\\$payload\['role_name'\] = optional\(\\\$user->roles\(\)->first\(\)\)->name;/s",
            $controller
        );

        // No se añadió ninguna comprobación de permiso / política nueva a este
        // endpoint self.
        $this->assertMatchesRegularExpression(
            '/function GetInfoProfile\(Request \$request\)\s*\{(?:(?!authorizeForUser|hasPermissionName|abort\(|permission:).)*?\n    \}/s',
            $controller
        );
    }

    // --- 2. capability probe de cash register no puede redirigir ----------
    //     (ya era correcto: su ÚNICO call-site — public/js/prodex-organization-
    //     navigation.js — usa meta.skipErrorRedirect; aquí se fija el contrato
    //     del interceptor que lo respeta).

    public function test_interceptor_honours_skip_error_redirect_opt_out(): void
    {
        $main = $this->read('resources/src/main.js');

        $this->assertStringContainsString(
            "const skipErrorRedirect = error.config && error.config.meta && error.config.meta.skipErrorRedirect;",
            $main
        );
        // skipErrorRedirect neutraliza el redirect de navegación.
        $this->assertStringContainsString('!skipErrorRedirect', $main);
    }

    // --- 3. 403 de navegación REAL sigue redirigiendo ---------------------

    public function test_real_navigational_403_still_redirects_to_not_authorize(): void
    {
        $main = $this->read('resources/src/main.js');

        $this->assertStringContainsString(
            "const isNavigationalLoad = method === 'get' && !skipErrorRedirect",
            $main
        );
        $this->assertStringContainsString("else if (isNavigationalLoad) router.push({ name: 'not_authorize' });", $main);
        $this->assertStringContainsString("if (status === 404 && isNavigationalLoad) router.push({ name: 'NotFound' });", $main);

        // El modelo de exenciones (call-site + regex dirigidas) se conserva.
        $this->assertStringContainsString('isTransferLogisticsCapabilityRequest', $main);
        $this->assertStringContainsString('isOrganizationCapabilityRequest', $main);
        $this->assertStringContainsString('isBackgroundCapabilityRequest', $main);
        $this->assertStringContainsString("/(^|\\/)transfer-logistics(\\/|$)/i", $main);
        $this->assertStringContainsString("/(^|\\/)organization(\\/|$)/i", $main);
    }

    public function test_no_blind_url_whitelist_for_roles_or_cash_registers(): void
    {
        $main = $this->read('resources/src/main.js');

        // No se añadió una exención ciega por URL para estos endpoints (también
        // los usan páginas reales, donde el 403 SÍ debe denegar acceso).
        $this->assertStringNotContainsString('/roles', $main);
        $this->assertStringNotContainsString('cash_registers', $main);
        $this->assertStringNotContainsString('cash-registers', $main);
    }

    // --- 4. no se concedió permissions_view al Cajero --------------------

    public function test_cashier_base_role_template_was_not_widened(): void
    {
        $templates = $this->read('app/Http/Controllers/Organization/RoleTemplateController.php');

        // La plantilla de cajero sigue SIN permisos administrativos.
        $this->assertMatchesRegularExpression("/'key' => 'cashier',/", $templates);
        $this->assertSame(
            1,
            preg_match("/'key' => 'cashier'.*?'permissions' => (\[[^\]]*\])/s", $templates, $m),
            'No se encontró la lista de permisos de la plantilla cashier.'
        );
        foreach (['permissions_view', 'record_view', 'users_view', 'view_employee', 'roles_view'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}'", $m[1], "La plantilla cashier no debe incluir '{$forbidden}'.");
        }
    }

    public function test_role_view_policy_still_requires_permissions_view(): void
    {
        $policy = $this->read('app/Policies/RolePolicy.php');

        $this->assertMatchesRegularExpression(
            "/function view\(User \\\$user\)\s*\{\s*return \\\$user->hasPermissionName\('permissions_view'\);/s",
            $policy
        );
    }

    // --- 5. no se amplió la política Sale / cash-register ---------------

    public function test_cash_register_report_authorization_is_unchanged(): void
    {
        $controller = $this->read('app/Http/Controllers/CashRegisterController.php');

        $this->assertStringContainsString(
            "\$this->authorizeForUser(\$request->user('api'), 'cash_register_report', Sale::class);",
            $controller
        );
    }

    // --- 6. interceptor conserva seguridad + 9b6383e intacto -------------

    public function test_dashboard_analytics_authorization_from_9b6383e_is_untouched(): void
    {
        $root = dirname(__DIR__, 2);

        // Estos archivos no se tocan en este fix.
        $this->assertFileExists($root.'/app/Services/DashboardScopeService.php');
        $this->assertStringContainsString('OWNER_ROLE_ID', $this->read('app/Services/DashboardScopeService.php'));

        $user = $this->read('app/Models/User.php');
        $this->assertStringContainsString('public function hasRecordView()', $user);
        $this->assertStringContainsString('if ((int) $this->role_id === 1)', $user);

        $sales = $this->read('app/Services/SalesReportingScopeService.php');
        $this->assertStringContainsString('public function applyRecordVisibility(', $sales);

        $dashboard = $this->read('app/Http/Controllers/DashboardController.php');
        $this->assertStringContainsString('public function real_time_sales_counter_data(Request $request)', $dashboard);
        $this->assertStringContainsString('DashboardScope $scope', $dashboard);
    }
}
