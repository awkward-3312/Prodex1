<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NavigationPerformanceArchitectureTest extends TestCase
{
    public function test_navigation_performance_layer_is_installed(): void
    {
        $main = file_get_contents(base_path('resources/src/main.js'));
        $perf = file_get_contents(base_path('resources/src/utils/navigationPerformance.js'));

        $this->assertStringContainsString("import { installNavigationPerformance } from './utils/navigationPerformance';", $main);
        $this->assertStringContainsString('installNavigationPerformance(window.axios, router);', $main);
        $this->assertStringContainsString("normalizeUrl(config && config.url) !== 'sync-locale'", $perf);
        $this->assertStringContainsString('locale === lastSyncedLocale', $perf);
        $this->assertStringContainsString('config.adapter = () => Promise.resolve', $perf);
        $this->assertStringContainsString('router.afterEach(() =>', $perf);
        $this->assertStringContainsString('NProgress.done();', $perf);
    }
}
