# PADRON SYNC - QUICK REFERENCE

## 📌 Comandos Básicos

### Sincronizar
```bash
php artisan padron:sync
php artisan padron:sync --since="2026-02-01T00:00:00Z"
php artisan padron:sync --per-page=1000
```

### Verificar Instalación
```bash
php artisan tinker
> include 'quick_test_padron_sync.php'
```

### Ver Datos
```bash
php artisan tinker
> \App\Models\SocioPadron::count()
> \App\Models\SocioPadron::where('acceso_full', true)->count()
> \App\Models\SyncState::getValue('padron_last_sync_at')
```

---

## 🔧 Configuración Requerida

En `.env`:
```dotenv
VMSERVER_BASE_URL=https://vmserver.ejemplo.com
VMSERVER_INTERNAL_TOKEN=token_secreto
VMSERVER_TIMEOUT=20
```

---

## 💻 Código Frecuente

### Buscar Socio en Padrón
```php
// Por DNI o SID
$socio = \App\Models\SocioPadron::findByDniOrSid('12345678');

// Por barcode
$socio = \App\Models\SocioPadron::findByBarcode('BAR123');

// Query manual
$socio = \App\Models\SocioPadron::where('dni', '12345678')->first();
```

### Materializar Socio a User
```php
use App\Support\GymSocioMaterializer;

// Un socio
$user = GymSocioMaterializer::materializeByDniOrSid('12345678');

// Múltiples
$result = GymSocioMaterializer::materializeMultiple([
    '11111111', '22222222'
]);
// $result['materialized'], $result['errors'], $result['total']

// Sincronizar usuarios existentes
$stats = GymSocioMaterializer::syncExistingUsers();
```

### Usar SyncState
```php
use App\Models\SyncState;

// Leer
$lastSync = SyncState::getValue('padron_last_sync_at');

// Escribir
SyncState::setValue('key', 'value');

// Timestamp
$timestamp = SyncState::getLastSyncTimestamp('key');
```

### Consultas SocioPadron
```php
// Contar
$total = \App\Models\SocioPadron::count();

// Activos
$activos = \App\Models\SocioPadron::where('acceso_full', true)->get();

// Con deuda
$conDeuda = \App\Models\SocioPadron::where('saldo', '<', 0)->get();

// Con paginación
$page = \App\Models\SocioPadron::paginate(50);

// Acceder raw JSON
foreach ($socios as $socio) {
    $raw = $socio->raw; // Array completo
    $controls = $socio->hab_controles_raw;
}
```

---

## 🌐 API REST (Controller)

### Asignar Socio a Profesor
```http
POST /api/professors/1/assign-socio
Content-Type: application/json

{
  "dni_or_sid": "12345678"
}
```

### Buscar Socio
```http
GET /api/socios/search?q=12345678
```

### Asignar Múltiples
```http
POST /api/professors/1/assign-socios

{
  "dni_list": ["11111111", "22222222"]
}
```

### Listar Socios de Profesor
```http
GET /api/professors/1/socios
```

---

## 📁 Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `app/Models/SocioPadron.php` | Modelo de padrón |
| `app/Models/SyncState.php` | Almacenar estado de syncs |
| `app/Services/VmServerPadronClient.php` | Cliente HTTP |
| `app/Console/Commands/PadronSyncCommand.php` | Command de sync |
| `app/Support/GymSocioMaterializer.php` | Materializar socios |
| `app/Console/Kernel.php` | Scheduler |
| `config/services.php` | Configuración vmserver |
| `database/migrations/2026_02_03_000000_*` | Tabla socios_padron |
| `database/migrations/2026_02_03_000001_*` | Tabla sync_states |

---

## 🎯 Flujo Típico

```
1. Ejecutar sync:
   php artisan padron:sync

2. Materializar socio:
   $user = GymSocioMaterializer::materializeByDniOrSid('DNI')

3. Asignar a profesor:
   $professor->assignedSocios()->attach($user->id)
```

---

## 🐛 Errores Comunes

| Error | Solución |
|-------|----------|
| "VMSERVER_BASE_URL not configured" | Agregar a .env |
| "tabla no existe" | Ejecutar `php artisan migrate` |
| "Socio no encontrado" | Ejecutar `php artisan padron:sync` primero |
| "0 registros" | Verificar token y endpoint en vmServer |

---

## 📊 Estructura Tabla

### socios_padron
```sql
id, dni, sid, apynom, barcode, saldo, semaforo, 
ult_impago, acceso_full, hab_controles, 
hab_controles_raw (JSON), raw (JSON), 
created_at, updated_at
```

### sync_states
```sql
id, key, value, updated_at
```

---

## 🚀 Casos de Uso

```php
// Búsqueda rápida
$s = \App\Models\SocioPadron::findByDniOrSid('DNI');

// Materialización inmediata
$u = \App\Support\GymSocioMaterializer::materializeByDniOrSid('DNI');

// Sincronización batch
GymSocioMaterializer::syncExistingUsers();

// Check sincronización
SyncState::getValue('padron_last_sync_at');
```

---

## 🔑 Endpoint vmServer

**GET** `/api/internal/padron/socios`

**Headers:**
```
X-Internal-Token: token_secreto
Accept: application/json
```

**Query Params:**
- `updated_since`: ISO datetime (ej: 2026-02-01T00:00:00Z)
- `page`: número de página
- `per_page`: registros por página

**Response:**
```json
{
  "data": [
    {
      "dni": "12345678",
      "sid": "SID123",
      "apynom": "Pérez, Juan",
      "barcode": "BAR123",
      "saldo": 100.50,
      "semaforo": 1,
      "ult_impago": 1704067200,
      "acceso_full": true,
      "hab_controles": true,
      "hab_controles_raw": {...},
      ...
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 500,
    "total": 1000,
    "last_page": 2
  },
  "server_time": "2026-02-03T12:00:00Z"
}
```

---

## 📚 Documentación Detallada

- [Implementación Completa](docs/PADRON_SYNC_IMPLEMENTATION.md)
- [Ejemplos de Uso](PADRON_SYNC_USAGE_EXAMPLES.php)
- [Integración en Controller](EJEMPLO_INTEGRACION_PROFESOR_SOCIOS.php)
- [Test Rápido](quick_test_padron_sync.php)

---

**Última actualización**: 3 Febrero 2026
