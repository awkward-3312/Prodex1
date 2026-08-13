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
        Schema::create('knowledge_base_article_feedbacks', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('knowledge_base_article_id');
            $table->integer('user_id')->nullable()->index('kb_article_feedback_user_fk');
            $table->boolean('helpful');
            $table->timestamps();

            $table->index(['knowledge_base_article_id', 'user_id'], 'kb_article_feedback_article_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_article_feedbacks');
    }
};
