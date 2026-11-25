{{-- vCenter column, only meaningful when more than one vCenter is configured --}}
@if ($vcenters->count() > 1)<td class="text-xs text-muted whitespace-nowrap">{{ $vcenters[$row->vcenter_id]->name ?? '—' }}</td>@endif
