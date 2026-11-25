<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editions and licensed add-on modules.
 *
 *   free      no valid license and the trial is over: licensed modules are off
 *   trial     the first TRIAL_DAYS days after installation: every module is on
 *   licensed  a valid license key for this machine: the modules it names are on
 *
 * A license key is "NXTVM2.<payload>.<signature>" (base64url). The payload is JSON
 *   {"v": 2, "lic": licensee, "mid": machine code, "mod": [modules], "exp": "YYYY-MM-DD"|null, "iat": "YYYY-MM-DD"}
 * signed with the vendor's Ed25519 key. Only the public key below ships with the application; keys are issued
 * with the vendor tool (license-tool/, outside the application) that holds the private key.
 *
 * The machine code binds a key to one installation on one host: it is derived from the host's /etc/machine-id
 * (mounted read-only into the containers) and a random installation id created on first start. Moving the
 * database to another host, or installing anew, gives another code — the key does not carry over.
 */
class Modules
{
    public const CHECKUP = 'checkup';

    public const TRIAL_DAYS = 30;

    private const PREFIX = 'NXTVM2';

    /** Vendor public key (Ed25519, base64). Deliberately not configurable: whoever could change it could sign keys. */
    private const PUBLIC_KEY = '+qjXDYF11cEFooYODB/bMKh7bEQe//wIVdZEDnZ8NpQ=';

    /** Host machine id, mounted from the host (docker-compose.yml). */
    private const HOST_MACHINE_ID = '/etc/host-machine-id';

    private const SETTING = 'license_key';

    private ?array $license = null;

    private bool $loaded = false;

    private ?array $install = null;

    /** @return array<string, array{label: string, description: string}> */
    public static function all(): array
    {
        return [
            self::CHECKUP => [
                'label' => __('Checkup'),
                'description' => __('Health checks, scoring and recommendations for the collected vCenter inventory.'),
            ],
        ];
    }

    public function enabled(string $module): bool
    {
        if ($this->edition() === 'licensed') {
            return in_array($module, $this->license()['mod'], true);
        }

        return $this->edition() === 'trial';
    }

    /** 'licensed' | 'trial' | 'free' */
    public function edition(): string
    {
        $l = $this->license();
        if ($l && $l['machine_ok'] && ! $l['expired']) {
            return 'licensed';
        }

        return $this->trialDaysLeft() > 0 ? 'trial' : 'free';
    }

    /** Whole days of the trial left (0 when over). */
    public function trialDaysLeft(): int
    {
        $end = $this->trialEnds();

        return ! $end || $end->isPast() ? 0 : (int) ceil(now()->floatDiffInDays($end));
    }

    /** End of the trial; null when the installation date is missing or was altered (the trial counts as over). */
    public function trialEnds(): ?CarbonImmutable
    {
        return $this->installation()['installed_at']?->addDays(self::TRIAL_DAYS);
    }

    /** When this installation was first started (null if the record is missing or was altered). */
    public function installedAt(): ?CarbonImmutable
    {
        return $this->installation()['installed_at'];
    }

    /**
     * The code a license is issued for, "XXXX-XXXX-XXXX-XXXX"; null when the host machine id is not available
     * (the containers miss the /etc/host-machine-id mount).
     */
    public function machineCode(): ?string
    {
        $hostId = is_readable(self::HOST_MACHINE_ID) ? trim((string) file_get_contents(self::HOST_MACHINE_ID)) : '';
        if ($hostId === '') {
            return null;
        }
        $hash = hash('sha256', 'nxtvm-machine|'.$hostId.'|'.$this->installation()['id'], true);

        return implode('-', str_split(self::base32(substr($hash, 0, 10)), 4));
    }

    /**
     * The stored license (decoded, with 'expired' and 'machine_ok' flags) or null if none / not genuine.
     * A genuine key for another machine is returned with machine_ok = false (shown, but not in effect).
     */
    public function license(): ?array
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $key = Setting::get(self::SETTING);
            $data = $key ? self::decode($key) : null;
            $this->license = $data ? $data + ['machine_ok' => $this->machineCode() !== null && hash_equals($this->machineCode(), $data['mid'])] : null;
        }

        return $this->license;
    }

    public function store(?string $key): void
    {
        Setting::put(self::SETTING, $key);
        $this->loaded = false;
    }

    /** Verify a license key; returns the payload (+ 'expired') or null when malformed / not genuine. */
    public static function decode(string $key): ?array
    {
        $parts = explode('.', trim(preg_replace('/\s+/', '', $key)));
        $pub = base64_decode(self::PUBLIC_KEY, true);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $payload = self::b64d($parts[1]);
        $sig = self::b64d($parts[2]);
        if ($payload === null || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, self::PREFIX.'.'.$parts[1], $pub)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || ! is_array($data['mod'] ?? null) || ! is_string($data['mid'] ?? null)) {
            return null;
        }

        $exp = ! empty($data['exp']) ? CarbonImmutable::parse($data['exp'])->endOfDay() : null;

        return [
            'lic' => (string) ($data['lic'] ?? ''),
            'mid' => $data['mid'],
            'mod' => array_values(array_filter($data['mod'], 'is_string')),
            'exp' => $exp,
            'iat' => ! empty($data['iat']) ? CarbonImmutable::parse($data['iat']) : null,
            'expired' => $exp !== null && $exp->isPast(),
        ];
    }

    /**
     * Installation id and date, created together on first use. The date is sealed with the app key: an edited or
     * missing date (while the id exists) counts as an ended trial.
     *
     * @return array{id: string, installed_at: ?CarbonImmutable}
     */
    private function installation(): array
    {
        if ($this->install) {
            return $this->install;
        }
        $read = fn () => DB::table('app_settings')->whereIn('key', ['install_id', 'installed_at'])->pluck('value', 'key');
        $rows = $read();
        if (! isset($rows['install_id'])) {
            $id = (string) Str::uuid();
            $at = now()->toImmutable()->startOfSecond();
            DB::table('app_settings')->insertOrIgnore([
                ['key' => 'install_id', 'value' => $id, 'updated_at' => now()],
                ['key' => 'installed_at', 'value' => $at->toIso8601String().'|'.$this->seal($at->toIso8601String(), $id), 'updated_at' => now()],
            ]);
            $rows = $read();   // another request may have won the race: use what is stored
        }

        $id = (string) $rows['install_id'];
        [$date, $mac] = array_pad(explode('|', (string) ($rows['installed_at'] ?? '')), 2, '');
        $valid = $date !== '' && hash_equals($this->seal($date, $id), $mac);

        return $this->install = [
            'id' => $id,
            'installed_at' => $valid ? CarbonImmutable::parse($date) : null,
        ];
    }

    private function seal(string $date, string $id): string
    {
        return hash_hmac('sha256', 'nxtvm-install|'.$date.'|'.$id, (string) config('app.key'));
    }

    private static function base32(string $bin): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no I, O, 0, 1: easy to read out and type
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function b64d(string $s): ?string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);

        return $r === false ? null : $r;
    }
}
