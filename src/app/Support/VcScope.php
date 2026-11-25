<?php

namespace App\Support;

use App\Models\VCenter;
use Illuminate\Support\Collection;

/**
 * The vCenter the inventory pages are showing, remembered per user.
 *
 * The {vc} segment of /vc/{vc}/… (an id or 'all') selects (and stores) it; old-style ?vc= is still read by the
 * redirects from the flat URLs. Without either the stored one is used.
 * Nothing stored yet, or the stored vCenter was deleted → the first vCenter in the configured order.
 * Resolved once per request (bound as a scoped singleton).
 */
class VcScope
{
    private ?Collection $vcenters = null;

    private string|int|null $key = null;

    /** All vCenters in display order, keyed by id. */
    public function vcenters(): Collection
    {
        return $this->vcenters ??= VCenter::ordered()->get()->keyBy('id');
    }

    /** 'all' or a vCenter id. */
    public function key(): string|int|null
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $wanted = request()->route('vc') ?? request()->query('vc') ?? auth()->user()?->vcenter_scope;

        return $this->remember($this->normalize($wanted) ?? $this->vcenters()->keys()->first() ?? 'all');
    }

    /** Explicitly select a vCenter (detail pages select the vCenter of the object shown). */
    public function set(int $id): void
    {
        if ($this->vcenters()->has($id)) {
            $this->remember($id);
        }
    }

    /** Selected vCenter id, or null for all. */
    public function id(): ?int
    {
        $key = $this->key();

        return $key === 'all' ? null : $key;
    }

    public function current(): ?VCenter
    {
        return $this->id() ? $this->vcenters()->get($this->id()) : null;
    }

    /** vCenters in the current scope (one, or all). */
    public function inScope(): Collection
    {
        return $this->id() ? $this->vcenters()->only([$this->id()]) : $this->vcenters();
    }

    /** Use this key for the request and store it as the user's selection. */
    private function remember(string|int $key): string|int
    {
        $user = auth()->user();
        if ($user && (string) $user->vcenter_scope !== (string) $key) {
            $user->forceFill(['vcenter_scope' => (string) $key])->saveQuietly();
        }

        return $this->key = $key;
    }

    private function normalize(mixed $value): string|int|null
    {
        if ($value === 'all') {
            return $this->vcenters()->count() > 1 ? 'all' : null;
        }
        $id = (int) $value;

        return $id > 0 && $this->vcenters()->has($id) ? $id : null;
    }
}
