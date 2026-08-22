<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CreateUserValidationFeedbackTest extends TestCase
{
    public function test_create_user_component_defines_safe_toast_and_renders_backend_errors(): void
    {
        $path = dirname(__DIR__, 2).'/resources/src/views/app/pages/people/CreateUser.vue';
        $source = file_get_contents($path);

        $this->assertStringContainsString('makeToast(variant, msg, title)', $source);
        $this->assertStringContainsString('form_errors: []', $source);
        $this->assertStringContainsString('Object.values(errors).reduce', $source);
        $this->assertStringContainsString('v-for="(message, index) in form_errors"', $source);
    }
}
