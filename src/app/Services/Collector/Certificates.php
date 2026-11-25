<?php

namespace App\Services\Collector;

use Carbon\CarbonImmutable;

/** X.509 helpers: the collector returns raw certificates, PHP decodes them. */
class Certificates
{
    /** Base64 DER or PEM → summary (subject, issuer, validity, days left), or null. */
    public static function fromDer(?string $derB64): ?array
    {
        if (! $derB64) {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($derB64, 64, "\n")."-----END CERTIFICATE-----\n";

        return self::fromPem($pem);
    }

    public static function fromPem(string $pem): ?array
    {
        $c = @openssl_x509_parse($pem);
        if (! $c) {
            return null;
        }
        $to = CarbonImmutable::createFromTimestampUTC($c['validTo_time_t']);
        $san = $c['extensions']['subjectAltName'] ?? null;

        return [
            'subject' => $c['subject']['CN'] ?? self::dn($c['subject'] ?? []),
            'issuer' => $c['issuer']['CN'] ?? self::dn($c['issuer'] ?? []),
            'issuer_o' => $c['issuer']['O'] ?? null,
            'serial' => $c['serialNumberHex'] ?? null,
            'san' => $san,
            'valid_from' => CarbonImmutable::createFromTimestampUTC($c['validFrom_time_t'])->toIso8601String(),
            'valid_to' => $to->toIso8601String(),
            'days_left' => (int) floor(now()->diffInSeconds($to, false) / 86400),
            'fingerprint' => @openssl_x509_fingerprint($pem, 'sha256') ?: null,
        ];
    }

    /** ok | warn | crit by days left */
    public static function level(?int $daysLeft): string
    {
        return match (true) {
            $daysLeft === null => 'unknown',
            $daysLeft <= config('nxtvm.cert_crit_days') => 'crit',
            $daysLeft <= config('nxtvm.cert_warn_days') => 'warn',
            default => 'ok',
        };
    }

    private static function dn(array $parts): string
    {
        return collect($parts)->map(fn ($v, $k) => $k.'='.(is_array($v) ? implode('+', $v) : $v))->implode(', ');
    }
}
