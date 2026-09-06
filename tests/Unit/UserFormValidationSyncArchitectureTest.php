<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Crear usuario" / "Editar usuario" marcaban el Rol como "Este campo es
 * obligatorio" aunque estuviera seleccionado, y un clic en "Crear usuario"
 * disparaba DOS toasts idénticos.
 *
 * Causa: los <validation-provider> quedaban desincronizados (mismo patrón que
 * cash_drawers.vue) — el control con el modelo está dentro del slot con scope
 * de <px-field> y VeeValidate 3.4.15 no lo auto-detecta; el `@input` del rol
 * llamaba `roleProvider.validate()` SIN pasar `user.role_id`. Y el botón
 * `type="submit"` llevaba además `@click="Submit_User"`, así que el submit se
 * ejecutaba dos veces (click + submit del form).
 *
 * Contrato del fix (SÓLO frontend; UserAccessController y los scopes intactos):
 *  - `user.firstname/lastname/username/email/password/role_id` = única fuente de
 *    verdad. Cada provider requerido se valida con ese valor real.
 *  - Ningún `@input` llama a `.validate()` sin argumento.
 *  - `Submit_User()` re-sincroniza los providers desde `user.*` (`syncValue`)
 *    ANTES de `Create_User/Edit_User.validate()`.
 *  - Una sola ruta de submit: `<form @submit.prevent="Submit_User">` + botón
 *    `type="submit"` SIN `@click="Submit_User"`.
 *  - Errores de acceso (grupo A, required) se nombran por campo + foco al
 *    primero; errores de alcance operativo (grupo B) llevan su propio mensaje y
 *    no se mezclan con los required.
 */
class UserFormValidationSyncArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/src/views/app/pages/people/'.$rel);
    }

    /** @return string[] */
    private function files(): array
    {
        return ['CreateUser.vue', 'EditUser.vue'];
    }

    public function test_no_required_provider_validates_without_the_real_value(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);

            // El patrón exacto del bug.
            $this->assertDoesNotMatchRegularExpression(
                '/\$refs\.roleProvider\)\s*\$refs\.roleProvider\.validate\(\)/',
                $src,
                "$file: el rol sigue llamando validate() sin pasar user.role_id."
            );
            $this->assertStringNotContainsString('@input="v.validate"', $src, "$file: PxInput sigue delegando en v.validate sin pasar por user.*");
            // Ningún `.validate()` a secas en un handler @input.
            $this->assertDoesNotMatchRegularExpression('/@input="[^"]*\.validate\(\)\s*[;}]/', $src, "$file: hay un @input que llama validate() sin valor.");
        }
    }

    public function test_role_provider_receives_user_role_id(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertStringContainsString('@input="onRoleSelected"', $src, "$file: el select de Rol no usa onRoleSelected.");
            $this->assertMatchesRegularExpression(
                '/onRoleSelected\(value\)\s*\{\s*this\.user\.role_id = value;\s*this\.roleChanged\(\);\s*this\.validateProvider\(\x27roleProvider\x27, this\.user\.role_id\);\s*\}/s',
                $src,
                "$file: onRoleSelected no valida roleProvider con this.user.role_id."
            );
        }
    }

    public function test_text_inputs_are_bound_to_user_and_validate_the_real_value(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertMatchesRegularExpression(
                '/onTextInput\(field, providerRef, value\)\s*\{\s*this\.user\[field\] = value;\s*this\.validateProvider\(providerRef, value\);\s*\}/s',
                $src,
                "$file: falta onTextInput(field, providerRef, value) que escribe user[field] y valida ese valor."
            );
            // Los inputs de acceso enlazan :value (no v-model) y pasan por onTextInput.
            foreach (['firstname', 'lastname', 'username', 'email'] as $field) {
                $this->assertStringContainsString(":value=\"user.$field\"", $src, "$file: el input $field no usa :value=user.$field");
                $this->assertStringContainsString("onTextInput('$field'", $src, "$file: el input $field no pasa por onTextInput");
                $this->assertStringNotContainsString("v-model=\"user.$field\"", $src, "$file: el input $field sigue con v-model (doble fuente).");
            }
        }
        // password sólo en Create (opcional en Edit).
        $create = $this->read('CreateUser.vue');
        $this->assertStringContainsString(":value=\"user.password\"", $create);
        $this->assertStringContainsString("onTextInput('password', 'passwordProvider'", $create);
    }

    public function test_submit_resyncs_providers_before_the_observer_validates(): void
    {
        $create = $this->read('CreateUser.vue');
        $this->assertMatchesRegularExpression(
            '/Submit_User\(\)\s*\{.*?this\.syncProvidersFromUser\(\);\s*this\.\$refs\.Create_User\.validate\(\)/s',
            $create,
            'CreateUser: Submit_User no re-sincroniza providers antes de Create_User.validate().'
        );
        $edit = $this->read('EditUser.vue');
        $this->assertMatchesRegularExpression(
            '/Submit_User\(\)\s*\{.*?this\.syncProvidersFromUser\(\);\s*this\.\$refs\.Edit_User\.validate\(\)/s',
            $edit,
            'EditUser: Submit_User no re-sincroniza providers antes de Edit_User.validate().'
        );
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertMatchesRegularExpression(
                '/syncProvidersFromUser\(\)\s*\{\s*const map = this\.providerFieldMap\(\);\s*Object\.keys\(map\)\.forEach\(ref => \{\s*const p = this\.\$refs\[ref\];\s*if \(p && p\.syncValue\) p\.syncValue\(this\.user\[map\[ref\]\]\);/s',
                $src,
                "$file: syncProvidersFromUser no alimenta syncValue desde user.*"
            );
        }
    }

    public function test_single_submit_path_no_click_handler_on_submit_button(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertStringContainsString('<form @submit.prevent="Submit_User"', $src, "$file: falta el form @submit.prevent.");
            $this->assertMatchesRegularExpression('/type="submit"[^>]*>\s*\{\{\s*SubmitProcessing/s', $src, "$file: el botón primario no es type=submit.");
            $this->assertStringNotContainsString('@click="Submit_User"', $src, "$file: el botón submit conserva @click (doble submit).");
        }
    }

    public function test_access_errors_are_named_and_focused_not_a_blank_generic(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertStringContainsString('invalidAccessFields()', $src, "$file: falta invalidAccessFields().");
            $this->assertStringContainsString('focusFirstInvalid()', $src, "$file: falta focusFirstInvalid().");
            $this->assertStringContainsString('Revisa estos campos obligatorios:', $src, "$file: el mensaje de grupo A no nombra los campos.");
            // El genérico sólo como fallback.
            $this->assertMatchesRegularExpression(
                '/fields\.length\s*\n?\s*\?\s*`Revisa estos campos obligatorios: \$\{fields\.join\(\x27, \x27\)\}\.`\s*\n?\s*:\s*this\.\$t\(\x27Please_fill_the_form_correctly\x27\)/s',
                $src,
                "$file: 'Please_fill_the_form_correctly' debe ser sólo el fallback."
            );
        }
    }

    public function test_operational_errors_are_separate_from_required_errors(): void
    {
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            // Grupo B: sucursal / sucursal predeterminada / caja física — mensajes propios.
            $this->assertStringContainsString('Selecciona al menos una sucursal para el alcance operativo.', $src);
            $this->assertStringContainsString('Selecciona la sucursal predeterminada.', $src);
            $this->assertStringContainsString('Este rol necesita una caja física predeterminada para operar POS.', $src);
            $this->assertStringContainsString("'Alcance operativo'", $src);
            // El bloque de grupo B va DESPUÉS del `if (!success)` (se comprueba por orden textual).
            $posSuccess = strpos($src, 'if (!success)');
            $posBranch = strpos($src, 'Selecciona al menos una sucursal para el alcance operativo.');
            $this->assertNotFalse($posSuccess);
            $this->assertNotFalse($posBranch);
            $this->assertGreaterThan($posSuccess, $posBranch, "$file: las reglas operativas deben evaluarse después de la validación de acceso.");
        }
    }

    public function test_backend_and_scopes_untouched(): void
    {
        $base = dirname(__DIR__, 2);
        // Ninguno de estos ficheros forma parte del changeset del fix.
        foreach ([
            'app/Http/Controllers/Organization/UserAccessController.php',
            'app/Services/BranchScopeService.php',
            'app/Services/InventoryLocationScopeService.php',
        ] as $rel) {
            $this->assertFileExists($base.'/'.$rel);
        }
        // El .vue no reimplementa reglas de negocio del backend.
        foreach ($this->files() as $file) {
            $src = $this->read($file);
            $this->assertStringNotContainsString('BranchScopeService', $src);
            $this->assertStringNotContainsString('InventoryLocationScopeService', $src);
        }
    }
}
