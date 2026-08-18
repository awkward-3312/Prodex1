<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('central')->table('landing_footer')->update([
            'sales_whatsapp_number' => '+504 9839-2308',
            'sales_whatsapp_message' => 'Hola, me interesa conocer más sobre PRODEX y sus planes.',
        ]);

        DB::connection('central')->table('landing_cta')->update([
            'sales_button_url' => null,
        ]);
    }

    public function down(): void
    {
        DB::connection('central')->table('landing_footer')->update([
            'sales_whatsapp_number' => null,
        ]);
    }
};
