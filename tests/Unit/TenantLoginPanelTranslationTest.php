<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Tenant /login panel title/subtitle must be locale-aware (auth.php lang
 * files) rather than hardcoded, and must still let a genuinely customized
 * per-tenant value (SettingsController) win over the translated default.
 */
class TenantLoginPanelTranslationTest extends TestCase
{
    public function test_login_panel_title_is_spanish_when_locale_is_spanish(): void
    {
        App::setLocale('es');

        $this->assertSame('Iniciar sesión', __('auth.login_panel_title'));
        $this->assertSame('Accede a tu panel y administra todo desde un solo lugar.', __('auth.login_panel_subtitle'));
    }

    public function test_login_panel_title_stays_english_when_locale_is_english(): void
    {
        App::setLocale('en');

        $this->assertSame('Sign In', __('auth.login_panel_title'));
        $this->assertSame('Access your dashboard and manage everything from one place.', __('auth.login_panel_subtitle'));
    }

    public function test_login_view_falls_back_to_translation_for_the_untouched_seed_default(): void
    {
        $blade = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString("__('auth.login_panel_title')", $blade);
        $this->assertStringContainsString("__('auth.login_panel_subtitle')", $blade);
        // The seed-default sentinel must still be present — this is what lets a
        // genuinely customized tenant value keep overriding the translation.
        $this->assertStringContainsString('panelTitleSeedDefault', $blade);
        $this->assertStringContainsString('panelSubtitleSeedDefault', $blade);
    }
}
