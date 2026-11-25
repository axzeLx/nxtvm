<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Checkup results: only the latest run per vCenter is kept (no score history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkup_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->unique()->constrained('vcenters')->cascadeOnDelete();
            $t->timestamp('ran_at');
            $t->string('trigger', 16);                 // manual | schedule
            $t->unsignedTinyInteger('score')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamp('inventory_at')->nullable(); // inventory the run was based on
            $t->jsonb('results');                      // check key → status, counts, weight, score
        });

        Schema::create('checkup_findings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('check', 64);
            $t->string('category', 24);
            $t->string('severity', 8);                 // crit | warn | info
            $t->string('object_type', 24);
            $t->string('object_name')->nullable();
            $t->string('object_moid', 64)->nullable();
            $t->text('message');
            $t->index(['vcenter_id', 'check']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkup_findings');
        Schema::dropIfExists('checkup_runs');
    }
};
