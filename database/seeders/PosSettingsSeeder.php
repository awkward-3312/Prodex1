<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosSettingsSeeder extends Seeder
{
    public function run()
    {
        DB::table('pos_settings')->insert([
            'id' => 1,
            'note_customer' => 'Thank You For Shopping With Us . Please Come Again',
            'show_note' => 1,
            'show_barcode' => 1,
            'show_discount' => 1,
            'show_customer' => 1,
            'show_email' => 1,
            'show_phone' => 1,
            'show_address' => 1,
        ]);
    }
}
