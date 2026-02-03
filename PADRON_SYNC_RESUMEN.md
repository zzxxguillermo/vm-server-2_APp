# PADRON SYNC - RESUMEN EJECUTIVO

## ✅ Implementación Completada

Se ha implementado un sistema completo de sincronización de socios desde vmServer hacia la tabla local `socios_padron`, siguiendo el patrón del Bridge de Piletas.

## 📦 Archivos Entregados

### 1. Migraciones
- ✅ `database/migrations/2026_02_03_000000_create_socios_padron_table.php` - Tabla de padrón de socios
- ✅ `database/migrations/2026_02_03_000001_create_sync_states_table.php` - Tabla de estados de sync

### 2. Modelos
- ✅ `app/Models/SocioPadron.php` - Modelo para tabla socios_padron
- ✅ `app/Models/SyncState.php` - Modelo para tabla sync_states

### 3. Configuración
- ✅ `config/services.php` - Actualizado con config de vmserver + internal_token
- ✅ `.env.example` - Agregadas variables VMSERVER_*

### 4. Servicios
- ✅ `app/Services/VmServerPadronClient.php` - Cliente HTTP con token interno

### 5. Commands
- ✅ `app/Console/Commands/PadronSyncCommand.php` - Comando padron:sync
- ✅ `app/Console/Kernel.php` - Configuración de scheduler

### 6. Helpers
- ✅ `app/Support/GymSocioMaterializer.php` - Materializar socios a usuarios

### 7. Documentación
- ✅ `docs/PADRON_SYNC_IMPLEMENTATION.md` - Documentación técnica completa
- ✅ `PADRON_SYNC_USAGE_EXAMPLES.php` - Ejemplos de uso (60+ líneas)

## 🚀 Modo de Uso

### 1. Ejecutar Migraciones
```bash
php artisan migrate
```

### 2. Configurar Variables de Entorno
```dotenv
VMSERVER_BASE_URL=https://vmserver.ejemplo.com
VMSERVER_INTERNAL_TOKEN=tu_token_secreto
VMSERVER_TIMEOUT=20
```

### 3. Sincronizar Socios
```bash
# Normal (desde último sync)
php artisan padron:sync

# Desde fecha específica
php artisan padron:sync --since="2026-02-01T00:00:00Z"

# Con opciones personalizadas
php artisan padron:sync --since="2026-02-01" --per-page=1000
```

### 4. Materializar Socios (On-Demand)
```php
use App\Support\GymSocioMaterializer;

// Convertir socio del padrón a usuario
$user = GymSocioMaterializer::materializeByDniOrSid('12345678');
```

## 🔑 Características

### Client HTTP (VmServerPadronClient)
- ✅ Usa Http client con baseUrl, timeout y headers
- ✅ Token interno en header X-Internal-Token
- ✅ Manejo de errores con status + body
- ✅ Métodos: fetchSocios(), fetchSocioByDni(), fetchSocioBySid()

### Comando Padron Sync
- ✅ Paginación automática con per_page personalizable
- ✅ Upsert inteligente:
  - Registros con SID → key: sid
  - Registros sin SID → key: dni
- ✅ Almacenamiento de raw + hab_controles_raw como arrays JSON
- ✅ Actualización automática de last_sync en SyncState
- ✅ Logging de estadísticas por página
- ✅ Manejo robusto de errores

### GymSocioMaterializer
- ✅ Materializar on-demand: materializeByDniOrSid($dni_or_sid)
- ✅ Batch: materializeMultiple($dni_list)
- ✅ Reconciliación: syncExistingUsers()
- ✅ Extrae nombre/apellido de apynom o raw
- ✅ Genera email sintético
- ✅ Crea/actualiza User con user_type=API

### SyncState (Tabla Key-Value)
- ✅ Almacena última fecha de sync
- ✅ Helpers: getValue(), setValue(), getLastSyncTimestamp()
- ✅ Persistencia entre ejecuciones

### Scheduler (Opcional)
- ✅ Configured para ejecutar cada 2 horas
- ✅ Con withoutOverlapping() para evitar carreras
- ✅ Identificado como 'padron-sync'

## 📊 Estructura de Datos

### Tabla: socios_padron
```
- id (PK)
- dni (INDEX)
- sid (INDEX)
- apynom
- barcode (UNIQUE INDEX)
- saldo (DECIMAL 12,2)
- semaforo (INT)
- ult_impago (INT)
- acceso_full (BOOL)
- hab_controles (BOOL)
- hab_controles_raw (JSON)
- raw (JSON) ← Respuesta completa
- created_at, updated_at
```

### Tabla: sync_states
```
- id (PK)
- key (UNIQUE INDEX)
- value (TEXT)
- updated_at
```

## 🔄 Flujo de Sincronización

```
1. Determinar "since"
   └─ Opción: --since=fecha
   └─ SyncState: padron_last_sync_at
   └─ Default: 24 horas atrás

2. Paginar desde vmServer
   └─ GET /api/internal/padron/socios
   └─ Headers: X-Internal-Token
   └─ Params: updated_since, page, per_page

3. Mapear items
   └─ Extraer: dni, sid, apynom, barcode, saldo, semaforo, etc.
   └─ Guardar raw JSON + hab_controles_raw

4. Upsert inteligente
   ├─ Registros con SID
   │  └─ upsert(..., ['sid'], [...])
   └─ Registros sin SID
      └─ upsert(..., ['dni'], [...])

5. Actualizar SyncState
   └─ padron_last_sync_at = server_time || now()

6. Log estadísticas
   └─ Página X: Y/Z upsertados
```

## 🛠️ Extensiones Posibles

1. **Webhook desde vmServer** - Notificar cambios en tiempo real
2. **Reconciliación automática** - En scheduler cada 6 horas
3. **Monitoreo en admin panel** - Estadísticas de sync
4. **Caché de queries** - Redis para búsquedas frecuentes
5. **Notificaciones Slack** - Alertas de errores de sync

## 📋 Checklist de Implementación

- ✅ Migraciones creadas
- ✅ Modelos configurados
- ✅ Config/services.php actualizado
- ✅ .env.example con nuevas variables
- ✅ VmServerPadronClient implementado
- ✅ PadronSyncCommand con lógica completa
- ✅ SyncState para persistencia
- ✅ GymSocioMaterializer para materialización on-demand
- ✅ Kernel.php con scheduler configurado
- ✅ Documentación técnica completa
- ✅ Ejemplos de uso extensivos

## 🚦 Próximos Pasos Recomendados

1. Configurar variables de entorno en .env
2. Ejecutar `php artisan migrate`
3. Ejecutar `php artisan padron:sync` (primera sincronización)
4. Verificar datos en tabla `socios_padron`
5. Probar materialización: `php artisan tinker` → `GymSocioMaterializer::materializeByDniOrSid('...')`
6. Integrar en endpoint de asignación de socios a profesores
7. Configurar scheduler en cron si se desea sincronización automática

## 💡 Notas Importantes

- **No crea usuarios masivamente**: El sync solo crea la tabla padrón
- **Materialización on-demand**: Los usuarios se crean cuando se necesita (asignar a profesor, etc.)
- **Upsert inteligente**: Maneja correctamente socios con y sin SID
- **Raw JSON**: Permite auditoría y recuperación de datos
- **Token interno**: Se usa en header X-Internal-Token (no en query string)
- **Paginación**: Configurable, default 500 por página
- **Scheduler opcional**: Se puede ejecutar manualmente o automáticamente

---

**Implementación completada**: 3 Febrero 2026 ✅
