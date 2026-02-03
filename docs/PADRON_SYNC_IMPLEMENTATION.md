# Padrón Sync - Implementación Completa

Este documento describe la implementación del sistema de sincronización de socios desde vmServer hacia la tabla local `socios_padron`.

## Estructura Implementada

### 1. Base de Datos

#### Tabla `socios_padron`
- **Modelo**: `App\Models\SocioPadron`
- **Migración**: `database/migrations/2026_02_03_000000_create_socios_padron_table.php`

Campos principales:
- `id`: Identificador primario
- `dni`: Número de documento (índice)
- `sid`: ID de socio del sistema (índice)
- `apynom`: Apellido y nombre
- `barcode`: Código de barras (índice único)
- `saldo`: Decimal(12,2) - saldo de la cuenta
- `semaforo`: Integer - estado del semáforo
- `ult_impago`: Integer - timestamp último impago
- `acceso_full`: Boolean - acceso completo
- `hab_controles`: Boolean - habilitación de controles
- `hab_controles_raw`: JSON - datos raw de controles
- `raw`: JSON - respuesta completa de vmServer
- `created_at`, `updated_at`: Auditoría

#### Tabla `sync_states`
- **Modelo**: `App\Models\SyncState`
- **Migración**: `database/migrations/2026_02_03_000001_create_sync_states_table.php`

Almacena estado de sincronizaciones (key-value) con timestamp:
- `key`: Identificador único (ej: 'padron_last_sync_at')
- `value`: Valor almacenado
- `updated_at`: Cuándo se actualizó

### 2. Configuración

#### `config/services.php`
Se agregó configuración para vmServer:

```php
'vmserver' => [
    'base_url' => env('VMSERVER_BASE_URL'),
    'admin_users_path' => env('VMSERVER_ADMIN_USERS_PATH', '/api/admin/users'),
    'timeout' => (int) env('VMSERVER_TIMEOUT', 10),
    'token' => env('VMSERVER_TOKEN'),
    'internal_token' => env('VMSERVER_INTERNAL_TOKEN'),
],
```

#### Variables de entorno (`.env`)
```dotenv
VMSERVER_BASE_URL=https://vmserver.ejemplo.com
VMSERVER_INTERNAL_TOKEN=tu_token_interno_secreto
VMSERVER_TIMEOUT=20
VMSERVER_TOKEN=token_opcional
VMSERVER_ADMIN_USERS_PATH=/api/admin/users
```

### 3. Servicios

#### `App\Services\VmServerPadronClient`
Cliente HTTP para comunicarse con vmServer:

```php
public function fetchSocios(array $params): array
```
- Parámetros: `updated_since`, `page`, `per_page`
- Devuelve respuesta decodificada con paginación
- Maneja errores con status y body de la respuesta

```php
public function fetchSocioByDni(string $dni): ?array
public function fetchSocioBySid(string $sid): ?array
```
- Métodos de conveniencia para búsquedas unitarias

### 4. Comando Artisan

#### `php artisan padron:sync`

**Opciones:**
- `--since=ISO_DATE`: Sincronizar desde fecha específica (default: last_sync o últimas 24h)
- `--per-page=NUMBER`: Registros por página (default: 500)

**Ejemplos de uso:**

```bash
# Sincronización normal (desde último sync registrado)
php artisan padron:sync

# Sincronización desde fecha específica
php artisan padron:sync --since="2026-02-01T00:00:00Z"

# Sincronización personalizada con más registros por página
php artisan padron:sync --per-page=1000

# Combinado
php artisan padron:sync --since="2026-02-01" --per-page=750
```

**Lógica del comando:**

1. Determina fecha `since` (opción, último sync, o 24h atrás)
2. Itera por páginas:
   - Llama `/api/internal/padron/socios?updated_since=since&page=page&per_page=per_page`
   - Mapea items a estructura local
   - **Upsert inteligente**:
     - Registros con `sid` → upsert usando `sid` como clave
     - Registros sin `sid` → upsert usando `dni` como clave
   - Guarda `hab_controles_raw` y `raw` como arrays JSON
3. Termina cuando `current_page >= last_page`
4. Actualiza `SyncState.padron_last_sync_at` con `server_time` o now()
5. Registra estadísticas en logs

**Output ejemplo:**
```
🔄 Iniciando sincronización de socios desde vmServer
  • Desde: 2026-02-02T10:30:00Z
  • Por página: 500

📄 Obteniendo página 1...
  ✓ Página 1: 485/500 upsertados
📄 Obteniendo página 2...
  ✓ Página 2: 342/500 upsertados

✅ Sincronización completada
  • Total procesados: 842
  • Total upsertados: 827
  • Último sync: 2026-02-03T11:45:23Z
```

### 5. Helper: GymSocioMaterializer

Clase estática para materializar socios del padrón a usuarios locales.

**Ubicación**: `App\Support\GymSocioMaterializer`

#### Método principal
```php
public static function materializeByDniOrSid(string $value): User
```
- Busca socio en `socios_padron` por DNI o SID
- Extrae nombre/apellido desde `apynom` o `raw`
- Crea/actualiza `User` con campos:
  - `user_type = UserType::API`
  - `socio_id` = sid del padrón
  - `socio_n` = sid del padrón
  - `barcode` = barcode del padrón
  - `saldo`, `semaforo`, `estado_socio`
  - `api_updated_at` = now()
  - `name`, `nombre`, `apellido`
- Genera email sintético si no existe

#### Método batch
```php
public static function materializeMultiple(array $dniOrSidList): array
```
- Materializa múltiples socios
- Devuelve array con materializados, errores y estadísticas
- Útil para sincronización en background

#### Método de reconciliación
```php
public static function syncExistingUsers(): array
```
- Sincroniza usuarios existentes con datos del padrón
- NO crea usuarios nuevos automáticamente
- Devuelve estadísticas de actualización

### 6. Uso en Controladores

**Ejemplo: Asignar socio a profesor**

```php
<?php

namespace App\Http\Controllers;

use App\Support\GymSocioMaterializer;
use App\Models\User;
use Illuminate\Http\Request;

class ProfessorSocioController extends Controller
{
    public function assignSocio(Request $request, User $professor)
    {
        $validated = $request->validate([
            'dni_or_sid' => 'required|string',
        ]);

        // Materializar socio desde padrón
        try {
            $socio = GymSocioMaterializer::materializeByDniOrSid(
                $validated['dni_or_sid']
            );
            
            // Aquí realizar la asignación específica
            $professor->assignedSocios()->attach($socio->id);

            return response()->json([
                'message' => 'Socio asignado exitosamente',
                'socio' => $socio,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo materializar el socio: ' . $e->getMessage(),
            ], 404);
        }
    }

    public function syncProfessorSocios(User $professor)
    {
        // Sincronizar usuarios locales con el padrón
        $stats = GymSocioMaterializer::syncExistingUsers();

        return response()->json([
            'message' => 'Sincronización completada',
            'stats' => $stats,
        ]);
    }
}
```

## Scheduler (Opcional)

### Configuración en `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Sincronizar padrón cada 2 horas
    $schedule->command('padron:sync')
        ->everyTwoHours()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->name('padron-sync')
        ->description('Sincronizar socios desde vmServer');
}
```

Para que funcione, debe ejecutarse:
```bash
php artisan schedule:run
```

O configurar cron:
```bash
* * * * * cd /ruta/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## Instalación y Setup

### 1. Ejecutar migraciones
```bash
php artisan migrate
```

Esto crea:
- Tabla `socios_padron`
- Tabla `sync_states`

### 2. Configurar variables de entorno

En `.env`:
```dotenv
VMSERVER_BASE_URL=https://vmserver.example.com
VMSERVER_INTERNAL_TOKEN=tu_token_secreto
VMSERVER_TIMEOUT=20
```

### 3. Ejecutar primer sync
```bash
php artisan padron:sync
```

## Notas Técnicas

### Estrategia de Upsert
El comando separa registros en dos grupos:
1. **Con SID**: Usa SID como clave única (preferible si existe)
2. **Sin SID**: Usa DNI como clave única

Esto permite actualizar registros correctamente incluso si SID no está disponible inicialmente.

### Almacenamiento de Raw
Cada registro guarda:
- `raw`: Respuesta completa de la API (JSON)
- `hab_controles_raw`: Datos de controles si vienen anidados

Esto permite:
- Auditoría completa de sincronizaciones
- Recuperación de datos que puedan ser necesarios después
- Debug de problemas de mapeo

### Manejo de Errores
- El cliente lanza `RuntimeException` si hay error en vmServer
- El comando captura excepciones y registra en logs
- El scheduler usa `withoutOverlapping()` para evitar carreras

### Performance
- Paginación eficiente (default 500 por página)
- Índices en campos de búsqueda frecuente
- `chunkById()` en operaciones batch
- Sin N+1 queries

## Troubleshooting

### Error: "VMSERVER_BASE_URL is not configured"
Verificar que `.env` tiene:
```dotenv
VMSERVER_BASE_URL=https://...
VMSERVER_INTERNAL_TOKEN=...
```

### No hay resultados en sincronización
1. Verificar endpoint: `/api/internal/padron/socios`
2. Verificar token interno
3. Probar manualmente:
```bash
curl -H "X-Internal-Token: token" https://vmserver/api/internal/padron/socios?per_page=1
```

### Materializer lanza "Socio no encontrado"
- Primero ejecutar `php artisan padron:sync`
- Verificar que SocioPadron existe:
```bash
php artisan tinker
> \App\Models\SocioPadron::where('dni', '1234567')->first()
```

## Próximos Pasos

1. Integrar con endpoint de asignación de socios a profesores
2. Crear webhook para cambios en vmServer
3. Implementar reconciliación automática en scheduler
4. Agregar monitoreo de sincronización en admin panel
