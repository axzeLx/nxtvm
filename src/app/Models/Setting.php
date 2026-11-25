<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Application-wide key/value setting. */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, ?string> per-request cache */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = static::find($key)?->value;
        }

        return self::$cache[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value === null) {
            static::whereKey($key)->delete();
        } else {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        self::$cache[$key] = $value;
    }
}
