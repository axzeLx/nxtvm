<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    /** Never stored in the audit details */
    private const SECRET = ['password', 'password_confirmation', 'current_password', 'private_key', '_token', '_method'];

    public static function log(string $action, ?string $subject = null, array $details = [], ?string $username = null): void
    {
        $user = Auth::user();
        AuditLog::create([
            'user_id' => $user?->id,
            'username' => $username ?? $user?->username,
            'action' => $action,
            'subject' => $subject ? mb_substr($subject, 0, 255) : null,
            'details' => array_diff_key($details, array_flip(self::SECRET)) ?: null,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /** Changed attributes of a model after fill()/update(), secrets removed. */
    public static function changes(\Illuminate\Database\Eloquent\Model $model): array
    {
        $out = [];
        foreach (array_diff_key($model->getChanges(), array_flip([...self::SECRET, 'updated_at', 'remember_token'])) as $k => $new) {
            $out[$k] = ['from' => $model->getPrevious()[$k] ?? null, 'to' => $new];
        }

        return $out;
    }
}
