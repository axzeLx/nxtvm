<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('settings.users.index', [
            'users' => User::orderBy('username')->get(),
        ]);
    }

    public function create(): View
    {
        return view('settings.users.form', ['user' => new User(['role' => User::ROLE_VIEWER, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::create($data);
        Audit::log('user.create', $user->username, ['role' => $user->role, 'is_active' => $user->is_active]);

        return redirect()->route('settings.users.index')->with('status', __('User created.'));
    }

    public function edit(User $user): View
    {
        return view('settings.users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        // Never lock the system out: keep at least one active admin.
        $losesAdmin = $user->isAdmin() && ($data['role'] !== User::ROLE_ADMIN || ! $data['is_active']);
        if ($losesAdmin && $this->activeAdminCount() <= 1) {
            return back()->withInput()->withErrors(['role' => __('At least one active administrator is required.')]);
        }

        $user->update($data);
        Audit::log('user.update', $user->username, Audit::changes($user) + (isset($data['password']) ? ['password_changed' => true] : []));

        return redirect()->route('settings.users.index')->with('status', __('User saved.'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => __('You cannot delete your own account.')]);
        }

        if ($user->isAdmin() && $user->is_active && $this->activeAdminCount() <= 1) {
            return back()->withErrors(['user' => __('At least one active administrator is required.')]);
        }

        $user->delete();
        Audit::log('user.delete', $user->username);

        return redirect()->route('settings.users.index')->with('status', __('User deleted.'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['username' => Str::lower((string) $request->input('username'))]);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(10)],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function activeAdminCount(): int
    {
        return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count();
    }
}
