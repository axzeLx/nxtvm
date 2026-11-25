<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class VCenter extends Model
{
    protected $table = 'vcenters';

    protected $fillable = [
        'name', 'host', 'port', 'username', 'password', 'verify_ssl', 'interval_minutes', 'is_enabled', 'location',
    ];

    protected $hidden = ['password'];

    /** Display order: the order set in Settings → vCenters, then name. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'verify_ssl' => 'boolean',
            'is_enabled' => 'boolean',
            'about' => 'array',
            'inventory' => 'array',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'last_collect_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** Connection settings handed to the Python collector (stdin). */
    public function connection(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->username,
            'password' => $this->password,
            'verify_ssl' => $this->verify_ssl,
        ];
    }

    /** ok | crit | unknown — for the status dot */
    /** Collection progress kept in the cache: 'queued' | 'running' | null (idle). */
    public function collectState(): ?string
    {
        return Cache::get(self::collectKey($this->id));
    }

    public static function setCollectState(int $id, ?string $state): void
    {
        $state === null
            ? Cache::forget(self::collectKey($id))
            : Cache::put(self::collectKey($id), $state, config('nxtvm.collect_timeout') + 180);
    }

    /** Queue a collection unless one is already queued or running. Returns false when it was already pending. */
    public function queueCollect(): bool
    {
        if ($this->collectState()) {
            return false;
        }
        self::setCollectState($this->id, 'queued');
        \App\Jobs\CollectVCenter::dispatch($this->id);

        return true;
    }

    private static function collectKey(int $id): string
    {
        return "nxtvm:collect:{$id}";
    }

    public function status(): string
    {
        if (! $this->is_enabled) {
            return 'unknown';
        }
        if ($this->last_error) {
            return 'crit';
        }
        if ($this->last_success_at) {
            return 'ok';
        }

        return match ($this->last_test_ok) {
            true => 'ok',
            false => 'crit',
            null => 'unknown',
        };
    }
}
