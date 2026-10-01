<?php

namespace Tests\Unit;

use App\Services\Brand\TenantNoImagePlaceholderUpdater;
use PHPUnit\Framework\TestCase;

class TenantNoImagePlaceholderUpdaterTest extends TestCase
{
    public function test_updates_only_known_legacy_copies_and_is_idempotent(): void
    {
        $root = sys_get_temp_dir().'/prodex-no-image-'.bin2hex(random_bytes(8));
        $tenants = $root.'/tenants';
        $legacy = dirname(__DIR__).'/Fixtures/brand/legacy-no-image.png';
        $official = dirname(__DIR__, 2).'/public/images/no-image.png';
        mkdir($tenants.'/alpha/products', 0755, true);
        mkdir($tenants.'/alpha/brands', 0755, true);
        mkdir($tenants.'/beta/products', 0755, true);
        copy($legacy, $tenants.'/alpha/products/no-image.png');
        copy($legacy, $tenants.'/beta/products/no-image.png');
        file_put_contents($tenants.'/alpha/brands/no-image.png', 'custom image');

        try {
            $updater = new TenantNoImagePlaceholderUpdater();
            $preview = $updater->sync($official, $tenants, true);
            $this->assertCount(2, $preview['updated']);
            $this->assertSame(hash_file('sha256', $legacy), hash_file('sha256', $tenants.'/alpha/products/no-image.png'));

            $result = $updater->sync($official, $tenants);
            $this->assertCount(2, $result['updated']);
            $this->assertSame(hash_file('sha256', $official), hash_file('sha256', $tenants.'/alpha/products/no-image.png'));
            $this->assertSame(0644, fileperms($tenants.'/alpha/products/no-image.png') & 0777);
            $this->assertSame('custom image', file_get_contents($tenants.'/alpha/brands/no-image.png'));

            $again = $updater->sync($official, $tenants);
            $this->assertSame([], $again['updated']);
            $this->assertCount(2, $again['current']);
        } finally {
            foreach (glob($tenants.'/*/*/no-image.png') as $file) {
                unlink($file);
            }
            foreach (glob($tenants.'/*/*', GLOB_ONLYDIR) as $folder) {
                rmdir($folder);
            }
            foreach (glob($tenants.'/*', GLOB_ONLYDIR) as $tenant) {
                rmdir($tenant);
            }
            rmdir($tenants);
            rmdir($root);
        }
    }
}
