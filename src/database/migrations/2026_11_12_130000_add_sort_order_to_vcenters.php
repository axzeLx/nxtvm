<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vcenters', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('id');
        });
        // keep the current (alphabetical) order as the starting point
        foreach (DB::table('vcenters')->orderBy('name')->pluck('id') as $i => $id) {
            DB::table('vcenters')->where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
        }
    }

    public function down(): void
    {
        Schema::table('vcenters', fn (Blueprint $table) => $table->dropColumn('sort_order'));
    }
};
