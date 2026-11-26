<?php

namespace App\Checkup;

use App\Models\Inv;
use Illuminate\Database\Eloquent\Model;

/** What a check found: number of objects examined and the findings. */
final class Result
{
    public int $total = 0;

    public ?string $na = null;

    /** Short summary shown when the check passes ("no overcommitted datastore …"). */
    public ?string $note = null;

    /** @var list<array{severity: string, object_type: string, object_name: ?string, object_moid: ?string, message: string}> */
    public array $findings = [];

    private const TYPES = [
        Inv\Host::class => 'host', Inv\Vm::class => 'vm', Inv\Cluster::class => 'cluster', Inv\Datastore::class => 'datastore',
        Inv\Network::class => 'network', Inv\DvSwitch::class => 'dvswitch', Inv\Certificate::class => 'certificate',
        Inv\License::class => 'license', Inv\Alarm::class => 'alarm',
    ];

    public function __construct(private readonly string $severity, public readonly string $vcName = '') {}

    /** Number of objects the check examined (the denominator of the affected share). */
    public function total(int $n): self
    {
        $this->total = $n;

        return $this;
    }

    /** The check could not be evaluated (no data, no permission, …). */
    public function na(string $reason): self
    {
        $this->na = $reason;

        return $this;
    }

    public function note(string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function add(string $type, ?string $name, ?string $moid, string $message, ?string $severity = null): self
    {
        $this->findings[] = [
            'severity' => $severity ?? $this->severity, 'object_type' => $type,
            'object_name' => $name !== null ? mb_substr($name, 0, 255) : null, 'object_moid' => $moid, 'message' => $message,
        ];

        return $this;
    }

    /** Finding about an inventory object. */
    public function obj(Model $m, string $message, ?string $severity = null): self
    {
        return $this->add(self::TYPES[$m::class] ?? 'object', $m->name ?? $m->alarm ?? $m->owner ?? null, $m->moid ?? null, $message, $severity);
    }

    public function count(string $severity): int
    {
        return count(array_filter($this->findings, fn ($f) => $f['severity'] === $severity));
    }
}
