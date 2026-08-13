<?php

declare(strict_types=1);

/**
 * Central (SaaS) super admin users. Not tenant-scoped.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralUsersTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('central_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('central_users');
    }
}
