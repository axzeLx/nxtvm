<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vcenters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();          // display name, e.g. VC01
            $table->string('host');                         // FQDN or IP
            $table->unsignedInteger('port')->default(443);
            $table->string('username', 190);
            $table->text('password');                       // encrypted with APP_KEY
            $table->boolean('verify_ssl')->default(false);
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->boolean('is_enabled')->default(true);
            $table->string('location', 64)->nullable();     // free text, e.g. "Main DC"

            // Facts from the last successful connection (about, resolved IP, certificate)
            $table->json('about')->nullable();
            $table->string('ip', 45)->nullable();

            // Last connection test
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_message')->nullable();

            // Collection state (filled from step 4 on)
            $table->timestamp('last_collect_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vcenters');
    }
};
