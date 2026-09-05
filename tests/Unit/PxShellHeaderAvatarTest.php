<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The px-next shell header (resources/src/components/px-next/PxShell.vue)
 * showed initials ("P0") instead of the real avatar because its avatarUrl
 * computed prefixed window.__uploadPath (images/tenants/{id}/…) via
 * $imgUrl('avatar', …) — the wrong folder. Avatars actually live at the
 * global '/images/avatar/' (see UserController + views/app/pages/profile.vue,
 * which already resolves them correctly with that exact literal path).
 *
 * No JS test runner (jest/vue-test-utils) is configured in this project, so
 * — consistent with how this codebase's other Blade/Vue source-level fixes
 * are regression-tested in this suite — these assert directly on the
 * component source: the broken call is gone, the correct/reused path
 * pattern is present, and nothing about the dropdown/menu/name/role markup
 * or the avatar's circular/object-fit styling was touched.
 */
class PxShellHeaderAvatarTest extends TestCase
{
    private function shellSource(): string
    {
        return file_get_contents(resource_path('src/components/px-next/PxShell.vue'));
    }

    private function profileSource(): string
    {
        return file_get_contents(resource_path('src/views/app/pages/profile.vue'));
    }

    public function test_header_no_longer_uses_the_tenant_scoped_img_url_helper_for_avatar(): void
    {
        $this->assertStringNotContainsString('this.$imgUrl("avatar", raw)', $this->shellSource());
        $this->assertStringNotContainsString("this.\$imgUrl('avatar', raw)", $this->shellSource());
    }

    public function test_header_resolves_avatar_with_the_same_path_pattern_as_the_profile_view(): void
    {
        $shell = $this->shellSource();
        $profile = $this->profileSource();

        // The profile view's proven-correct, tenant-scope-independent pattern.
        $this->assertStringContainsString("'/images/avatar/'+avatar", str_replace(' ', '', $profile));
        // The header must reuse that exact same pattern — not a new/second
        // resolution rule.
        $this->assertStringContainsString('"/images/avatar/" + raw', $shell);
    }

    public function test_a_custom_avatar_and_the_four_defaults_all_resolve_through_the_same_branch(): void
    {
        $shell = $this->shellSource();

        // Only the legacy placeholder / empty value falls back to initials —
        // a custom avatar and default_avatar_1..4.png all fall through to
        // the same "/images/avatar/" + raw return.
        $this->assertMatchesRegularExpression(
            '/if \(!raw \|\| raw === "no_avatar\.png"\) return null;\s*\n\s*return "\/images\/avatar\/" \+ raw;/',
            $shell
        );
    }

    public function test_initials_fallback_is_preserved_for_null_or_legacy_placeholder(): void
    {
        $shell = $this->shellSource();

        $this->assertStringContainsString('pxn-userchip__initials', $shell);
        $this->assertStringContainsString('v-else class="pxn-userchip__initials"', $shell);
    }

    public function test_dropdown_caret_and_menu_are_untouched(): void
    {
        $shell = $this->shellSource();

        $this->assertStringContainsString('pxn-userchip__caret', $shell);
        $this->assertStringContainsString('toggleUserMenu', $shell);
        $this->assertStringContainsString('pxn-userchip__menu', $shell);
        $this->assertStringContainsString('pxn-userchip__name', $shell);
        $this->assertStringContainsString('pxn-userchip__role', $shell);
    }

    public function test_avatar_styling_stays_circular_and_object_fit_cover(): void
    {
        $shell = $this->shellSource();

        $this->assertMatchesRegularExpression('/\.pxn-userchip__avatar\s*\{[^}]*border-radius:\s*var\(--pxn-radius-pill\)/s', $shell);
        $this->assertStringContainsString('.pxn-userchip__avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }', $shell);
    }
}
