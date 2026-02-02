# 🎯 Auto-asignación de Socios por Profesor - Implementación Completa

## 📋 Resumen de Cambios

Se han implementado 4 nuevos endpoints en la API para permitir que un profesor autenticado gestione sus propias asignaciones de socios (usuarios API). La solución mantiene separados los flujos de admin y profesor.

---

## 📁 Archivos Modificados y Creados

### 1. **Model - Relaciones** 
**Archivo**: `app/Models/User.php`

✅ **Cambio**: Actualizar/agregar relaciones `sociosAsignados()` y `profesoresAsignados()`

```php
// ✅ RELACIÓN PRINCIPAL (usar esta)
/**
 * Socios (usuarios API) asignados a este profesor
 * Pivot: professor_socio (professor_id, socio_id, assigned_by)
 */
public function sociosAsignados()
{
    return $this->belongsToMany(
        User::class,
        'professor_socio',
        'professor_id',
        'socio_id'
    )->withTimestamps()
     ->withPivot(['assigned_by']);
}

/**
 * Alias para compatibilidad
 */
public function assignedSocios()
{
    return $this->sociosAsignados();
}

/**
 * Profesores asignados a este socio (usuario API)
 * Pivot: professor_socio (professor_id, socio_id, assigned_by)
 */
public function profesoresAsignados()
{
    return $this->belongsToMany(
        User::class,
        'professor_socio',
        'socio_id',
        'professor_id'
    )->withTimestamps()
     ->withPivot(['assigned_by']);
}

/**
 * Alias para compatibilidad
 */
public function assignedProfessors()
{
    return $this->profesoresAsignados();
}
```

---

### 2. **Controller - Profesor** ✨ (NUEVO)
**Archivo**: `app/Http/Controllers/Profesor/SocioController.php`

```php
<?php

namespace App\Http\Controllers\Profesor;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SocioController extends Controller
{
    /**
     * GET /api/profesor/socios
     * Lista los socios (usuarios API) asignados al profesor logueado
     */
    public function index(Request $request): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'Solo profesores pueden acceder a esta ruta');

        $query = $profesor->sociosAsignados()
            ->where('user_type', UserType::API);

        // Buscar por DNI, nombre, apellido
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $socios = $query->orderBy('apellido')->orderBy('nombre')->paginate($perPage);

        return response()->json([
            'ok' => true,
            'data' => $socios,
        ]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * Lista socios NO asignados al profesor logueado (disponibles para asignar)
     * Filtra por: user_type = 'api' y no estén ya asignados
     */
    public function disponibles(Request $request): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'Solo profesores pueden acceder a esta ruta');

        // Obtener IDs de socios ya asignados
        $asignados = $profesor->sociosAsignados()->pluck('users.id')->all();

        // Query: socios (API users) NO asignados
        $query = User::query()
            ->where('user_type', UserType::API)
            ->whereNotIn('id', $asignados);

        // Buscar por DNI, nombre, apellido
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $socios = $query->orderBy('apellido')->orderBy('nombre')->paginate($perPage);

        return response()->json([
            'ok' => true,
            'data' => $socios,
        ]);
    }

    /**
     * POST /api/profesor/socios/{socioId}
     * Auto-asignarse (profesor) un socio
     * El profesor NO puede enviar profesorId, siempre usa auth()->user()
     */
    public function store(Request $request, User $socio): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'Solo profesores pueden asignar socios');

        // Validar que el socio existe y es válido
        abort_unless($socio->user_type === UserType::API, 422, 'El usuario debe ser un socio (API)');

        // Validar que el socio no esté ya asignado
        if ($profesor->sociosAsignados()->where('socio_id', $socio->id)->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'El socio ya está asignado a este profesor',
            ], 422);
        }

        // Asignar el socio al profesor (syncWithoutDetaching = attach)
        $profesor->sociosAsignados()->attach($socio->id, [
            'assigned_by' => $profesor->id, // El profesor se auto-asigna
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Socio asignado correctamente',
            'data' => [
                'profesor_id' => $profesor->id,
                'socio_id' => $socio->id,
                'socio' => $socio->only(['id', 'dni', 'nombre', 'apellido', 'name', 'email']),
            ],
        ], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socioId}
     * Auto-desasignarse (profesor) un socio
     */
    public function destroy(Request $request, User $socio): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'Solo profesores pueden desasignar socios');

        // Validar que el socio existe y es válido
        abort_unless($socio->user_type === UserType::API, 422, 'El usuario debe ser un socio (API)');

        // Validar que el socio está asignado
        $assigned = $profesor->sociosAsignados()->where('socio_id', $socio->id)->exists();
        abort_unless($assigned, 404, 'El socio no está asignado a este profesor');

        // Desasignar el socio
        $profesor->sociosAsignados()->detach($socio->id);

        return response()->json([
            'ok' => true,
            'message' => 'Socio desasignado correctamente',
            'data' => [
                'profesor_id' => $profesor->id,
                'socio_id' => $socio->id,
            ],
        ]);
    }
}
```

---

### 3. **Admin Controller** (ACTUALIZADO)
**Archivo**: `app/Http/Controllers/Admin/ProfesorSocioController.php`

✅ **Cambio**: Cambiar `sociosAsignados()->where('user_type', 'api')` a solo `sociosAsignados()`

```php
/**
 * GET /api/admin/profesores/{profesor}/socios
 */
public function sociosPorProfesor(Request $request, User $profesor)
{
    abort_unless($profesor->is_professor, 404);

    $q = $profesor->sociosAsignados();  // ← CAMBIO: sin el ->where('user_type', 'api')
    
    // ... resto del código igual
}
```

---

### 4. **Routes** (ACTUALIZADO)
**Archivo**: `routes/api.php`

✅ **Cambios**:

1. Importar el nuevo controller en la parte superior:
```php
use App\Http\Controllers\Profesor\SocioController as ProfesorSocioController;
```

2. Agregar las nuevas rutas en la sección de profesor (línea ~130):
```php
// Profesor (protegido por rol 'professor')
Route::prefix('professor')->middleware('professor')->group(function () {
    Route::get('my-students', [ProfessorAssignmentController::class, 'myStudents']);
    Route::get('my-stats', [ProfessorAssignmentController::class, 'myStats']);

    Route::post('assign-template', [ProfessorAssignmentController::class, 'assignTemplate']);
    Route::get('assignments/{assignment}', [ProfessorAssignmentController::class, 'show']);
    Route::put('assignments/{assignment}', [ProfessorAssignmentController::class, 'updateAssignment']);
    Route::delete('assignments/{assignment}', [ProfessorAssignmentController::class, 'unassignTemplate']);

    Route::get('students/{student}/progress', [ProfessorAssignmentController::class, 'studentProgress']);
    Route::post('progress/{progress}/feedback', [ProfessorAssignmentController::class, 'addFeedback']);

    Route::get('today-sessions', [ProfessorAssignmentController::class, 'todaySessions']);
    Route::get('weekly-calendar', [ProfessorAssignmentController::class, 'weeklyCalendar']);

    // ==============================
    // Gestión de socios (usuarios API) por el profesor
    // GET  /api/profesor/socios                    -> listar asignados
    // GET  /api/profesor/socios/disponibles        -> listar disponibles
    // POST /api/profesor/socios/{socioId}          -> asignar socio
    // DELETE /api/profesor/socios/{socioId}        -> desasignar socio
    // ==============================
    Route::prefix('socios')->group(function () {
        Route::get('/', [ProfesorSocioController::class, 'index']);
        Route::get('/disponibles', [ProfesorSocioController::class, 'disponibles']);
        Route::post('/{socio}', [ProfesorSocioController::class, 'store']);
        Route::delete('/{socio}', [ProfesorSocioController::class, 'destroy']);
    });
});
```

---

### 5. **Migration** ✅ (YA EXISTE)
**Archivo**: `database/migrations/2026_01_30_215825_create_professor_socio_table.php`

La migration ya está creada con la estructura correcta:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('professor_socio', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professor_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('socio_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['professor_id', 'socio_id']);
            $table->index('professor_id');
            $table->index('socio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professor_socio');
    }
};
```

---

### 6. **Tests** ✨ (NUEVO)
**Archivo**: `tests/Feature/ProfesorSocioTest.php`

Tests completos para los 4 endpoints y validaciones de seguridad. Incluye:
- Autenticación requerida
- Validación de rol profesor
- CRUD completo
- Búsqueda
- Flujo integrado

---

## 🔌 API Endpoints

### Profesor autenticado puede:

#### 1. **Listar socios asignados**
```bash
GET /api/profesor/socios?search=...&per_page=20
Authorization: Bearer {token}

Respuesta 200:
{
  "ok": true,
  "data": {
    "data": [
      {
        "id": 2,
        "dni": "22222222",
        "nombre": "Juan",
        "apellido": "García",
        "name": "Juan García",
        "email": "socio1@test.com",
        "user_type": "api",
        "pivot": {
          "professor_id": 1,
          "socio_id": 2,
          "assigned_by": 1,
          "created_at": "2026-02-02T10:00:00Z",
          "updated_at": "2026-02-02T10:00:00Z"
        }
      }
    ],
    "total": 1,
    "per_page": 20,
    "current_page": 1
  }
}
```

#### 2. **Listar socios disponibles (NO asignados)**
```bash
GET /api/profesor/socios/disponibles?search=...&per_page=20
Authorization: Bearer {token}

Respuesta 200: (ídem anterior, pero solo socios NO asignados)
```

#### 3. **Asignar un socio**
```bash
POST /api/profesor/socios/{socioId}
Authorization: Bearer {token}

Respuesta 201:
{
  "ok": true,
  "message": "Socio asignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2,
    "socio": {
      "id": 2,
      "dni": "22222222",
      "nombre": "Juan",
      "apellido": "García",
      "name": "Juan García",
      "email": "socio1@test.com"
    }
  }
}

Respuesta 422 (ya está asignado):
{
  "ok": false,
  "message": "El socio ya está asignado a este profesor"
}
```

#### 4. **Desasignar un socio**
```bash
DELETE /api/profesor/socios/{socioId}
Authorization: Bearer {token}

Respuesta 200:
{
  "ok": true,
  "message": "Socio desasignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 2
  }
}

Respuesta 404 (no está asignado):
{
  "message": "El socio no está asignado a este profesor"
}
```

---

## 📋 Admin puede seguir usando:

```bash
# Admin endpoints (NO CAMBIAN)
GET    /api/admin/profesores              # Listar profesores
GET    /api/admin/socios                  # Listar socios
GET    /api/admin/profesores/{id}/socios  # Ver socios de un profesor
POST   /api/admin/profesores/{id}/socios  # Asignar socios a profesor (body: {socio_ids: [...]})
```

---

## 🧪 Ejecutar Tests

```bash
# Tests del nuevo módulo
php artisan test tests/Feature/ProfesorSocioTest.php

# O todos los tests
php artisan test
```

---

## ✅ Checklist de Implementación

- [x] Migration `professor_socio` existe y está bien estructurada
- [x] Relaciones `sociosAsignados()` y `profesoresAsignados()` agregadas en User model
- [x] ProfesorSocioController creado con validaciones de seguridad
- [x] Rutas profesor agregadas en `routes/api.php`
- [x] Admin/ProfesorSocioController actualizado para usar nuevo nombre de relación
- [x] Tests completos incluidos
- [x] Documentación de endpoints incluida

---

## 🚀 Pasos para Implementar

1. **Copiar/pegar el código del Controller** en `app/Http/Controllers/Profesor/SocioController.php`
2. **Actualizar las relaciones** en `app/Models/User.php`
3. **Actualizar admin controller** en `app/Http/Controllers/Admin/ProfesorSocioController.php`
4. **Agregar rutas** en `routes/api.php`
5. **Agregar tests** en `tests/Feature/ProfesorSocioTest.php`
6. **Ejecutar migration** (si no se ha hecho): `php artisan migrate`
7. **Ejecutar tests**: `php artisan test`

---

## 🔒 Reglas de Seguridad Implementadas

1. ✅ Solo usuarios autenticados pueden acceder
2. ✅ Solo profesores (is_professor=true) pueden usar estos endpoints
3. ✅ Solo se pueden asignar usuarios con user_type='api'
4. ✅ El profesor autenticado no puede especificar otro professor_id
5. ✅ No se puede asignar un socio que ya está asignado
6. ✅ No se puede desasignar un socio que no está asignado
7. ✅ Validación de existencia de usuario al usar route model binding

---

## 📝 Notas Importantes

- **El profesor se auto-asigna**: El campo `assigned_by` en la tabla pivot se rellena con el ID del profesor autenticado
- **Tablas PIVOT**: Usa la tabla `professor_socio` ya existente
- **Validación de usuario**: Solo usuarios con `user_type = 'api'` pueden ser asignados
- **Búsqueda**: Soporta búsqueda por DNI, nombre, apellido, name, email
- **Paginación**: Por defecto 20 por página, personalizable con `per_page`

