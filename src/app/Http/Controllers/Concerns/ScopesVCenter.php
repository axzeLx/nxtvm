<?php

namespace App\Http\Controllers\Concerns;

use App\Support\VcScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Inventory pages show the vCenter selected in the vCenter bar (remembered per user), or all of them. */
trait ScopesVCenter
{
    /** vCenters in the current scope, keyed by id. Views show a vCenter column only when this holds more than one. */
    protected function vcenters(): Collection
    {
        return app(VcScope::class)->inScope();
    }

    protected function selectedVc(): ?int
    {
        return app(VcScope::class)->id();
    }

    protected function scoped(Builder $q): Builder
    {
        return $q->when($this->selectedVc(), fn ($q, $vc) => $q->where('vcenter_id', $vc));
    }
}
