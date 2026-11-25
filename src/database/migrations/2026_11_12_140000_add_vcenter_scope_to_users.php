<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembered vCenter selection for the inventory pages: null = not chosen yet, 'all', or a vCenter id
        Schema::table('users', fn (Blueprint $t) => $t->string('vcenter_scope', 16)->nullable()->after('mode'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('vcenter_scope'));
    }
};
