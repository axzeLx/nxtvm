<?php

namespace App\Jobs;

use App\Models\VCenter;
use App\Services\Collector\Collector;
use App\Services\Collector\CollectorException;
use App\Services\Collector\Ingestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One full inventory collection of a vCenter. */
class CollectVCenter implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $vcenterId)
    {
        $this->onQueue('collect');
        $this->timeout = config('nxtvm.collect_timeout') + 60;
    }

    public function uniqueId(): string
    {
        return (string) $this->vcenterId;
    }

    public int $uniqueFor = 900;

    public function handle(Collector $collector, Ingestor $ingestor): void
    {
        $vc = VCenter::find($this->vcenterId);
        if (! $vc) {
            return;
        }
        $vc->forceFill(['last_collect_at' => now()])->save();
        VCenter::setCollectState($vc->id, 'running');

        try {
            $out = $collector->run('collect', $vc->connection());
            $this->keepRaw($vc, $out);
            $ingestor->ingest($vc, $out);
            $vc->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
        } catch (ProcessTimedOutException) {
            $vc->forceFill(['last_error' => 'Collection timed out after '.config('nxtvm.collect_timeout').' s.'])->save();
        } catch (CollectorException $e) {
            $vc->forceFill(['last_error' => $e->getMessage()])->save();
        } catch (Throwable $e) {
            Log::error('Collection failed', ['vcenter' => $vc->name, 'exception' => $e]);
            $vc->forceFill(['last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000)])->save();
        } finally {
            VCenter::setCollectState($vc->id, null);
        }
    }

    /** Last raw collector output (gzip) for troubleshooting; license keys are already masked. */
    private function keepRaw(VCenter $vc, array $out): void
    {
        $dir = storage_path('app/private/raw');
        @mkdir($dir, 0770, true);
        file_put_contents("$dir/vcenter-{$vc->id}.json.gz", gzencode(json_encode($out, JSON_UNESCAPED_SLASHES), 6));
    }
}
