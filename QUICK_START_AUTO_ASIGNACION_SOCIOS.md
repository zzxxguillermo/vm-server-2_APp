# ⚡ Quick Start - Auto-asignación de Socios

## 📋 Resumen Rápido

Se implementan 4 endpoints para que un profesor autenticado gestione sus socios:
- `GET /api/profesor/socios` - Lista socios asignados
- `GET /api/profesor/socios/disponibles` - Lista socios disponibles
- `POST /api/profesor/socios/{id}` - Asignar socio
- `DELETE /api/profesor/socios/{id}` - Desasignar socio

---

## 🔄 3 Pasos para Implementar

### Paso 1: Actualizar User Model
**Archivo**: `app/Models/User.php`

Reemplazar las relaciones al final del archivo (antes de la llave de cierre):

```php
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

public function assignedSocios()
{
    return $this->sociosAsignados();
}

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

public function assignedProfessors()
{
    return $this->profesoresAsignados();
}
```

---

### Paso 2: Crear Controller
**Archivo**: `app/Http/Controllers/Profesor/SocioController.php`

Copiar contenido completo de [IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md - Sección Controller]

---

### Paso 3: Actualizar Rutas
**Archivo**: `routes/api.php`

1. Agregar import en la parte superior:
```php
use App\Http\Controllers\Profesor\SocioController as ProfesorSocioController;
```

2. Dentro del grupo `Route::prefix('professor')`, agregar:
```php
Route::prefix('socios')->group(function () {
    Route::get('/', [ProfesorSocioController::class, 'index']);
    Route::get('/disponibles', [ProfesorSocioController::class, 'disponibles']);
    Route::post('/{socio}', [ProfesorSocioController::class, 'store']);
    Route::delete('/{socio}', [ProfesorSocioController::class, 'destroy']);
});
```

---

## ✅ Validación

```bash
# 1. Tests básicos
php artisan test tests/Feature/ProfesorSocioTest.php

# 2. Prueba manual - obtener token
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"profesor@test.com","password":"pass"}'

# 3. Listar socios
curl http://localhost:8000/api/profesor/socios \
  -H "Authorization: Bearer {TOKEN}"
```

---

## 📁 Archivos Modificados

| Archivo | Acción | Descripción |
|---------|--------|------------|
| `app/Models/User.php` | ✏️ Modificar | Agregar relaciones `sociosAsignados()` |
| `app/Http/Controllers/Profesor/SocioController.php` | ➕ Crear | Nuevo controller |
| `routes/api.php` | ✏️ Modificar | Agregar rutas grupo `profesor/socios` |
| `app/Http/Controllers/Admin/ProfesorSocioController.php` | ✏️ Ajustar | Cambiar nombre método (sociosAsignados) |
| `tests/Feature/ProfesorSocioTest.php` | ➕ Crear | Tests completos |

---

## 🎯 Endpoints Resultado

```
GET    /api/profesor/socios                     [profesor autenticado]
GET    /api/profesor/socios/disponibles         [profesor autenticado]
POST   /api/profesor/socios/{socioId}           [profesor autenticado]
DELETE /api/profesor/socios/{socioId}           [profesor autenticado]
```

---

## 🔒 Seguridad

- ✅ Autenticación requerida (Bearer token)
- ✅ Solo profesores (is_professor = true)
- ✅ Solo usuarios API (user_type = 'api')
- ✅ Profesor NO puede especificar otro profesor_id
- ✅ Validación única: professor_id + socio_id

---

## 📝 Notas

- **Migration**: Ya existe `2026_01_30_215825_create_professor_socio_table.php`
- **Tabla Pivot**: `professor_socio` (professor_id, socio_id, assigned_by, timestamps)
- **Búsqueda**: Por DNI, nombre, apellido, email
- **Paginación**: Por defecto 20, personalizable con `?per_page=50`

---

## 🚀 Próximos Pasos (Opcional)

1. Agregar tests de integración con auth
2. Crear eventos cuando se asigna/desasigna socios
3. Enviar notificaciones a socios cuando son asignados
4. Dashboard profesor con estadísticas

