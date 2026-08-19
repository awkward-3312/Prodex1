<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportQuestionsSeeder extends Seeder
{
    public function run()
    {
        $questions = [
            ['Resumen de ventas de ayer','daily_sales_summary',['range'=>'yesterday'],null,false,1],
            ['¿Por qué bajó la ganancia ayer?','daily_sales_summary',['range'=>'yesterday'],['range'=>'previous_day'],true,2],
            ['Resumen de ventas de hoy','daily_sales_summary',['range'=>'today'],null,false,3],
            ['Resumen de ventas de esta semana','daily_sales_summary',['range'=>'this_week'],null,false,4],
            ['Resumen de ventas de la semana pasada','daily_sales_summary',['range'=>'last_week'],null,false,5],
            ['Esta semana vs. semana pasada (análisis)','daily_sales_summary',['range'=>'this_week'],['range'=>'previous_week'],true,6],
            ['Resumen de ventas de este mes','daily_sales_summary',['range'=>'this_month'],null,false,7],
            ['Resumen de ventas del mes pasado','daily_sales_summary',['range'=>'last_month'],null,false,8],
            ['10 productos con mayor ganancia (esta semana)','sales_by_product',['range'=>'this_week','limit'=>10,'sort_by'=>'profit','sort_dir'=>'desc'],null,false,9],
            ['20 productos con mayor ganancia (este mes)','sales_by_product',['range'=>'this_month','limit'=>20,'sort_by'=>'profit','sort_dir'=>'desc'],null,false,10],
            ['15 productos con mayores ingresos (esta semana)','sales_by_product',['range'=>'this_week','limit'=>15,'sort_by'=>'revenue','sort_dir'=>'desc'],null,false,11],
            ['10 productos con mayor cantidad vendida (esta semana)','sales_by_product',['range'=>'this_week','limit'=>10,'sort_by'=>'qty','sort_dir'=>'desc'],null,false,12],
            ['10 productos con mayor ganancia (mes pasado)','sales_by_product',['range'=>'last_month','limit'=>10,'sort_by'=>'profit','sort_dir'=>'desc'],null,false,13],
            ['Clientes con pagos atrasados por más de 30 días','late_payments',['min_days_overdue'=>30],null,false,14],
            ['Clientes con pagos atrasados por más de 60 días','late_payments',['min_days_overdue'=>60],null,false,15],
            ['Clientes con pagos atrasados por más de 90 días','late_payments',['min_days_overdue'=>90],null,false,16],
            ['Facturas vencidas por 7 días o más','late_payments',['min_days_overdue'=>7],null,false,17],
            ['Facturas vencidas por 14 días o más','late_payments',['min_days_overdue'=>14],null,false,18],
            ['25 productos con mayor ganancia (este mes)','sales_by_product',['range'=>'this_month','limit'=>25,'sort_by'=>'profit','sort_dir'=>'desc'],null,false,19],
            ['Ayer vs. día anterior (análisis de ganancia)','daily_sales_summary',['range'=>'yesterday'],['range'=>'previous_day'],true,20],
        ];

        foreach ($questions as [$title,$key,$filters,$compare,$insights,$order]) {
            DB::table('report_questions')->insert([
                'title'=>$title,
                'report_key'=>$key,
                'default_filters'=>json_encode($filters),
                'default_compare'=>$compare ? json_encode($compare) : null,
                'needs_insights'=>$insights,
                'active'=>true,
                'sort_order'=>$order,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        }
    }
}
