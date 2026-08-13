<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_base_article_feedbacks', function (Blueprint $table) {
            $table->foreign(['knowledge_base_article_id'], 'kb_article_feedbacks_article_id_foreign')->references(['id'])->on('knowledge_base_articles')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_base_article_feedbacks', function (Blueprint $table) {
            $table->dropForeign('kb_article_feedbacks_article_id_foreign');
            $table->dropForeign('knowledge_base_article_feedbacks_user_id_foreign');
        });
    }
};
