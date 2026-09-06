<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Configuración / Cajas físicas → Agregar caja física" no podía guardar: al
 * pulsar "Guardar caja" aparecían errores "Este campo es obligatorio" en Nombre
 * y Código y un toast de sucursal/ubicación aunque los cuatro valores estaban
 * llenos, y el POST /cash-drawers ni siquiera se enviaba.
 *
 * Causa: los <validation-provider> quedaban desincronizados. El control que
 * lleva el modelo está dentro del slot con scope de <px-field>, que VeeValidate
 * no puede auto-detectar; y cada handler @input llamaba `v.validate()` SIN
 * argumento, validando el valor interno obsoleto (undefined) en vez del valor
 * real de `form.*`. `this.$refs.CashDrawerForm.validate()` devolvía `false` y
 * `submitDrawer()` abortaba antes de `saveDrawer()`.
 *
 * Contrato del fix (SÓLO frontend; backend CashDrawerController intacto):
 *  - `form.name` / `form.code` / `form.branch_id` / `form.inventory_location_id`
 *    son la ÚNICA fuente de verdad; cada provider se valida SIEMPRE con ese
 *    valor real (`provider.validate(value)`), nunca con `validate()` a secas.
 *  - `submitDrawer()` re-sincroniza los cuatro providers desde `form.*` antes de
 *    `CashDrawerForm.validate()`.
 *  - El toast de sucursal/ubicación sólo aparece cuando realmente falta
 *    `branch_id` o `inventory_location_id`; los required de Nombre/Código se
 *    quedan bajo su campo.
 */
class CashDrawerFormValidationArchitectureTest extends TestCase
{
    private function vue(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2).'/resources/src/views/app/pages/settings/cash_drawers.vue'
        );
    }

    private function controller(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/CashDrawerController.php'
        );
    }

    /** Ningún handler @input puede llamar a validate() del slot sin argumento. */
    public function test_no_input_handler_calls_provider_validate_without_a_value(): void
    {
        $c = $this->vue();

        // El patrón exacto del bug: `v.validate()` / `v.validate();` a secas.
        $this->assertDoesNotMatchRegularExpression(
            '/\bv\.validate\(\s*\)/',
            $c,
            'Un handler sigue llamando v.validate() sin pasar el valor real; el provider se desincroniza.'
        );

        // El método intermedio buggy también desaparece.
        $this->assertStringNotContainsString('onBranchChangeAndValidate', $c);
    }

    /** Los @input de los cuatro campos van por helpers que pasan el valor real. */
    public function test_inputs_route_through_helpers_that_sync_the_real_value(): void
    {
        $c = $this->vue();

        $this->assertStringContainsString('@input="val => onTextInput(\'name\', \'nameProvider\', val)"', $c);
        $this->assertStringContainsString('@input="val => onTextInput(\'code\', \'codeProvider\', val)"', $c);
        $this->assertStringContainsString('@input="onBranchSelected"', $c);
        $this->assertStringContainsString('@input="onLocationSelected"', $c);

        // onTextInput: escribe form[field] y valida el provider con ESE valor.
        $this->assertMatchesRegularExpression(
            '/onTextInput\(field, ref, rawValue\)\s*\{.*?this\.form\[field\]\s*=\s*value;.*?this\.validateProvider\(ref, value\);/s',
            $c
        );

        // validateProvider delega en provider.validate(value) — valor explícito.
        $this->assertMatchesRegularExpression(
            '/validateProvider\(ref, value\)\s*\{\s*const p = this\.\$refs\[ref\];\s*if \(p && p\.validate\) p\.validate\(value\);\s*\}/s',
            $c
        );

        // El select de sucursal revalida sucursal Y la ubicación recalculada.
        $this->assertMatchesRegularExpression(
            '/onBranchSelected\(\)\s*\{.*?this\.onBranchChange\(\);.*?this\.validateProvider\("branchProvider", this\.form\.branch_id\);.*?this\.validateProvider\("locationProvider", this\.form\.inventory_location_id\);/s',
            $c
        );
    }

    /** submitDrawer re-sincroniza desde form.* antes de validar el observer. */
    public function test_submit_resyncs_every_provider_from_the_form_before_validating(): void
    {
        $c = $this->vue();

        $this->assertMatchesRegularExpression(
            '/submitDrawer\(\)\s*\{.*?const map = this\.providerFieldMap\(\);.*?p\.syncValue\(this\.form\[map\[ref\]\]\).*?this\.\$refs\.CashDrawerForm\.validate\(\)\.then\(valid => \{/s',
            $c
        );

        // providerFieldMap: los cuatro providers ligados a sus campos de form.
        $this->assertMatchesRegularExpression(
            '/providerFieldMap\(\)\s*\{\s*return \{ nameProvider: "name", codeProvider: "code", branchProvider: "branch_id", locationProvider: "inventory_location_id" \};/s',
            $c
        );
    }

    /** El toast de sucursal/ubicación deja de ser genérico y engañoso. */
    public function test_submit_toast_tells_the_truth_about_what_is_missing(): void
    {
        $c = $this->vue();

        // El mensaje genérico anterior (disparado también por Nombre/Código) se retira.
        $this->assertStringNotContainsString(
            'Selecciona la sucursal y una ubicación de venta válida.',
            $c
        );

        // Ahora: si es válido -> saveDrawer(); si no, el toast de sucursal/
        // ubicación está guardado por la ausencia real de cada id.
        $this->assertMatchesRegularExpression(
            '/if \(valid\) \{\s*this\.saveDrawer\(\);\s*return;\s*\}/s',
            $c
        );
        $this->assertMatchesRegularExpression(
            '/if \(!this\.form\.branch_id\) \{\s*this\.toast\("warning", "Selecciona una sucursal\."/s',
            $c
        );
        $this->assertMatchesRegularExpression(
            '/else if \(!this\.form\.inventory_location_id\) \{\s*this\.toast\("warning", "Selecciona una ubicación de venta válida\."/s',
            $c
        );
    }

    /** El fix es sólo de UI: no toca backend, scope ni reglas de cajas. */
    public function test_backend_contract_is_untouched(): void
    {
        $c = $this->controller();

        // Reglas de validación intactas.
        $this->assertStringContainsString("'name' => ['required', 'string', 'max:191'],", $c);
        $this->assertStringContainsString("Rule::unique('cash_drawers', 'code')->ignore(\$drawerId)", $c);

        // Comprobaciones de negocio intactas.
        $this->assertStringContainsString('La sucursal seleccionada no existe o está inactiva.', $c);
        $this->assertStringContainsString('debe estar activa y pertenecer a la sucursal seleccionada.', $c);
        $this->assertStringContainsString('solo puede operar desde una ubicación habilitada para venta.', $c);
        $this->assertStringContainsString('BranchScopeService', $c);
        $this->assertStringContainsString('InventoryLocationScopeService', $c);
        $this->assertStringContainsString('$location->is_sellable', $c);

        // El .vue no reimplementa la autorización del backend.
        $v = $this->vue();
        $this->assertStringNotContainsString('BranchScopeService', $v);
        $this->assertStringNotContainsString('canAccess', $v);
    }
}
