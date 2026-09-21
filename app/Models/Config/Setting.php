<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        $row = static::where('key', $key)->first();
        return $row ? $row->value : $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}