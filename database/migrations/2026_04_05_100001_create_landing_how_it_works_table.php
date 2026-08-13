<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateLandingHowItWorksTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('landing_how_it_works_section', function (Blueprint $table) {
            $table->id();
            $table->string('section_label')->nullable();
            $table->string('section_title')->nullable();
            $table->string('section_subtitle')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('central')->create('landing_how_it_works_steps', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('icon_color')->nullable()->default('green');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default section & steps
        $now = now();
        DB::connection('central')->table('landing_how_it_works_section')->insert([
            'section_label'    => 'How It Works',
            'section_title'    => 'Transparent & fair for every customer',
            'section_subtitle' => 'Here is how plans, pricing, and your data are handled — no surprises.',
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $steps = [
            ['title' => 'Plan updates apply instantly',  'description' => 'When new features or higher limits are added to your plan, you get immediate access. No need to re-subscribe or wait — changes are live in real time for all users on the plan.', 'icon' => 'bi bi-arrow-repeat',   'icon_color' => 'green',  'sort_order' => 1],
            ['title' => 'Price changes are fair',         'description' => 'If a plan price changes, your current billing period stays at the old rate. The new price only kicks in on your next renewal, so you are never charged more mid-cycle.',           'icon' => 'bi bi-credit-card',    'icon_color' => 'blue',   'sort_order' => 2],
            ['title' => 'Plans cannot disappear on you',  'description' => 'A plan with active subscribers can never be deleted. If a plan is retired, it is simply hidden from new signups — your access and features continue without interruption.',        'icon' => 'bi bi-shield-check',   'icon_color' => 'amber',  'sort_order' => 3],
            ['title' => 'Your data is isolated & secure',  'description' => 'Every workspace gets its own dedicated database. Your inventory, customers, and reports are completely separated from other businesses on the platform.',                          'icon' => 'bi bi-database-lock',  'icon_color' => 'purple', 'sort_order' => 4],
            ['title' => 'Try before you buy',              'description' => 'Start with a free trial — full access to all features, no credit card required. When the trial ends, simply pick a plan to continue. Your data is preserved.',                   'icon' => 'bi bi-clock-history',  'icon_color' => 'teal',   'sort_order' => 5],
            ['title' => 'Upgrade or downgrade anytime',    'description' => 'Switch plans whenever you need to. Upgrading gives you instant access to new features. Downgrading takes effect at the end of your current billing period.',                     'icon' => 'bi bi-arrow-up-circle','icon_color' => 'red',    'sort_order' => 6],
        ];

        foreach ($steps as $step) {
            DB::connection('central')->table('landing_how_it_works_steps')->insert(array_merge($step, [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_how_it_works_steps');
        Schema::connection('central')->dropIfExists('landing_how_it_works_section');
    }
}
