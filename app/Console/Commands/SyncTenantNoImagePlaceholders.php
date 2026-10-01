<?php

namespace App\Console\Commands;

use App\Services\Brand\TenantNoImagePlaceholderUpdater;
use Illuminate\Console\Command;

class SyncTenantNoImagePlaceholders extends Command
{
    protected $signature = 'prodex:sync-no-image {--dry-run : Report changes without writing files}';

    protected $description = 'Refresh only known legacy tenant no-image placeholders';

    public function handle(TenantNoImagePlaceholderUpdater $updater): int
    {
        $result = $updater->sync(
            public_path('images/no-image.png'),
            public_path('images/tenants'),
            (bool) $this->option('dry-run')
        );

        foreach ($result as $status => $files) {
            $this->line("{$status}: ".count($files));
        }

        return self::SUCCESS;
    }
}
