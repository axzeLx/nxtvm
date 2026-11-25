<?php

namespace App\Http\Controllers;

use App\Models\Inv;
use App\Support\VcScope;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Dashboard of the vCenter selected in the vCenter bar: vCenter, settings, clusters, hosts, datastores, VMs. */
class DashboardController extends Controller
{
    public function index(VcScope $scope): View
    {
        $vc = $scope->current();
        if (! $vc) {
            // "All vCenters" selected: the dashboard is per vCenter, let the user pick one
            return view('dashboard.pick', ['vcenters' => $scope->vcenters()]);
        }
        $id = $vc->id;

        $hosts = Inv\Host::where('vcenter_id', $id)->get()->sortBy('name', SORT_NATURAL)->values();
        $vmsPerHost = Inv\Vm::where('vcenter_id', $id)->where('template', false)->groupBy('host_moid')
            ->select('host_moid', DB::raw("sum(case when power_state = 'poweredOn' then 1 else 0 end) on_n"), DB::raw('count(*) n'))
            ->get()->keyBy('host_moid');

        $vms = Inv\Vm::where('vcenter_id', $id)->select(
            DB::raw('sum(case when not template then 1 else 0 end) n'),
            DB::raw("sum(case when not template and power_state = 'poweredOn' then 1 else 0 end) on_n"),
            DB::raw("sum(case when not template and power_state = 'poweredOff' then 1 else 0 end) off_n"),
            DB::raw("sum(case when not template and power_state = 'suspended' then 1 else 0 end) susp_n"),
            DB::raw('sum(case when template then 1 else 0 end) templates'),
            DB::raw("sum(case when data->>'connection_state' = 'orphaned' then 1 else 0 end) orphaned"),
            DB::raw("sum(case when data->>'connection_state' in ('inaccessible', 'invalid', 'disconnected') then 1 else 0 end) inaccessible"),
            DB::raw("sum(case when not template and power_state = 'poweredOn' and coalesce(tools_running, '') <> 'guestToolsRunning' then 1 else 0 end) tools_not"),
            DB::raw('sum(case when snapshot_count > 0 then 1 else 0 end) with_snap'),
            DB::raw('sum(case when template then 0 else vcpu end) vcpu'),
            DB::raw('sum(case when template then 0 else mem_mb::bigint end) mem_mb'),
        )->first();

        $osDist = Inv\Vm::where('vcenter_id', $id)->where('template', false)
            ->groupBy('guest_os')->select('guest_os', DB::raw('count(*) n'),
                DB::raw("sum(case when power_state = 'poweredOn' then 1 else 0 end) on_n"))
            ->orderByDesc('n')->orderBy('guest_os')->get();

        $datastores = Inv\Datastore::where('vcenter_id', $id)->get()
            ->sortByDesc(fn ($d) => $d->capacity_bytes ? 1 - $d->free_bytes / $d->capacity_bytes : 0)->values();

        return view('dashboard.index', [
            'vc' => $vc,
            'clusters' => Inv\Cluster::where('vcenter_id', $id)->get()->sortBy('name', SORT_NATURAL)->values(),
            'hosts' => $hosts,
            'vmsPerHost' => $vmsPerHost,
            'vms' => $vms,
            'osDist' => $osDist,
            'datastores' => $datastores,
            'alarms' => Inv\Alarm::where('vcenter_id', $id)->orderByDesc('triggered_at')->get(),
            'certSoon' => Inv\Certificate::where('vcenter_id', $id)->where('valid_to', '<', now()->addDays(config('nxtvm.cert_warn_days')))->count(),
        ]);
    }
}
