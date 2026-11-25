<?php

namespace App\Services\Collector;

use Illuminate\Support\Facades\Process;

/** Runs the Python vCenter collector (pyVmomi) and decodes its JSON output. */
class Collector
{
    /**
     * @param  array{host:string,port:int,user:string,password:string,verify_ssl:bool}  $connection
     */
    public function run(string $mode, array $connection, ?int $timeout = null): array
    {
        $timeout ??= $mode === 'test' ? config('nxtvm.test_timeout') : config('nxtvm.collect_timeout');
        $input = json_encode($connection + ['timeout' => min($timeout, 60)], JSON_THROW_ON_ERROR);

        $result = Process::timeout($timeout + 5)
            ->input($input)
            ->run([config('nxtvm.python'), config('nxtvm.collector'), $mode]);

        $out = json_decode(trim($result->output()), true);

        if (! is_array($out)) {
            $err = trim($result->errorOutput()) ?: trim($result->output()) ?: 'no output';
            throw new CollectorException('Collector failed (exit '.$result->exitCode().'): '.mb_substr($err, -500));
        }
        if (! ($out['ok'] ?? false)) {
            throw new CollectorException($out['error'] ?? 'Unknown error', $out['kind'] ?? 'error');
        }

        return $out;
    }

    /** Connection test; the server certificate (DER) is decoded here. */
    public function test(array $connection): array
    {
        try {
            $out = $this->run('test', $connection);
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException) {
            throw new CollectorException('Timed out after '.config('nxtvm.test_timeout').' s.', 'timeout');
        }
        $out['certificate'] = Certificates::fromDer($out['certificate']['der_b64'] ?? null);
        $out['ip'] = filter_var($connection['host'], FILTER_VALIDATE_IP) ? $connection['host'] : (gethostbyname($connection['host']) ?: null);

        return $out;
    }
}
