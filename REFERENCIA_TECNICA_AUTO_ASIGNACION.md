# 📚 Referencia Técnica - Auto-asignación de Socios

## 📊 Tabla Resumen

| Componente | Ubicación | Estado | Notas |
|-----------|-----------|--------|-------|
| **Relaciones** | `app/Models/User.php` | ✅ Implementado | `sociosAsignados()`, `profesoresAsignados()` |
| **Controller Prof** | `app/Http/Controllers/Profesor/SocioController.php` | ✅ Creado | 4 métodos (index, disponibles, store, destroy) |
| **Controller Admin** | `app/Http/Controllers/Admin/ProfesorSocioController.php` | ✅ Ajustado | Cambio menor en método `sociosPorProfesor` |
| **Rutas** | `routes/api.php` | ✅ Actualizado | Grupo `professor/socios` con 4 rutas |
| **Tabla Pivot** | `database/migrations/2026_01_30_215825...` | ✅ Existe | `professor_socio` con índices y unique |
| **Tests** | `tests/Feature/ProfesorSocioTest.php` | ✅ Completos | 13 test cases |

---

## 🔌 Endpoints

### Profesor
```
GET    /api/profesor/socios
GET    /api/profesor/socios/disponibles
POST   /api/profesor/socios/{socioId}
DELETE /api/profesor/socios/{socioId}
```

### Admin (sin cambios)
```
GET    /api/admin/profesores
GET    /api/admin/socios
GET    /api/admin/profesores/{id}/socios
POST   /api/admin/profesores/{id}/socios
```

---

## 🏗️ Estructura de Datos

### Tabla: professor_socio
```sql
CREATE TABLE professor_socio (
  id                BIGINT PRIMARY KEY AUTO_INCREMENT,
  professor_id      BIGINT NOT NULL,
  socio_id          BIGINT NOT NULL,
  assigned_by       BIGINT NULL,
  created_at        TIMESTAMP,
  updated_at        TIMESTAMP,
  UNIQUE(professor_id, socio_id),
  FOREIGN KEY(professor_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(socio_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(assigned_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX(professor_id),
  INDEX(socio_id)
);
```

---

## 📝 Relaciones Eloquent

```php
// En User model:

// Profesor → Socios
sociosAsignados()          // belongsToMany, with assigned_by pivot
profesoresAsignados()      // inverse (inverso)

// Aliases
assignedSocios()           // alias de sociosAsignados()
assignedProfessors()       // alias de profesoresAsignados()
```

---

## 🎯 Lógica de Negocio

### INDEX (GET /api/profesor/socios)
1. Validar auth + is_professor
2. Query: `auth()->user()->sociosAsignados()`
3. Filtro: user_type = 'api'
4. Búsqueda: dni, nombre, apellido, email
5. Paginar (defecto 20)

### DISPONIBLES (GET /api/profesor/socios/disponibles)
1. Validar auth + is_professor
2. Obtener IDs asignados: `profesor->sociosAsignados()->pluck('id')`
3. Query: `User::where('user_type', 'api')->whereNotIn('id', $asignados)`
4. Búsqueda + paginación

### STORE (POST /api/profesor/socios/{socioId})
1. Validar auth + is_professor
2. Validar: socio.user_type = 'api'
3. Validar: no duplicado en pivot
4. `profesor->sociosAsignados()->attach(socio_id, ['assigned_by' => auth_id])`
5. Respuesta 201

### DESTROY (DELETE /api/profesor/socios/{socioId})
1. Validar auth + is_professor
2. Validar: socio.user_type = 'api'
3. Validar: existe en pivot
4. `profesor->sociosAsignados()->detach(socio_id)`
5. Respuesta 200

---

## 🔐 Validaciones

```php
// Nivel 1: Middleware
Route::middleware('auth:sanctum') // Token requerido

// Nivel 2: Controller
abort_unless(auth()->user()->is_professor, 403)

// Nivel 3: Modelo
abort_unless(socio->user_type === UserType::API, 422)

// Nivel 4: Negocio
if (profesor->sociosAsignados()->where('socio_id', $id)->exists())
    return error_422 // Ya existe

// Nivel 5: Eloquent
$profesor->sociosAsignados()->detach($id) // Route model binding
```

---

## 📤 Respuestas

### 200 OK (GET)
```json
{
  "ok": true,
  "data": {
    "data": [...],
    "total": 10,
    "per_page": 20,
    "current_page": 1
  }
}
```

### 201 Created (POST)
```json
{
  "ok": true,
  "message": "Socio asignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2,
    "socio": {...}
  }
}
```

### 200 OK (DELETE)
```json
{
  "ok": true,
  "message": "Socio desasignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2
  }
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "message": "Solo profesores pueden acceder a esta ruta"
}
```

### 404 Not Found
```json
{
  "message": "El socio no está asignado a este profesor"
}
```

### 422 Unprocessable
```json
{
  "ok": false,
  "message": "El socio ya está asignado a este profesor"
}
```

---

## 🧪 Tests (13)

| Test | Endpoint | Validación |
|------|----------|-----------|
| test_profesor_socios_index_requires_authentication | GET /socios | 401 |
| test_profesor_socios_index_requires_professor_role | GET /socios | 403 |
| test_profesor_socios_index_returns_empty_list | GET /socios | 200 empty |
| test_profesor_socios_index_returns_assigned_socios | GET /socios | 200 with data |
| test_profesor_socios_index_search_by_dni | GET /socios?search | 200 filtered |
| test_profesor_socios_disponibles_returns_unassigned | GET /disponibles | 200 unassigned |
| test_profesor_socios_disponibles_excludes_assigned | GET /disponibles | 200 excluded |
| test_profesor_puede_asignarse_socio | POST /{id} | 201 created |
| test_profesor_no_puede_asignarse_usuario_local | POST /{id} local | 422 |
| test_profesor_no_puede_asignarse_socio_duplicado | POST /{id} dup | 422 |
| test_no_profesor_no_puede_asignarse_socio | POST /{id} no prof | 403 |
| test_profesor_puede_desasignarse_socio | DELETE /{id} | 200 |
| test_profesor_no_puede_desasignarse_socio_no_asignado | DELETE /{id} missing | 404 |
| test_flujo_completo_asignacion_socios | All 4 endpoints | E2E |

---

## 🚀 Checklist Implementación

- [x] Migration existe: `professor_socio` table
- [x] Relaciones agregadas: `sociosAsignados()`, `profesoresAsignados()`
- [x] Controller creado: `Profesor/SocioController.php`
- [x] Admin controller actualizado: cambiar nombre relación
- [x] Rutas añadidas: grupo `professor/socios`
- [x] Tests completos: 13 test cases
- [x] Documentación: 3 archivos .md
- [x] Ejemplos CURL: listados

---

## 📊 Uso de Memoria

```
Tabla pivot: ~24 bytes por registro (id, profesor_id, socio_id, assigned_by)
+ timestamps: +16 bytes
Total: ~40 bytes por asignación

Si profesor tiene 1000 socios:
- Memoria: ~40KB por profesor
- Query index: O(log n) con índice professor_id
```

---

## ⚡ Performance

| Operación | Complejidad | Notas |
|-----------|------------|-------|
| GET /profesor/socios | O(n) | Con índice en professor_id |
| GET /disponibles | O(m) | m = total socios - asignados |
| POST /socios/{id} | O(1) | Insert en pivot |
| DELETE /socios/{id} | O(1) | Delete en pivot |

---

## 🔍 Debugging

```php
// Ver socios de profesor
$profesor->sociosAsignados()->get();

// Ver profesores de socio
$socio->profesoresAsignados()->get();

// Query SQL generada
$profesor->sociosAsignados()->toSql();

// Con assigned_by
$profesor->sociosAsignados()->with('pivot')->get();
```

---

## 📌 Consideraciones de Seguridad

1. **CSRF**: Protegido por middleware auth:sanctum
2. **Rate Limiting**: Añadir si es necesario
3. **Datos Sensibles**: Solo se retornan campos públicos
4. **Auditoría**: Campo `assigned_by` registra quién asignó
5. **Validación**: Type checking en controller

---

## 🔗 Enlaces Documentación

- [Implementación Completa](./IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md)
- [Ejemplos CURL](./EJEMPLOS_CURL_AUTO_ASIGNACION_SOCIOS.md)
- [Quick Start](./QUICK_START_AUTO_ASIGNACION_SOCIOS.md)

