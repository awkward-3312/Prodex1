<?php

use App\Services\Brand\TenantNoImagePlaceholderUpdater;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new TenantNoImagePlaceholderUpdater())->sync(
            public_path('images/no-image.png'),
            public_path('images/tenants')
        );
    }

    public function down(): void
    {
        // Reverting the official placeholder would recreate obsolete branding.
    }
};
