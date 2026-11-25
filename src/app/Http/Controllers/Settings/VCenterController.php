<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\VCenter;
use App\Services\Collector\Collector;
use App\Services\Collector\CollectorException;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VCenterController extends Controller
{
    public function index(): View
    {
        return view('settings.vcenters.index', ['vcenters' => VCenter::ordered()->get()]);
    }

    public function create(): View
    {
        return view('settings.vcenters.form', ['vcenter' => new VCenter([
            'port' => 443, 'verify_ssl' => false, 'is_enabled' => true,
            'interval_minutes' => config('nxtvm.interval.default'),
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vcenter = VCenter::create($this->validated($request));
        $vcenter->forceFill(['sort_order' => (int) VCenter::max('sort_order') + 10])->save(); // new ones go last
        Audit::log('vcenter.create', $vcenter->name, ['host' => $vcenter->host, 'username' => $vcenter->username]);

        return redirect()->route('settings.vcenters.index')->with('status', __('vCenter added. Run a connection test.'));
    }

    public function edit(VCenter $vcenter): View
    {
        return view('settings.vcenters.form', ['vcenter' => $vcenter]);
    }

    public function update(Request $request, VCenter $vcenter): RedirectResponse
    {
        $data = $this->validated($request, $vcenter);
        $vcenter->update($data);
        Audit::log('vcenter.update', $vcenter->name, Audit::changes($vcenter) + (isset($data['password']) ? ['password_changed' => true] : []));

        return redirect()->route('settings.vcenters.index')->with('status', __('vCenter saved.'));
    }

    public function destroy(VCenter $vcenter): RedirectResponse
    {
        $vcenter->delete();
        Audit::log('vcenter.delete', $vcenter->name);

        return redirect()->route('settings.vcenters.index')->with('status', __('vCenter deleted.'));
    }

    /** Move a vCenter one place up or down in the display order (dashboard, selectors, lists). */
    public function move(Request $request, VCenter $vcenter): RedirectResponse
    {
        $list = VCenter::ordered()->get()->values();
        $i = $list->search(fn (VCenter $vc) => $vc->is($vcenter));
        $j = $request->input('dir') === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $list->count()) {
            $items = $list->all();
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
            foreach ($items as $n => $vc) {
                $vc->forceFill(['sort_order' => ($n + 1) * 10])->save();
            }
        }

        return redirect()->route('settings.vcenters.index');
    }

    /** Queue a collection now (the worker runs it within seconds). JSON for the collect button, redirect otherwise. */
    public function collect(Request $request, VCenter $vcenter): RedirectResponse|JsonResponse
    {
        $queued = $vcenter->queueCollect();
        if ($queued) {
            Audit::log('vcenter.collect', $vcenter->name);
        }
        if ($request->wantsJson()) {
            return response()->json(['state' => $vcenter->collectState(), 'queued' => $queued]);
        }

        return back()->with('status', $queued
            ? __('Collection of :name queued.', ['name' => $vcenter->name])
            : __(':name is already being collected.', ['name' => $vcenter->name]));
    }

    /** Queue a collection of every enabled vCenter. */
    public function collectAll(Request $request): RedirectResponse|JsonResponse
    {
        $names = VCenter::where('is_enabled', true)->get()->filter(fn (VCenter $vc) => $vc->queueCollect())->pluck('name');
        if ($names->isNotEmpty()) {
            Audit::log('vcenter.collect', $names->join(', '));
        }
        if ($request->wantsJson()) {
            return response()->json(['queued' => $names->count()]);
        }

        return back()->with('status', __('Collection queued for :n vCenter(s).', ['n' => $names->count()]));
    }

    /** Collection progress of every vCenter, polled by the collect buttons. */
    public function collectStatus(): JsonResponse
    {
        return response()->json(VCenter::all()->mapWithKeys(fn (VCenter $vc) => [$vc->id => [
            'state' => $vc->collectState(),
            'last_success_at' => $vc->last_success_at?->toIso8601String(),
            'error' => $vc->last_error,
        ]]));
    }

    /** Test a saved vCenter with its stored credentials; the result is kept on the record. */
    public function test(VCenter $vcenter, Collector $collector): JsonResponse
    {
        $result = $this->runTest($collector, $vcenter->connection());

        $vcenter->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $result['ok'],
            'last_test_message' => $result['message'],
        ] + ($result['ok'] ? ['about' => $result['facts'], 'ip' => $result['facts']['ip'] ?? null] : []))->save();
        Audit::log('vcenter.test', $vcenter->name, ['ok' => $result['ok']]);

        return response()->json($result);
    }

    /** Test the values currently in the form (not saved). An empty password falls back to the stored one. */
    public function testForm(Request $request, Collector $collector): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:vcenters,id'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        $password = $data['password'] ?? null;
        if (! $password && ! empty($data['id'])) {
            $password = VCenter::find($data['id'])->password;
        }
        if (! $password) {
            return response()->json(['ok' => false, 'message' => __('Enter the password to test.')]);
        }

        return response()->json($this->runTest($collector, [
            'host' => trim($data['host']), 'port' => (int) $data['port'], 'user' => $data['username'],
            'password' => $password, 'verify_ssl' => $request->boolean('verify_ssl'),
        ]));
    }

    private function runTest(Collector $collector, array $connection): array
    {
        try {
            $out = $collector->test($connection);
        } catch (CollectorException $e) {
            return ['ok' => false, 'kind' => $e->kind, 'message' => $e->getMessage()];
        }

        $a = $out['about'];
        $priv = $out['privileges'] ?? null;
        $cert = $out['certificate'] ?? null;

        return [
            'ok' => true,
            'message' => __('Connected to :name :version (build :build) as :user.', [
                'name' => $a['name'], 'version' => $a['version'], 'build' => $a['build'], 'user' => $out['user'],
            ]),
            'details' => array_filter([
                __('Datacenters') => $out['datacenters'],
                __('Response time') => $out['elapsed_ms'].' ms',
                __('Account') => $priv === null ? __('privileges could not be read')
                    : ($priv['read_only'] ? __('read-only') : __('NOT read-only (:n extra privileges)', ['n' => count($priv['extra'])])),
                __('Certificate') => $cert ? __(':cn, expires :date (:days days)', [
                    'cn' => $cert['subject'], 'date' => substr($cert['valid_to'], 0, 10), 'days' => $cert['days_left'],
                ]) : null,
            ], fn ($v) => $v !== null),
            'warnings' => array_values(array_filter([
                $out['warning'] ?? null,
                $priv && ! $priv['read_only'] ? __('The account has write privileges. nxtVM only reads; a Read-only role is recommended.') : null,
            ])),
            'facts' => $a + ['ip' => $out['ip'] ?? null, 'certificate' => $cert, 'read_only' => $priv['read_only'] ?? null],
        ];
    }

    private function validated(Request $request, ?VCenter $vcenter = null): array
    {
        $i = config('nxtvm.interval');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('vcenters', 'name')->ignore($vcenter)],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:190'],
            'password' => [$vcenter ? 'nullable' : 'required', 'string', 'max:255'],
            'interval_minutes' => ['required', 'integer', 'between:'.$i['min'].','.$i['max']],
            'location' => ['nullable', 'string', 'max:64'],
        ]);
        $data['host'] = trim($data['host']);
        $data['verify_ssl'] = $request->boolean('verify_ssl');
        $data['is_enabled'] = $request->boolean('is_enabled');
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
