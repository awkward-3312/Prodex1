<?php

namespace App\Services\Brand;

use RuntimeException;

final class TenantNoImagePlaceholderUpdater
{
    private const FOLDERS = ['banners', 'brands', 'leaves', 'products', 'settings'];

    // Exact bytes of the two placeholders shipped before the PRODEX brand refresh.
    private const LEGACY_HASHES = [
        '94dcb24d78ef808136e2a6c4cfa8981377d9e4020e671718313018f1afede51e',
        'd38f7d27fcd7506c6688e8fb2070c8ae8de1a3be76d09de640d1b0a7b2c5fe1f',
    ];

    public function sync(string $source, string $tenantsRoot, bool $dryRun = false): array
    {
        if (! is_file($source) || is_link($source)) {
            throw new RuntimeException("Official placeholder is missing: {$source}");
        }

        $sourceHash = hash_file('sha256', $source);
        $result = ['updated' => [], 'current' => [], 'custom' => [], 'missing' => []];
        if (! is_dir($tenantsRoot)) {
            return $result;
        }

        foreach (new \DirectoryIterator($tenantsRoot) as $tenant) {
            if ($tenant->isDot() || ! $tenant->isDir() || $tenant->isLink()) {
                continue;
            }

            foreach (self::FOLDERS as $folder) {
                $destination = $tenant->getPathname().DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'no-image.png';
                if (is_link(dirname($destination)) || ! is_file($destination) || is_link($destination)) {
                    $result['missing'][] = $destination;
                    continue;
                }

                $hash = hash_file('sha256', $destination);
                if ($hash === $sourceHash) {
                    $result['current'][] = $destination;
                    continue;
                }
                if (! in_array($hash, self::LEGACY_HASHES, true)) {
                    $result['custom'][] = $destination;
                    continue;
                }

                if (! $dryRun) {
                    $temporary = tempnam(dirname($destination), '.no-image-');
                    if ($temporary === false) {
                        throw new RuntimeException("Cannot stage placeholder: {$destination}");
                    }
                    try {
                        if (! copy($source, $temporary)
                            || ! chmod($temporary, 0644)
                            || ! rename($temporary, $destination)) {
                            throw new RuntimeException("Cannot update placeholder: {$destination}");
                        }
                    } finally {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                }
                $result['updated'][] = $destination;
            }
        }

        return $result;
    }
}
