<?php

namespace Database\Seeders\Central;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::connection('central')->table('plans')->exists()) {
            return;
        }

        $now = now();

        DB::connection('central')->table('plans')->insert([
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 0,
                'yearly_price' => 0,
                'billing_interval' => 'monthly',
                'limits' => json_encode([
                    'max_products' => 50,
                    'max_users' => 2,
                    'max_warehouses' => 1,
                    'max_customers' => 50,
                    'max_suppliers' => 10,
                    'max_whatsapp_messages' => 50,
                ]),
                'features' => json_encode(['pos']),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'price' => 29.99,
                'yearly_price' => 299.99,
                'billing_interval' => 'monthly',
                'limits' => json_encode([
                    'max_products' => 500,
                    'max_users' => 10,
                    'max_warehouses' => 5,
                    'max_customers' => 500,
                    'max_suppliers' => 50,
                    'max_whatsapp_messages' => 500,
                ]),
                'features' => json_encode([
                    'pos',
                    'online_orders',
                    'transfers',
                    'accounting',
                ]),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 99.99,
                'yearly_price' => 999.99,
                'billing_interval' => 'monthly',
                'limits' => json_encode([]),
                'features' => json_encode([
                    'pos',
                    'online_orders',
                    'hrm',
                    'accounting',
                    'woocommerce',
                    'transfers',
                    'service_maintenance',
                    'ai_reports',
                    'contracts',
                    'projects',
                    'bookings',
                    'assets',
                    'quotations',
                    'commissions',
                    'recruitment',
                    'meetings',
                    'marketing',
                    'knowledge_base',
                    'quickbooks',
                    'zatca',
                    'webhooks',
                ]),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
