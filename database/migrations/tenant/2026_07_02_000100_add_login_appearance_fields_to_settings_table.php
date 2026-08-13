<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login-page appearance fields saved by the Appearance Settings tab
     * (SettingsController::update_appearance_settings). The create_settings
     * migration only ships the hero/panel title+subtitle; these six were
     * referenced by the code without any migration.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'login_hero_badge')) {
                $table->string('login_hero_badge')->nullable()->after('login_panel_subtitle');
            }
            if (! Schema::hasColumn('settings', 'login_hero_feature_1')) {
                $table->string('login_hero_feature_1')->nullable()->after('login_hero_badge');
            }
            if (! Schema::hasColumn('settings', 'login_hero_feature_2')) {
                $table->string('login_hero_feature_2')->nullable()->after('login_hero_feature_1');
            }
            if (! Schema::hasColumn('settings', 'login_hero_feature_3')) {
                $table->string('login_hero_feature_3')->nullable()->after('login_hero_feature_2');
            }
            if (! Schema::hasColumn('settings', 'login_btn_text')) {
                $table->string('login_btn_text')->nullable()->after('login_hero_feature_3');
            }
            if (! Schema::hasColumn('settings', 'login_footer_text')) {
                $table->string('login_footer_text')->nullable()->after('login_btn_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['login_hero_badge', 'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3', 'login_btn_text', 'login_footer_text'] as $col) {
                if (Schema::hasColumn('settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
