<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings;
use App\Http\Controllers\VCentersController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ResolveVcScope;
use App\Support\VcScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [VCentersController::class, 'index'])->name('vcenters');

    // Everything about one vCenter (or "all") lives under /vc/{id|all}/…: the vCenter is the parent of its sections.
    Route::prefix('vc/{vc}')->where(['vc' => '[0-9]+|all'])->middleware(ResolveVcScope::class)->group(function () {
        Route::get('/', fn (string $vc) => redirect()->route('dashboard', ['vc' => $vc]));
        Route::get('overview', [DashboardController::class, 'index'])->name('dashboard');

        Route::controller(InventoryController::class)->group(function () {
            Route::get('hosts', 'hosts')->name('hosts');
            Route::get('hosts/{moid}', 'host')->whereNumber('vc')->name('hosts.show');
            Route::get('vms', 'vms')->name('vms');
            Route::get('vms/{moid}', 'vm')->whereNumber('vc')->name('vms.show');
            Route::get('datastores', 'datastores')->name('datastores');
            Route::get('datastores/{moid}', 'datastore')->whereNumber('vc')->name('datastores.show');
            Route::get('networks', 'networks')->name('networks');
            Route::get('networks/{moid}', 'network')->whereNumber('vc')->name('networks.show');
            Route::get('licenses', 'licenses')->name('licenses');
            Route::get('certificates', 'certificates')->name('certificates');
            Route::get('components', 'components')->name('components');
        });

        Route::get('checkup', [CheckupController::class, 'index'])->name('checkup');
        Route::post('checkup/run', [CheckupController::class, 'run'])->middleware(EnsureAdmin::class)->name('checkup.run');
        Route::get('checkup/license', [CheckupController::class, 'license'])->name('checkup.license');
    });

    // Old flat URLs (/hosts?vc=3, /hosts/3/host-12, /dashboard) → their place under /vc/{vc}
    $legacy = ['dashboard' => 'dashboard', 'hosts' => 'hosts', 'vms' => 'vms', 'datastores' => 'datastores', 'networks' => 'networks',
        'licenses' => 'licenses', 'certificates' => 'certificates', 'components' => 'components', 'checkup' => 'checkup'];
    foreach ($legacy as $path => $name) {
        Route::get($path, fn (Request $r, VcScope $scope) => redirect()->route($name, ['vc' => $scope->key()] + $r->except('vc'), 301));
    }
    Route::get('{section}/{vc}/{moid}', fn (string $section, int $vc, string $moid) => redirect()->route("$section.show", compact('vc', 'moid'), 301))
        ->whereIn('section', ['hosts', 'vms', 'datastores'])->whereNumber('vc');

    Route::get('/about', AboutController::class)->name('about');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/appearance', [ProfileController::class, 'appearance'])->name('profile.appearance');

    Route::middleware(EnsureAdmin::class)->prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/settings/vcenters')->name('index');
        Route::post('vcenters/test', [Settings\VCenterController::class, 'testForm'])->name('vcenters.test-form');
        Route::post('vcenters/collect', [Settings\VCenterController::class, 'collectAll'])->name('vcenters.collect-all');
        Route::get('vcenters/collect-status', [Settings\VCenterController::class, 'collectStatus'])->name('vcenters.collect-status');
        Route::post('vcenters/{vcenter}/move', [Settings\VCenterController::class, 'move'])->name('vcenters.move');
        Route::post('vcenters/{vcenter}/collect', [Settings\VCenterController::class, 'collect'])->name('vcenters.collect');
        Route::post('vcenters/{vcenter}/test', [Settings\VCenterController::class, 'test'])->name('vcenters.test');
        Route::resource('vcenters', Settings\VCenterController::class)->except('show');
        Route::resource('users', Settings\UserController::class)->except('show');
        Route::get('checkup', [Settings\CheckupController::class, 'edit'])->name('checkup');
        Route::put('checkup', [Settings\CheckupController::class, 'update']);
        Route::get('ai', [Settings\AiController::class, 'edit'])->name('ai');
        Route::put('ai', [Settings\AiController::class, 'update']);
        Route::get('license', [Settings\LicenseController::class, 'index'])->name('license');
        Route::put('license', [Settings\LicenseController::class, 'update'])->name('license.update');
        Route::delete('license', [Settings\LicenseController::class, 'destroy'])->name('license.destroy');
        Route::redirect('modules', '/settings/license', 301);
        Route::get('audit', [Settings\AuditController::class, 'index'])->name('audit');
    });
});

// Vendor-only extension, not part of the distribution: loaded only where it is mounted (docker-compose.override.yml
// on the vendor's machine). Elsewhere these routes do not exist.
if (is_file('/opt/nxtvm-vendor/app/routes.php')) {
    require '/opt/nxtvm-vendor/app/routes.php';
}
