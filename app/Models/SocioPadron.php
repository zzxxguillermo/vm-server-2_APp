<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SocioPadron extends Model
{
    use HasFactory;

    protected $table = 'socios_padron';

    protected $fillable = [
        'dni',
        'sid',
        'apynom',
        'barcode',
        'saldo',
        'semaforo',
        'ult_impago',
        'acceso_full',
        'hab_controles',
        'hab_controles_raw',
        'raw',
    ];

    protected $casts = [
        'saldo' => 'decimal:2',
        'semaforo' => 'integer',
        'ult_impago' => 'integer',
        'acceso_full' => 'boolean',
        'hab_controles' => 'boolean',
        'hab_controles_raw' => 'array',
        'raw' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Buscar un socio por DNI o SID
     */
    public static function findByDniOrSid(string $value): ?self
    {
        return static::where('dni', $value)
            ->orWhere('sid', $value)
            ->first();
    }

    /**
     * Buscar un socio por barcode
     */
    public static function findByBarcode(string $barcode): ?self
    {
        return static::where('barcode', $barcode)->first();
    }
}
