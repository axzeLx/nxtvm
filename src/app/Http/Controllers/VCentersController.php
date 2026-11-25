<?php

namespace App\Http\Controllers;

use App\Models\Inv;
use App\Models\VCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Home page: one card per vCenter with its key figures. */
class VCentersController extends Controller
{
    public function index(): View
    {
        $vcenters = VCenter::ordered()->get();

        // Per-vCenter aggregates in a handful of grouped queries
        $hosts = Inv\Host::groupBy('vcenter_id')->select('vcenter_id',
            DB::raw('count(*) n'), DB::raw("sum(case when connection_state <> 'connected' then 1 else 0 end) disconnected"),
            DB::raw('sum(case when maintenance then 1 else 0 end) maint'),
            DB::raw('sum(cpu_mhz::bigint * cpu_cores) cpu_total'), DB::raw('sum(cpu_usage_mhz) cpu_used'),
            DB::raw('sum(mem_bytes) mem_total'), DB::raw('sum(mem_usage_mb::bigint) * 1048576 mem_used'),
            DB::raw('count(distinct build) builds'),
            DB::raw('sum(cpu_sockets) cpus'), DB::raw('sum(cpu_cores) cores'),
            // per-core licensing: each CPU counts at least license_min_cores (same rule as Host::licensedCores)
            DB::raw('sum(case when cpu_sockets > 0 then cpu_sockets * greatest('.(int) config('nxtvm.license_min_cores').', cpu_cores / cpu_sockets) else cpu_cores end) lic_cores'))
            ->get()->keyBy('vcenter_id');
        $vms = Inv\Vm::groupBy('vcenter_id')->select('vcenter_id',
            DB::raw('sum(case when not template then 1 else 0 end) n'), DB::raw("sum(case when power_state = 'poweredOn' and not template then 1 else 0 end) on_n"),
            DB::raw('sum(case when template then 1 else 0 end) templates'), DB::raw('sum(snapshot_count) snaps'),
            DB::raw('sum(case when template then 0 else vcpu end) vcpu'), DB::raw('sum(case when template then 0 else mem_mb::bigint end) mem_mb'))
            ->get()->keyBy('vcenter_id');
        $ds = Inv\Datastore::groupBy('vcenter_id')->select('vcenter_id', DB::raw('count(*) n'),
            DB::raw('sum(capacity_bytes) cap'), DB::raw('sum(free_bytes) free'), DB::raw('sum(provisioned_bytes) prov'))->get()->keyBy('vcenter_id');
        $simple = fn ($model) => $model::groupBy('vcenter_id')->select('vcenter_id', DB::raw('count(*) n'))->pluck('n', 'vcenter_id');
        $certSoon = Inv\Certificate::where('valid_to', '<', now()->addDays(config('nxtvm.cert_warn_days')))
            ->groupBy('vcenter_id')->select('vcenter_id', DB::raw('count(*) n'))->pluck('n', 'vcenter_id');

        return view('vcenters.index', [
            'vcenters' => $vcenters,
            'hosts' => $hosts, 'vms' => $vms, 'ds' => $ds,
            'clusters' => $simple(Inv\Cluster::class), 'networks' => $simple(Inv\Network::class),
            'alarms' => $simple(Inv\Alarm::class), 'certSoon' => $certSoon,
        ]);
    }
}
