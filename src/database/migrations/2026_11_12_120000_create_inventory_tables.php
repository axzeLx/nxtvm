<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inventory = latest state only. Every collection replaces all rows of a vCenter (no change history).
 * Columns are what lists sort / filter on; the full collected record is in "data".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vcenters', function (Blueprint $table) {
            $table->json('inventory')->nullable()->after('about');   // counts, datacenters, settings, appliance, warnings
            $table->unsignedInteger('collect_ms')->nullable()->after('last_error');
        });

        Schema::create('inv_clusters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('datacenter')->nullable();
            $t->boolean('ha_enabled')->default(false);
            $t->boolean('drs_enabled')->default(false);
            $t->unsignedInteger('num_hosts')->default(0);
            $t->unsignedBigInteger('total_cpu_mhz')->nullable();
            $t->unsignedBigInteger('total_mem_bytes')->nullable();
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
        });

        Schema::create('inv_hosts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('cluster_moid', 64)->nullable();
            $t->string('cluster')->nullable();
            $t->string('datacenter')->nullable();
            $t->string('connection_state', 24)->nullable();
            $t->string('power_state', 24)->nullable();
            $t->boolean('maintenance')->default(false);
            $t->string('overall_status', 12)->nullable();
            $t->string('vendor')->nullable();
            $t->string('model')->nullable();
            $t->string('serial')->nullable();
            $t->string('cpu_model')->nullable();
            $t->unsignedSmallInteger('cpu_sockets')->nullable();
            $t->unsignedSmallInteger('cpu_cores')->nullable();
            $t->unsignedSmallInteger('cpu_threads')->nullable();
            $t->unsignedInteger('cpu_mhz')->nullable();
            $t->unsignedBigInteger('mem_bytes')->nullable();
            $t->string('version', 32)->nullable();
            $t->string('build', 32)->nullable();
            $t->timestamp('boot_time')->nullable();
            $t->unsignedInteger('cpu_usage_mhz')->nullable();
            $t->unsignedInteger('mem_usage_mb')->nullable();
            $t->unsignedInteger('vm_count')->default(0);
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
        });

        Schema::create('inv_vms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('host_moid', 64)->nullable();
            $t->string('host')->nullable();
            $t->string('cluster')->nullable();
            $t->string('datacenter')->nullable();
            $t->string('folder')->nullable();
            $t->string('power_state', 24)->nullable();
            $t->boolean('template')->default(false);
            $t->string('guest_os')->nullable();
            $t->unsignedSmallInteger('vcpu')->nullable();
            $t->unsignedInteger('mem_mb')->nullable();
            $t->string('hw_version', 16)->nullable();
            $t->string('tools_running', 32)->nullable();
            $t->string('tools_status', 32)->nullable();
            $t->string('ip', 64)->nullable();
            $t->unsignedBigInteger('provisioned_bytes')->nullable();
            $t->unsignedBigInteger('used_bytes')->nullable();
            $t->unsignedSmallInteger('snapshot_count')->default(0);
            $t->unsignedBigInteger('snapshot_bytes')->default(0);
            $t->string('overall_status', 12)->nullable();
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
            $t->index(['vcenter_id', 'name']);
        });

        Schema::create('inv_datastores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('datacenter')->nullable();
            $t->string('type', 16)->nullable();
            $t->unsignedBigInteger('capacity_bytes')->default(0);
            $t->unsignedBigInteger('free_bytes')->default(0);
            $t->unsignedBigInteger('provisioned_bytes')->default(0);
            $t->boolean('accessible')->default(true);
            $t->unsignedInteger('host_count')->default(0);
            $t->unsignedInteger('vm_count')->default(0);
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
        });

        // Standard port groups (vim.Network) and distributed port groups in one list
        Schema::create('inv_networks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('kind', 12);                 // standard | distributed
            $t->string('switch')->nullable();       // dvSwitch name (standard: per host, see data)
            $t->string('vlan', 64)->nullable();     // single value, "mixed", trunk ranges
            $t->unsignedInteger('host_count')->default(0);
            $t->unsignedInteger('vm_count')->default(0);
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
        });

        Schema::create('inv_dvswitches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('moid', 64);
            $t->string('name');
            $t->string('version', 16)->nullable();
            $t->unsignedInteger('mtu')->nullable();
            $t->unsignedInteger('host_count')->default(0);
            $t->jsonb('data');
            $t->unique(['vcenter_id', 'moid']);
        });

        Schema::create('inv_licenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('license_key', 64);          // masked: XXXXX-…-ABCDE
            $t->string('name');
            $t->string('edition')->nullable();
            $t->integer('total')->nullable();
            $t->integer('used')->nullable();
            $t->string('unit', 32)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('assigned_count')->default(0);
            $t->jsonb('data');
        });

        Schema::create('inv_certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('scope', 16);                // vcenter | host | root
            $t->string('owner');                    // vCenter / host name
            $t->string('owner_moid', 64)->nullable();
            $t->string('subject')->nullable();
            $t->string('issuer')->nullable();
            $t->timestamp('valid_from')->nullable();
            $t->timestamp('valid_to')->nullable();
            $t->jsonb('data');
        });

        Schema::create('inv_alarms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vcenter_id')->constrained('vcenters')->cascadeOnDelete();
            $t->string('alarm');
            $t->string('entity_moid', 64)->nullable();
            $t->string('entity')->nullable();
            $t->string('entity_type', 48)->nullable();
            $t->string('status', 12);
            $t->timestamp('triggered_at')->nullable();
            $t->boolean('acknowledged')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['inv_alarms', 'inv_certificates', 'inv_licenses', 'inv_dvswitches', 'inv_networks', 'inv_datastores', 'inv_vms', 'inv_hosts', 'inv_clusters'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('vcenters', fn (Blueprint $t) => $t->dropColumn(['inventory', 'collect_ms']));
    }
};
