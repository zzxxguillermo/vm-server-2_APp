# PADRON SYNC - ARQUITECTURA Y FLUJOS

## 🏗️ ARQUITECTURA GENERAL

```
┌─────────────────────────────────────────────────────────────────┐
│                         vmServer                                │
│                 (Fuente de Verdad - Padrón)                     │
│            GET /api/internal/padron/socios                      │
│         Headers: X-Internal-Token: {token}                      │
└──────────────────┬──────────────────────────────────────────────┘
                   │
                   │ HTTP + Paginación
                   │
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│              App\Services\VmServerPadronClient                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ public fetchSocios(array $params): array               │   │
│  │ public fetchSocioByDni(string): ?array                 │   │
│  │ public fetchSocioBySid(string): ?array                 │   │
│  └─────────────────────────────────────────────────────────┘   │
│                          ▲                                      │
│              Inyectable (Service Container)                     │
└──────────────┬───────────────────────────────────────────┬──────┘
               │                                           │
        (usado por)                                  (usado por)
               │                                           │
               ▼                                           ▼
        ┌─────────────────┐                    ┌──────────────────┐
        │  PadronSync     │                    │   Controller     │
        │  Command        │                    │  (on-demand)     │
        │  (automático)   │                    │  (asignación)    │
        └────────┬────────┘                    └──────────────────┘
                 │
                 │ Upsert Inteligente
                 │ (SID vs DNI)
                 │
                 ▼
    ┌────────────────────────────────┐
    │  DB: socios_padron             │
    │  ┌──────────────────────────┐  │
    │  │ id, dni, sid, apynom ... │  │
    │  │ barcode, saldo, semaforo │  │
    │  │ raw (JSON), ... (JSON)   │  │
    │  └──────────────────────────┘  │
    │                                │
    │  + Índices:                    │
    │    - dni (INDEX)               │
    │    - sid (INDEX)               │
    │    - barcode (UNIQUE)          │
    └────────────────────────────────┘
                 │
                 │ Materialización On-Demand
                 │
                 ▼
    ┌────────────────────────────────┐
    │   GymSocioMaterializer         │
    │  ┌──────────────────────────┐  │
    │  │ materializeByDniOrSid()  │  │
    │  │ materializeMultiple()    │  │
    │  │ syncExistingUsers()      │  │
    │  └──────────────────────────┘  │
    └────────────────────────────────┘
                 │
                 │ Crea/Actualiza
                 │ user_type = API
                 │
                 ▼
    ┌────────────────────────────────┐
    │  DB: users                     │
    │  ┌──────────────────────────┐  │
    │  │ id, dni, name, email ... │  │
    │  │ socio_id, barcode, saldo │  │
    │  │ user_type (API), ...     │  │
    │  └──────────────────────────┘  │
    │                                │
    │  Usuarios Locales              │
    │  (creados on-demand)           │
    └────────────────────────────────┘
                 │
                 ▼
    ┌────────────────────────────────┐
    │  Asignaciones a Profesor       │
    │  (relación many-to-many)       │
    │                                │
    │  professor.assignedSocios()    │
    └────────────────────────────────┘
```

---

## 📊 FLUJOS PRINCIPALES

### FLUJO 1: SINCRONIZACIÓN (Automática cada 2h o manual)

```
START: php artisan padron:sync [--since=...] [--per-page=...]
   │
   ├─ 1. Determine SINCE
   │  ├─ if option --since → use it
   │  ├─ else if SyncState padron_last_sync_at exists → use it
   │  └─ else → use 24 hours ago
   │
   ├─ 2. LOOP: Page=1
   │  │
   │  ├─ Call: VmServerPadronClient::fetchSocios()
   │  │  └─ GET vmServer/api/internal/padron/socios
   │  │     Headers: X-Internal-Token: {token}
   │  │     Query: updated_since, page, per_page
   │  │
   │  ├─ 3. Parse Response
   │  │  ├─ Extract: data[], pagination[]
   │  │  ├─ Check: current_page == last_page?
   │  │
   │  ├─ 4. Map Items
   │  │  ├─ For each item in data[]
   │  │  │  ├─ Extract: dni, sid, apynom, barcode, saldo, ...
   │  │  │  ├─ Cast: saldo (float), semaforo (int), raw (JSON)
   │  │  │  └─ Store: raw (complete response), hab_controles_raw
   │  │  │
   │  │  ├─ Separate into 2 groups:
   │  │  │  ├─ Group A: items with sid
   │  │  │  └─ Group B: items without sid
   │  │
   │  ├─ 5. Upsert (Inteligente)
   │  │  ├─ Group A: SocioPadron::upsert($groupA, ['sid'], [...columns])
   │  │  └─ Group B: SocioPadron::upsert($groupB, ['dni'], [...columns])
   │  │
   │  ├─ 6. Log Page Stats
   │  │  └─ "Página X: Y/Z upsertados"
   │  │
   │  ├─ 7. Is current_page >= last_page?
   │  │  ├─ YES → goto 8
   │  │  └─ NO → page++, goto 2
   │
   ├─ 8. Update SyncState
   │  └─ SyncState::setValue('padron_last_sync_at', server_time || now())
   │
   ├─ 9. Log Final Stats
   │  ├─ Total procesados: X
   │  ├─ Total upsertados: Y
   │  └─ Last sync: Z
   │
   END: ✅ Sincronización completada
```

---

### FLUJO 2: MATERIALIZACIÓN ON-DEMAND (Asignar socio a profesor)

```
START: POST /api/professors/1/assign-socio { dni_or_sid: "12345678" }
   │
   ├─ 1. Validate Input
   │  └─ dni_or_sid must be 5-20 chars
   │
   ├─ 2. Call Materializer
   │  │
   │  ├─ GymSocioMaterializer::materializeByDniOrSid("12345678")
   │  │
   │  ├─ 3. Search in Local Padron
   │  │  └─ SocioPadron::where('dni', "...").orWhere('sid', "...")->first()
   │  │
   │  ├─ 4. Extract Data
   │  │  ├─ dni, sid, apynom, barcode, saldo, semaforo, etc
   │  │  ├─ Parse apynom → nombre, apellido (or from raw JSON)
   │  │  └─ Generate email: socio.{dni}@gimnasio.local
   │  │
   │  ├─ 5. Create/Update User
   │  │  └─ User::updateOrCreate(
   │  │       ['dni' => $dni],
   │  │       [
   │  │         'user_type' => API,
   │  │         'socio_id' => $sid,
   │  │         'socio_n' => $sid,
   │  │         'barcode' => $barcode,
   │  │         'saldo' => $saldo,
   │  │         'semaforo' => $semaforo,
   │  │         'estado_socio' => $acceso_full ? ACTIVO : INACTIVO,
   │  │         'api_updated_at' => now(),
   │  │         'name' => apynom,
   │  │         'nombre' => nombre,
   │  │         'apellido' => apellido,
   │  │         'email' => generated_email,
   │  │         'password' => bcrypt(random())
   │  │       ]
   │  │     )
   │  │
   │  └─ Return: User object
   │
   ├─ 6. Verify Not Already Assigned
   │  └─ if professor.assignedSocios.contains(socio) → return 409
   │
   ├─ 7. Attach to Professor
   │  └─ professor.assignedSocios().attach(socio.id, [
   │       'assigned_at' => now(),
   │       'assigned_by' => auth().id()
   │     ])
   │
   └─ 8. Return Success
      └─ { success: true, socio: {...}, message: "..." }

END: ✅ Socio asignado a profesor
```

---

### FLUJO 3: BÚSQUEDA Y CONSULTA

```
START: GET /api/socios/search?q=12345678
   │
   ├─ 1. Search Local Padron
   │  └─ SocioPadron::findByDniOrSid("12345678")
   │
   ├─ 2. If Found
   │  └─ Return: {
   │       found: true,
   │       source: "local",
   │       data: { dni, sid, apynom, barcode, saldo, ... }
   │     }
   │
   ├─ 3. If Not Found → Try Remote
   │  └─ VmServerPadronClient::fetchSocioByDni()
   │
   ├─ 4. If Found in Remote
   │  └─ Return: {
   │       found: true,
   │       source: "remote",
   │       data: { ... vmServer response ... },
   │       message: "Use assign-socio to materialize..."
   │     }
   │
   └─ 5. If Not Found Anywhere
      └─ Return 404: { found: false }

END: ✅ Búsqueda completada
```

---

## 🔄 ESTADO (SyncState)

```
Table: sync_states

┌─────────────────────────────────────────────────────────┐
│ id  │ key                      │ value   │ updated_at  │
├─────┼──────────────────────────┼─────────┼─────────────┤
│ 1   │ padron_last_sync_at      │ 2026... │ 2026-02-03  │
│ 2   │ templates_last_sync_at   │ 2026... │ 2026-02-02  │
│ 3   │ assignments_last_sync_at │ 2026... │ 2026-02-01  │
└─────────────────────────────────────────────────────────┘

Acceso:
  SyncState::getValue('padron_last_sync_at')
  SyncState::setValue('key', 'value')
  SyncState::getLastSyncTimestamp('key')
```

---

## 🗄️ BASES DE DATOS

### Tabla: socios_padron

```sql
CREATE TABLE socios_padron (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  
  -- Identificación
  dni VARCHAR(20) INDEX,
  sid VARCHAR(50) INDEX,
  apynom VARCHAR(255),
  barcode VARCHAR(100) UNIQUE INDEX,
  
  -- Estado
  saldo DECIMAL(12,2),
  semaforo INT,
  ult_impago INT,
  
  -- Acceso
  acceso_full BOOLEAN DEFAULT false,
  hab_controles BOOLEAN DEFAULT true,
  
  -- Raw Data (JSON)
  hab_controles_raw JSON,
  raw JSON,
  
  -- Auditoría
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  
  -- Índices
  INDEX idx_dni (dni),
  INDEX idx_sid (sid),
  UNIQUE INDEX idx_barcode (barcode),
  INDEX idx_dni_sid (dni, sid),
  INDEX idx_updated_at (updated_at)
);
```

### Tabla: sync_states

```sql
CREATE TABLE sync_states (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  key VARCHAR(255) UNIQUE INDEX,
  value LONGTEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Relación: users ←→ socios_padron

```
users (existentes)
  │
  ├─ dni ─────────┐
  │               │
  ├─ socio_id ───┐│
  ├─ socio_n ───┐││
  ├─ barcode ──┐│││
  └─ saldo ───┐││││
              ││││
              ▼▼▼▼
        socios_padron
        (tabla de referencia)
```

---

## 📋 SECUENCIA TEMPORAL

```
HORA 00:00 → Scheduler dispara
HORA 00:00 → padron:sync inicia
HORA 00:00 → Lee último sync (o 24h atrás)
HORA 00:00 → Itera páginas de vmServer
HORA 00:XX → Upsertas a socios_padron
HORA 00:XX → Actualiza SyncState
HORA 00:XX → padron:sync termina

HORA 02:00 → Próximo cycle
...
HORA 14:30 → Usuario asigna socio a profesor
HORA 14:30 → Controller materializa socio
HORA 14:30 → Se crea/actualiza User
HORA 14:30 → Se asocia a profesor
```

---

## 🔐 SEGURIDAD Y AUTENTICACIÓN

```
vmServer
  │
  └─ GET /api/internal/padron/socios
     │
     Header: X-Internal-Token: {token_secreto}
             (NO en query string)
     │
     ├─ ✅ Seguro (HTTPS)
     ├─ ✅ Token en header (no en logs de URL)
     ├─ ✅ Validación en vmServer
     └─ ✅ Sin credenciales en código
```

---

## 📈 FLUJO DE DATOS

```
vmServer (Fuente de Verdad)
   │
   ▼
VmServerPadronClient (HTTP)
   │
   ▼
PadronSyncCommand (Orchestration)
   │
   ├─ Mapeo
   │  └─ Extrae y transforma
   │
   ├─ Validación
   │  └─ DNI/SID requerido
   │
   ├─ Upsert Inteligente
   │  ├─ Con SID → key: sid
   │  └─ Sin SID → key: dni
   │
   ▼
SocioPadron (Local DB)
   │
   ├─ Datos normalizados
   ├─ Raw JSON (auditoría)
   └─ Índices optimizados
   │
   ▼
GymSocioMaterializer (On-Demand)
   │
   ├─ Extrae datos de padrón
   ├─ Crea/actualiza User
   └─ Asigna a profesor
   │
   ▼
User (Local DB)
   │
   └─ Disponible para asignaciones
```

---

## ⚙️ COMPONENTES

```
┌─────────────────────────────────────────────────┐
│            ARQUITECTURA COMPLETA                 │
│                                                 │
│  ┌────────────────────────────────────────┐    │
│  │  1. VmServerPadronClient               │    │
│  │     - HTTP Client                      │    │
│  │     - Paginación                       │    │
│  │     - Error Handling                   │    │
│  └────────────────────────────────────────┘    │
│                    ▲                            │
│                    │ Injected                   │
│                    │                            │
│  ┌────────────────────────────────────────┐    │
│  │  2. PadronSyncCommand                  │    │
│  │     - Orchestración                    │    │
│  │     - Lógica de Upsert                 │    │
│  │     - Logging                          │    │
│  └────────────────────────────────────────┘    │
│                    │                            │
│                    ▼                            │
│  ┌────────────────────────────────────────┐    │
│  │  3. SocioPadron Model                  │    │
│  │     - DB Persistence                   │    │
│  │     - Helper Methods                   │    │
│  └────────────────────────────────────────┘    │
│                    │                            │
│                    ▼                            │
│  ┌────────────────────────────────────────┐    │
│  │  4. GymSocioMaterializer               │    │
│  │     - Materialización                  │    │
│  │     - User Creation                    │    │
│  │     - Batch Operations                 │    │
│  └────────────────────────────────────────┘    │
│                    │                            │
│                    ▼                            │
│  ┌────────────────────────────────────────┐    │
│  │  5. SyncState Model                    │    │
│  │     - Track Syncs                      │    │
│  │     - Timestamps                       │    │
│  └────────────────────────────────────────┘    │
│                                                 │
└─────────────────────────────────────────────────┘
```

---

## 🎯 CASOS DE USO ARQUITECTÓNICOS

```
CASO 1: Sincronización Automática
─────────────────────────────────
Scheduler (2h)
   └─ padron:sync
      ├─ VmServerPadronClient.fetchSocios()
      ├─ SocioPadron.upsert()
      └─ SyncState.setValue()

CASO 2: Asignación a Profesor
──────────────────────────────
Controller.assignSocio()
   ├─ GymSocioMaterializer.materializeByDniOrSid()
   │  ├─ SocioPadron.findByDniOrSid()
   │  └─ User.updateOrCreate()
   └─ professor.assignedSocios().attach()

CASO 3: Búsqueda
────────────────
Controller.searchSocio()
   ├─ SocioPadron.findByDniOrSid() [Local]
   └─ if !found → VmServerPadronClient.fetchSocioByDni() [Remote]

CASO 4: Reconciliación
──────────────────────
GymSocioMaterializer.syncExistingUsers()
   └─ Para cada usuario existente con DNI
      └─ Actualizar campos de padrón
```

---

## 📊 DIAGRAMA DE ESTADOS

```
SOCIO EN vmServer
      │
      ├─ (Sin sincronizar)
      │
      ▼ padron:sync
SOCIO EN socios_padron (No-materializado)
      │
      ├─ (Sin usuario local)
      │
      ▼ GymSocioMaterializer::materializeByDniOrSid()
USER EN users (Materializado)
      │
      ├─ user_type = API
      ├─ socio_id = sid from padron
      │
      ▼ $professor->assignedSocios()->attach($user->id)
ASIGNADO A PROFESOR
      │
      └─ Disponible para operaciones
```

---

**Última actualización**: 3 Febrero 2026
**Versión**: 1.0 - Producción Ready
