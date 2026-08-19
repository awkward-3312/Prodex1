<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_questions')) return;

        $titles = [
            1 => 'Resumen de ventas de ayer',
            2 => '¿Por qué bajó la ganancia ayer?',
            3 => 'Resumen de ventas de hoy',
            4 => 'Resumen de ventas de esta semana',
            5 => 'Resumen de ventas de la semana pasada',
            6 => 'Esta semana vs. semana pasada (análisis)',
            7 => 'Resumen de ventas de este mes',
            8 => 'Resumen de ventas del mes pasado',
            9 => '10 productos con mayor ganancia (esta semana)',
            10 => '20 productos con mayor ganancia (este mes)',
            11 => '15 productos con mayores ingresos (esta semana)',
            12 => '10 productos con mayor cantidad vendida (esta semana)',
            13 => '10 productos con mayor ganancia (mes pasado)',
            14 => 'Clientes con pagos atrasados por más de 30 días',
            15 => 'Clientes con pagos atrasados por más de 60 días',
            16 => 'Clientes con pagos atrasados por más de 90 días',
            17 => 'Facturas vencidas por 7 días o más',
            18 => 'Facturas vencidas por 14 días o más',
            19 => '25 productos con mayor ganancia (este mes)',
            20 => 'Ayer vs. día anterior (análisis de ganancia)',
        ];

        foreach ($titles as $sortOrder => $title) {
            DB::table('report_questions')->where('sort_order', $sortOrder)->update([
                'title' => $title,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep Spanish user-facing report titles.
    }
};
