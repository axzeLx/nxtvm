<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Application-wide key/value settings (e.g. the module license key)
        Schema::create('app_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
