<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $table = 'sync_states';
    
    public $timestamps = false;
    
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener el valor de un sync state
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $record = static::where('key', $key)->first();
        return $record?->value ?? $default;
    }

    /**
     * Actualizar o crear un sync state
     */
    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)]
        );
    }

    /**
     * Obtener el timestamp del último sync
     */
    public static function getLastSyncTimestamp(string $key): ?\DateTime
    {
        $record = static::where('key', $key)->first();
        return $record?->updated_at;
    }
}
