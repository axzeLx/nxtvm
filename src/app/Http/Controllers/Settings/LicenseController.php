<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → License: edition (free / trial / licensed), how to get a license (machine code), the license key. */
class LicenseController extends Controller
{
    public function index(Modules $modules): View
    {
        return view('settings.license', [
            'modules' => $modules,
            'license' => $modules->license(),
            // a key is stored but is not a genuine current-format key (e.g. an old NXTVM1 key)
            'storedInvalid' => ! $modules->license() && \App\Models\Setting::get('license_key') !== null,
        ]);
    }

    public function update(Request $request, Modules $modules): RedirectResponse
    {
        $key = trim((string) $request->validate(['license_key' => ['required', 'string', 'max:4000']])['license_key']);
        $license = Modules::decode($key);
        if (! $license) {
            return back()->withInput()->withErrors(['license_key' => __('This license key is not valid.')]);
        }
        $code = $modules->machineCode();
        if ($code === null || ! hash_equals($code, $license['mid'])) {
            return back()->withInput()->withErrors(['license_key' => __('This license key was issued for another machine (:code).', ['code' => $license['mid']])]);
        }

        $modules->store(preg_replace('/\s+/', '', $key));
        Audit::log('license.update', $license['lic'], ['modules' => $license['mod'], 'expires' => $license['exp']?->toDateString()]);

        return redirect()->route('settings.license')->with('status', $license['expired']
            ? __('License key saved, but it has expired.')
            : __('License key saved.'));
    }

    public function destroy(Modules $modules): RedirectResponse
    {
        $lic = $modules->license()['lic'] ?? null;
        $modules->store(null);
        Audit::log('license.delete', $lic);

        return redirect()->route('settings.license')->with('status', __('License key removed; licensed modules are disabled.'));
    }
}
