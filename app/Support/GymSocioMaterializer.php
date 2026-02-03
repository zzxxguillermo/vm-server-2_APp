<?php

namespace App\Support;

use App\Models\SocioPadron;
use App\Models\User;
use App\Enums\UserType;
use Illuminate\Support\Str;

class GymSocioMaterializer
{
    /**
     * Materializar un socio del padrón hacia la tabla de usuarios
     * Busca por DNI o SID y crea/actualiza el User correspondiente
     * 
     * @param string $value DNI o SID del socio
     * @return User Usuario creado o actualizado
     * @throws \Exception Si el socio no existe en el padrón
     */
    public static function materializeByDniOrSid(string $value): User
    {
        // Buscar en padrón
        $socio = SocioPadron::findByDniOrSid($value);
        
        if (!$socio) {
            throw new \Exception("Socio con DNI/SID '{$value}' no encontrado en padrón");
        }

        // Extraer nombre y apellido
        $nombre = '';
        $apellido = '';
        
        if ($socio->apynom) {
            // Formato esperado: "APELLIDO, NOMBRE"
            $parts = explode(',', $socio->apynom);
            $apellido = trim($parts[0] ?? '');
            $nombre = trim($parts[1] ?? '');
        } elseif ($socio->raw) {
            // Intentar extraer de raw
            $nombre = $socio->raw['nombre'] ?? '';
            $apellido = $socio->raw['apellido'] ?? '';
        }

        $fullName = trim("{$apellido} {$nombre}");

        // Crear o actualizar el usuario
        return User::updateOrCreate(
            ['dni' => $socio->dni],
            [
                'user_type' => UserType::API,
                'socio_id' => $socio->sid,
                'socio_n' => $socio->sid,
                'barcode' => $socio->barcode,
                'saldo' => $socio->saldo,
                'semaforo' => $socio->semaforo,
                'estado_socio' => $socio->acceso_full ? 'ACTIVO' : 'INACTIVO',
                'api_updated_at' => now(),
                'name' => $fullName,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'email' => self::generateEmailFromDni($socio->dni),
                'password' => bcrypt(Str::random(16)), // Password aleatorio
            ]
        );
    }

    /**
     * Materializar múltiples socios (sin hacer upsert en masa)
     * Útil para procesos en background
     * 
     * @param array $dniOrSidList Lista de DNI o SID
     * @return array Array con usuarios materializados y errores
     */
    public static function materializeMultiple(array $dniOrSidList): array
    {
        $materialized = [];
        $errors = [];

        foreach ($dniOrSidList as $value) {
            try {
                $materialized[] = self::materializeByDniOrSid($value);
            } catch (\Exception $e) {
                $errors[$value] = $e->getMessage();
            }
        }

        return [
            'materialized' => $materialized,
            'errors' => $errors,
            'total' => count($materialized),
            'failed' => count($errors),
        ];
    }

    /**
     * Generar un email sintético desde DNI si no existe
     */
    protected static function generateEmailFromDni(string $dni): string
    {
        $user = User::where('dni', $dni)->first();
        if ($user && $user->email) {
            return $user->email;
        }

        return "socio.{$dni}@gimnasio.local";
    }

    /**
     * Sincronizar padrón completo a usuarios (sin crear todos, solo asociar existentes)
     * Útil para reconciliación
     */
    public static function syncExistingUsers(): array
    {
        $stats = [
            'updated' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        SocioPadron::chunkById(100, function ($socios) use (&$stats) {
            foreach ($socios as $socio) {
                try {
                    // Si el usuario ya existe (por email o dni), solo actualizar
                    $user = User::where('dni', $socio->dni)->first();
                    
                    if ($user) {
                        $user->update([
                            'socio_id' => $socio->sid,
                            'socio_n' => $socio->sid,
                            'barcode' => $socio->barcode,
                            'saldo' => $socio->saldo,
                            'semaforo' => $socio->semaforo,
                            'estado_socio' => $socio->acceso_full ? 'ACTIVO' : 'INACTIVO',
                            'api_updated_at' => now(),
                        ]);
                        $stats['updated']++;
                    } else {
                        // No crear automáticamente, solo marcar para sincronizar
                        $stats['skipped']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = [
                        'dni' => $socio->dni,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return $stats;
    }
}
