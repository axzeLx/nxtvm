<?php

namespace App\Services\Collector;

use App\Models\VCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Writes one collector result into the inventory tables. All rows of the vCenter are replaced
 * in one transaction, so readers always see either the previous or the new complete state.
 */
class Ingestor
{
    public function ingest(VCenter $vc, array $out): void
    {
        $id = $vc->id;
        $dcNames = collect($out['datacenters'] ?? [])->pluck('name', 'moid');
        $clusterNames = collect($out['clusters'] ?? [])->pluck('name', 'moid');
        $hostNames = collect($out['hosts'] ?? [])->pluck('name', 'moid');
        $dvsNames = collect($out['dvswitches'] ?? [])->pluck('name', 'moid');

        $rows = [
            'inv_clusters' => $this->clusters($id, $out, $dcNames),
            'inv_hosts' => $this->hosts($id, $out, $dcNames, $clusterNames),
            'inv_vms' => $this->vms($id, $out, $dcNames, $clusterNames, $hostNames),
            'inv_datastores' => $this->datastores($id, $out, $dcNames),
            'inv_networks' => $this->networks($id, $out, $dvsNames),
            'inv_dvswitches' => $this->dvswitches($id, $out),
            'inv_licenses' => $this->licenses($id, $out),
            'inv_certificates' => $this->certificates($id, $vc, $out),
            'inv_alarms' => $this->alarms($id, $out),
        ];

        DB::transaction(function () use ($vc, $rows, $out) {
            foreach ($rows as $table => $list) {
                DB::table($table)->where('vcenter_id', $vc->id)->delete();
                foreach (array_chunk($list, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            $vc->forceFill([
                'about' => array_merge($vc->about ?? [], $out['about'] ?? []),
                'inventory' => [
                    'collected_at' => $out['collected_at'] ?? null,
                    'user' => $out['user'] ?? null,
                    'datacenters' => $out['datacenters'] ?? [],
                    'counts' => array_map('count', Arr::only($rows, ['inv_clusters', 'inv_hosts', 'inv_vms', 'inv_datastores', 'inv_networks', 'inv_dvswitches', 'inv_licenses', 'inv_certificates', 'inv_alarms'])),
                    'settings' => $out['settings'] ?? [],
                    'appliance' => $this->appliance($out['appliance'] ?? []),
                    'license_evaluation' => $out['license_evaluation'] ?? null,
                    'warnings' => $out['warnings'] ?? [],
                    'timings_ms' => $out['timings_ms'] ?? [],
                ],
                'collect_ms' => $out['elapsed_ms'] ?? null,
            ])->save();
        });
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function ts(?string $iso): ?string
    {
        return $iso ? CarbonImmutable::parse($iso)->utc()->toDateTimeString() : null;
    }

    private function clusters(int $id, array $out, $dc): array
    {
        return array_map(fn ($c) => [
            'vcenter_id' => $id, 'moid' => $c['moid'], 'name' => $c['name'],
            'datacenter' => $dc[$c['datacenter']] ?? null,
            'ha_enabled' => $c['ha']['enabled'], 'drs_enabled' => $c['drs']['enabled'],
            'num_hosts' => $c['num_hosts'] ?? count($c['hosts']),
            'total_cpu_mhz' => $c['total_cpu_mhz'], 'total_mem_bytes' => $c['total_mem_bytes'],
            'data' => $this->json($c),
        ], $out['clusters'] ?? []);
    }

    private function hosts(int $id, array $out, $dc, $clusters): array
    {
        return array_map(fn ($h) => [
            'vcenter_id' => $id, 'moid' => $h['moid'], 'name' => $h['name'],
            'cluster_moid' => $h['cluster'], 'cluster' => $clusters[$h['cluster']] ?? null,
            'datacenter' => $dc[$h['datacenter']] ?? null,
            'connection_state' => $h['connection_state'], 'power_state' => $h['power_state'],
            'maintenance' => $h['maintenance'], 'overall_status' => $h['overall_status'],
            'vendor' => $h['vendor'], 'model' => $h['model'], 'serial' => $h['serial'],
            'cpu_model' => $h['cpu_model'], 'cpu_sockets' => $h['cpu_sockets'], 'cpu_cores' => $h['cpu_cores'],
            'cpu_threads' => $h['cpu_threads'], 'cpu_mhz' => $h['cpu_mhz'], 'mem_bytes' => $h['mem_bytes'],
            'version' => $h['version'], 'build' => $h['build'], 'boot_time' => $this->ts($h['boot_time']),
            'cpu_usage_mhz' => $h['cpu_usage_mhz'], 'mem_usage_mb' => $h['mem_usage_mb'], 'vm_count' => $h['vm_count'],
            'data' => $this->json(Arr::except($h, ['certificate_pem'])),
        ], $out['hosts'] ?? []);
    }

    private function vms(int $id, array $out, $dc, $clusters, $hosts): array
    {
        return array_map(fn ($v) => [
            'vcenter_id' => $id, 'moid' => $v['moid'], 'name' => $v['name'],
            'host_moid' => $v['host'], 'host' => $hosts[$v['host']] ?? null,
            'cluster' => $clusters[$v['cluster']] ?? null, 'datacenter' => $dc[$v['datacenter']] ?? null,
            'folder' => $v['folder'] ? mb_substr($v['folder'], 0, 255) : null,
            'power_state' => $v['power_state'], 'template' => $v['template'],
            'guest_os' => $v['guest_os'] ? mb_substr($v['guest_os'], 0, 255) : null,
            'vcpu' => $v['vcpu'], 'mem_mb' => $v['mem_mb'], 'hw_version' => $v['hw_version'],
            'tools_running' => $v['tools_running'], 'tools_status' => $v['tools_status'],
            'ip' => $v['ip'] ? mb_substr($v['ip'], 0, 64) : null,
            'provisioned_bytes' => ($v['committed_bytes'] ?? 0) + ($v['uncommitted_bytes'] ?? 0),
            'used_bytes' => $v['committed_bytes'] ?? 0,
            'snapshot_count' => count($v['snapshots']), 'snapshot_bytes' => $v['snapshot_bytes'] ?? 0,
            'overall_status' => $v['overall_status'],
            'data' => $this->json($v),
        ], $out['vms'] ?? []);
    }

    private function datastores(int $id, array $out, $dc): array
    {
        return array_map(fn ($d) => [
            'vcenter_id' => $id, 'moid' => $d['moid'], 'name' => $d['name'],
            'datacenter' => $dc[$d['datacenter']] ?? null, 'type' => $d['type'],
            'capacity_bytes' => $d['capacity_bytes'] ?? 0, 'free_bytes' => $d['free_bytes'] ?? 0,
            'provisioned_bytes' => max(0, $d['provisioned_bytes'] ?? 0), 'accessible' => $d['accessible'],
            'host_count' => count($d['hosts']), 'vm_count' => $d['vm_count'],
            'data' => $this->json($d),
        ], $out['datastores'] ?? []);
    }

    /** Standard networks get their VLAN / vSwitch per host from the hosts' port group config. */
    private function networks(int $id, array $out, $dvsNames): array
    {
        $perHost = [];   // port group name → host moid → [vlan, vswitch]
        foreach ($out['hosts'] ?? [] as $h) {
            foreach ($h['network']['portgroups'] ?? [] as $pg) {
                $perHost[$pg['name']][$h['moid']] = ['vlan' => $pg['vlan'], 'vswitch' => $pg['vswitch']];
            }
        }

        $rows = [];
        foreach ($out['networks'] ?? [] as $n) {
            $hosts = $perHost[$n['name']] ?? [];
            $vlans = array_values(array_unique(array_column($hosts, 'vlan')));
            $switches = array_values(array_unique(array_column($hosts, 'vswitch')));
            $rows[] = [
                'vcenter_id' => $id, 'moid' => $n['moid'], 'name' => $n['name'], 'kind' => 'standard',
                'switch' => count($switches) === 1 ? $switches[0] : (count($switches) ? 'mixed' : null),
                'vlan' => count($vlans) === 1 ? (string) $vlans[0] : (count($vlans) ? 'mixed' : null),
                'host_count' => count($n['hosts']), 'vm_count' => $n['vm_count'],
                'data' => $this->json($n + ['per_host' => $hosts]),
            ];
        }
        foreach ($out['dvportgroups'] ?? [] as $p) {
            if ($p['uplink']) {
                continue;
            }
            $rows[] = [
                'vcenter_id' => $id, 'moid' => $p['moid'], 'name' => $p['name'], 'kind' => 'distributed',
                'switch' => $dvsNames[$p['dvs']] ?? null,
                'vlan' => $p['vlan_type'] === 'vlan' ? $p['vlan'] : ($p['vlan'] ? $p['vlan_type'].' '.$p['vlan'] : null),
                'host_count' => count($p['hosts']), 'vm_count' => $p['vm_count'],
                'data' => $this->json($p),
            ];
        }

        return $rows;
    }

    private function dvswitches(int $id, array $out): array
    {
        return array_map(fn ($s) => [
            'vcenter_id' => $id, 'moid' => $s['moid'], 'name' => $s['name'], 'version' => $s['version'],
            'mtu' => $s['mtu'], 'host_count' => count($s['hosts']), 'data' => $this->json($s),
        ], $out['dvswitches'] ?? []);
    }

    /** Full license list when readable; otherwise licenses are derived from the assignments. */
    private function licenses(int $id, array $out): array
    {
        $assign = collect($out['license_assignments'] ?? [])->groupBy('key');
        $rows = [];
        foreach ($out['licenses'] ?? [] as $l) {
            if (($l['edition'] ?? null) === 'eval' && ! $l['total']) {
                continue;
            }
            $entities = ($assign[$l['key']] ?? collect())->values()->all();
            $rows[] = [
                'vcenter_id' => $id, 'license_key' => $l['key'], 'name' => $l['name'], 'edition' => $l['edition'],
                'total' => $l['total'], 'used' => $l['used'], 'unit' => $l['unit'], 'expires_at' => $this->ts($l['expires']),
                'assigned_count' => count($entities),
                'data' => $this->json($l + ['assigned' => $entities, 'source' => 'license_manager']),
            ];
        }
        if (! $rows) {
            foreach ($assign as $key => $entities) {
                $rows[] = [
                    'vcenter_id' => $id, 'license_key' => (string) $key, 'name' => $entities[0]['license_name'] ?? '—',
                    'edition' => null, 'total' => null, 'used' => null, 'unit' => null, 'expires_at' => null,
                    'assigned_count' => count($entities),
                    'data' => $this->json(['assigned' => $entities->values()->all(), 'source' => 'assignments']),
                ];
            }
        }

        return $rows;
    }

    private function certificates(int $id, VCenter $vc, array $out): array
    {
        $rows = [];
        $add = function (string $scope, string $owner, ?string $moid, ?array $c, array $extra = []) use (&$rows, $id) {
            if (! $c) {
                return;
            }
            $rows[] = [
                'vcenter_id' => $id, 'scope' => $scope, 'owner' => $owner, 'owner_moid' => $moid,
                'subject' => mb_substr((string) $c['subject'], 0, 255), 'issuer' => mb_substr((string) $c['issuer'], 0, 255),
                'valid_from' => $this->ts($c['valid_from']), 'valid_to' => $this->ts($c['valid_to']),
                'data' => $this->json($c + $extra),
            ];
        };

        $add('vcenter', $vc->name, null, Certificates::fromDer($out['certificate']['der_b64'] ?? null), ['role' => 'Machine SSL']);
        foreach ($out['hosts'] ?? [] as $h) {
            if (! empty($h['certificate_pem'])) {
                $add('host', $h['name'], $h['moid'], Certificates::fromPem($h['certificate_pem']));
            }
        }
        foreach ($out['appliance']['root_chain_pems'] ?? [] as $chain) {
            foreach ((array) $chain['pem'] as $pem) {
                $add('root', $vc->name, null, Certificates::fromPem($pem), ['role' => 'Trusted root', 'chain' => $chain['id']]);
            }
        }

        return $rows;
    }

    private function alarms(int $id, array $out): array
    {
        return array_map(fn ($a) => [
            'vcenter_id' => $id, 'alarm' => mb_substr($a['alarm'], 0, 255), 'entity_moid' => $a['entity'],
            'entity' => $a['entity_name'], 'entity_type' => $a['entity_type'], 'status' => $a['status'],
            'triggered_at' => $this->ts($a['time']), 'acknowledged' => $a['acknowledged'],
        ], $out['alarms'] ?? []);
    }

    /** Appliance REST results without bulky payloads; keeps which endpoints were readable. */
    private function appliance(array $a): array
    {
        $eps = [];
        foreach ($a['endpoints'] ?? [] as $name => $r) {
            $eps[$name] = $r['ok'] ? ['ok' => true, 'data' => $r['data']] : ['ok' => false, 'status' => $r['status'] ?? null];
        }

        return ['available' => $a['available'] ?? false, 'error' => $a['error'] ?? null, 'endpoints' => $eps];
    }
}
