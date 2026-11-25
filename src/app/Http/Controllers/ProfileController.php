<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(10)],
        ]);

        $user = $request->user();
        $user->fill(['name' => $data['name'], 'email' => $data['email'] ?? null]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();
        Audit::log(empty($data['password']) ? 'profile.update' : 'profile.password', $user->username, Audit::changes($user));

        return back()->with('status', __('Profile saved.'));
    }

    /** Palette / light-dark choice from the header picker (JSON, no audit entry — cosmetic). */
    public function appearance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(array_keys(config('nxtvm.themes')))],
            'mode' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
        ]);
        $request->user()->forceFill($data)->save();

        return response()->json(['ok' => true]);
    }
}
