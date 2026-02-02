# ✅ Checklist Implementación - Auto-asignación de Socios

> Sigue este checklist paso a paso para implementar la solución completa

---

## 🔍 FASE 1: PREPARACIÓN

- [ ] Leer `QUICK_START_AUTO_ASIGNACION_SOCIOS.md`
- [ ] Leer `RESUMEN_FINAL_AUTO_ASIGNACION_SOCIOS.md`
- [ ] Entender la estructura de 4 endpoints
- [ ] Revisar que la migration `professor_socio` existe

**Verificación**:
```bash
# Comprobar que la migration existe
ls database/migrations/*professor_socio*
# Debería mostrar: 2026_01_30_215825_create_professor_socio_table.php
```

---

## 🛠️ FASE 2: MODIFICAR USER MODEL

**Archivo**: `app/Models/User.php`

- [ ] Abrir archivo al final (antes de la llave `}`)
- [ ] Encontrar sección "// ==================== RELACIONES ===================="
- [ ] Reemplazar/agregar estas 4 funciones:

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

- [ ] Guardar archivo

**Verificación**:
```bash
php artisan tinker
> $profesor = User::where('is_professor', true)->first();
> $profesor->sociosAsignados();
// No debe dar error
```

---

## 📁 FASE 3: CREAR CONTROLLER

**Archivo**: `app/Http/Controllers/Profesor/SocioController.php` (NUEVO)

- [ ] Crear carpeta `app/Http/Controllers/Profesor/` (si no existe)
- [ ] Crear archivo `SocioController.php`
- [ ] Copiar contenido completo de:
  - Sección "Controller - Profesor" en `IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md`
- [ ] Guardar archivo

**Verificación**:
```bash
php artisan tinker
> $controller = new \App\Http\Controllers\Profesor\SocioController();
> echo get_class($controller);
// Debe mostrar: App\Http\Controllers\Profesor\SocioController
```

---

## 🔀 FASE 4: ACTUALIZAR RUTAS

**Archivo**: `routes/api.php`

### Paso 4.1: Agregar import
- [ ] Ir a línea 1-20 (imports)
- [ ] Agregar esta línea después de los otros imports:
```php
use App\Http\Controllers\Profesor\SocioController as ProfesorSocioController;
```

### Paso 4.2: Agregar grupo de rutas
- [ ] Buscar comentario: `// Profesor (protegido por rol 'professor')`
- [ ] Dentro de ese grupo Route::prefix('professor'), al final, agregar:
```php
Route::prefix('socios')->group(function () {
    Route::get('/', [ProfesorSocioController::class, 'index']);
    Route::get('/disponibles', [ProfesorSocioController::class, 'disponibles']);
    Route::post('/{socio}', [ProfesorSocioController::class, 'store']);
    Route::delete('/{socio}', [ProfesorSocioController::class, 'destroy']);
});
```

- [ ] Guardar archivo

**Verificación**:
```bash
php artisan route:list | grep profesor
# Debe mostrar 4 nuevas rutas con /profesor/socios
```

---

## 🔧 FASE 5: ACTUALIZAR ADMIN CONTROLLER

**Archivo**: `app/Http/Controllers/Admin/ProfesorSocioController.php`

- [ ] Buscar método: `sociosPorProfesor`
- [ ] Encontrar línea: `$q = $profesor->sociosAsignados()->where('user_type', 'api');`
- [ ] Cambiar a: `$q = $profesor->sociosAsignados();`
- [ ] Guardar archivo

**Verificación**:
```bash
git diff app/Http/Controllers/Admin/ProfesorSocioController.php
# Debe mostrar 1 línea diferente
```

---

## ✍️ FASE 6: CREAR TESTS

**Archivo**: `tests/Feature/ProfesorSocioTest.php` (NUEVO)

- [ ] Crear archivo
- [ ] Copiar contenido completo de:
  - Sección "Tests" en `IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md`
- [ ] Guardar archivo

**Verificación**:
```bash
php artisan test tests/Feature/ProfesorSocioTest.php --verbose
# Todos los tests deben pasar (13)
```

---

## 🧪 FASE 7: EJECUCIÓN DE TESTS

- [ ] Ejecutar tests del nuevo módulo:
```bash
php artisan test tests/Feature/ProfesorSocioTest.php
```

- [ ] Verificar que TODOS los tests pasen:
```
PASSED  tests/Feature/ProfesorSocioTest.php
✓ 13 passed
```

- [ ] Si alguno falla, revisar:
  1. ¿User model tiene las relaciones?
  2. ¿Controller está en la ruta correcta?
  3. ¿Rutas están agregadas?
  4. ¿Admin controller actualizado?

---

## 🚀 FASE 8: PRUEBAS MANUALES

### Prueba 1: Token de profesor
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"profesor@test.com","password":"password"}'
# Guardar el token en variable: PROF_TOKEN
```

- [ ] ¿Obtuve token válido?

### Prueba 2: Listar socios asignados
```bash
curl http://localhost:8000/api/profesor/socios \
  -H "Authorization: Bearer $PROF_TOKEN"
```

- [ ] ¿Respuesta 200 con formato correcto?
- [ ] ¿Campo "ok": true?

### Prueba 3: Listar socios disponibles
```bash
curl http://localhost:8000/api/profesor/socios/disponibles \
  -H "Authorization: Bearer $PROF_TOKEN"
```

- [ ] ¿Respuesta 200?
- [ ] ¿Lista contiene usuarios API?

### Prueba 4: Asignar socio
```bash
# Primero, obtener ID de un socio de /disponibles
# Luego:
curl -X POST http://localhost:8000/api/profesor/socios/{SOCIO_ID} \
  -H "Authorization: Bearer $PROF_TOKEN"
```

- [ ] ¿Respuesta 201?
- [ ] ¿Campo "ok": true?

### Prueba 5: Desasignar socio
```bash
curl -X DELETE http://localhost:8000/api/profesor/socios/{SOCIO_ID} \
  -H "Authorization: Bearer $PROF_TOKEN"
```

- [ ] ¿Respuesta 200?
- [ ] ¿Socio fue removido?

---

## 📚 FASE 9: DOCUMENTACIÓN

- [ ] Verificar que existen 5 documentos:
  - [ ] `IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md`
  - [ ] `EJEMPLOS_CURL_AUTO_ASIGNACION_SOCIOS.md`
  - [ ] `QUICK_START_AUTO_ASIGNACION_SOCIOS.md`
  - [ ] `REFERENCIA_TECNICA_AUTO_ASIGNACION.md`
  - [ ] `RESUMEN_FINAL_AUTO_ASIGNACION_SOCIOS.md`

- [ ] Hacer lectura rápida de cada documento

---

## ✨ FASE 10: VALIDACIÓN FINAL

- [ ] Ejecutar tests completos:
```bash
php artisan test tests/Feature/ProfesorSocioTest.php --verbose
```

- [ ] Verificar rutas:
```bash
php artisan route:list | grep "profesor/socios"
```

- [ ] Verificar relaciones en DB:
```bash
php artisan tinker
> $prof = User::where('is_professor', true)->first();
> $prof->sociosAsignados()->count();  // Debe funcionar
> $prof->profesoresAsignados();       // Puede estar vacío
```

- [ ] Verificar estructura de base de datos:
```bash
php artisan tinker
> \Schema::getColumnListing('professor_socio')
// Debe mostrar: ['id', 'professor_id', 'socio_id', 'assigned_by', 'created_at', 'updated_at']
```

---

## 🎯 FASE 11: DOCUMENTACIÓN DEL EQUIPO

- [ ] Compartir con el equipo:
  - [ ] Enviar `QUICK_START_AUTO_ASIGNACION_SOCIOS.md`
  - [ ] Enviar `EJEMPLOS_CURL_AUTO_ASIGNACION_SOCIOS.md`
  - [ ] Enviar `REFERENCIA_TECNICA_AUTO_ASIGNACION.md`

- [ ] Hacer demo del sistema funcionando

- [ ] Explicar la diferencia entre endpoints admin vs profesor

---

## 🔒 FASE 12: SEGURIDAD

- [ ] Verificar que NO se puede acceder sin token:
```bash
curl http://localhost:8000/api/profesor/socios
# Debe responder: 401 Unauthenticated
```

- [ ] Verificar que NO se puede acceder como no-profesor:
```bash
# Con token de estudiante
curl http://localhost:8000/api/profesor/socios \
  -H "Authorization: Bearer $STUDENT_TOKEN"
# Debe responder: 403 Forbidden
```

- [ ] Verificar que NO se puede asignar usuario local:
```bash
curl -X POST http://localhost:8000/api/profesor/socios/{LOCAL_USER_ID} \
  -H "Authorization: Bearer $PROF_TOKEN"
# Debe responder: 422 Unprocessable Entity
```

---

## 📊 RESUMEN DE CAMBIOS

**Archivos Creados**: 3
- [ ] `app/Http/Controllers/Profesor/SocioController.php`
- [ ] `tests/Feature/ProfesorSocioTest.php`
- [ ] `IMPLEMENTACION_AUTO_ASIGNACION_SOCIOS.md` (y 4 más doc)

**Archivos Modificados**: 3
- [ ] `app/Models/User.php`
- [ ] `routes/api.php`
- [ ] `app/Http/Controllers/Admin/ProfesorSocioController.php`

**Nuevos Endpoints**: 4
- [ ] GET /api/profesor/socios
- [ ] GET /api/profesor/socios/disponibles
- [ ] POST /api/profesor/socios/{socioId}
- [ ] DELETE /api/profesor/socios/{socioId}

**Tests**: 13
- [ ] Todos pasando

---

## 🎉 IMPLEMENTACIÓN COMPLETADA

Una vez que hayas marcado TODO en este checklist:

1. ✅ Tu sistema está **100% implementado**
2. ✅ Los profesores pueden **auto-asignarse socios**
3. ✅ El admin **mantiene su funcionalidad**
4. ✅ Todo está **validado y testeado**
5. ✅ **Documentación completa** disponible

---

## 📞 EN CASO DE PROBLEMAS

| Problema | Solución |
|----------|----------|
| Tests fallan | Verificar relaciones en User model |
| Error 404 en rutas | Verificar imports en routes/api.php |
| Error 403 en endpoints | Verificar is_professor = true |
| Error 422 en POST | Verificar que socio.user_type = 'api' |
| Error de tabla | Ejecutar: php artisan migrate |

---

## 💾 BACKUP RECOMENDADO

```bash
# Antes de implementar
git add .
git commit -m "Backup antes de auto-asignación de socios"

# Después de implementar
git add .
git commit -m "Implementada auto-asignación de socios por profesor"
```

---

**Buena suerte con la implementación! 🚀**

