# ⚡ PADRON SYNC - START HERE

## 📦 Qué recibiste

✅ **9 archivos de código** (migraciones, modelos, servicios, commands, helpers)
✅ **9 archivos de documentación** (guías, ejemplos, referencias)
✅ **~2000 líneas de código** listas para usar
✅ **Sin dependencias externas** (solo Laravel 11)

---

## 🚀 3 Pasos para Empezar

### 1️⃣ Ejecutar Migraciones
```bash
php artisan migrate
```
✅ Crea: `socios_padron` y `sync_states`

### 2️⃣ Configurar `.env`
```dotenv
VMSERVER_BASE_URL=https://vmserver.ejemplo.com
VMSERVER_INTERNAL_TOKEN=tu_token_secreto
VMSERVER_TIMEOUT=20
```

### 3️⃣ Sincronizar
```bash
php artisan padron:sync
```
✅ Listo. Datos en `socios_padron`

---

## 💻 Usar en Código

```php
use App\Support\GymSocioMaterializer;

// Materializar socio
$user = GymSocioMaterializer::materializeByDniOrSid('12345678');

// Asignar a profesor
$professor->assignedSocios()->attach($user->id);
```

---

## 📚 Dónde Leer

| Necesito... | Leer... |
|------------|---------|
| Comandos rápidos | `PADRON_SYNC_QUICK_REFERENCE.md` |
| Entender todo | `docs/PADRON_SYNC_IMPLEMENTATION.md` |
| Ver ejemplos | `PADRON_SYNC_USAGE_EXAMPLES.php` |
| Integración completa | `EJEMPLO_INTEGRACION_PROFESOR_SOCIOS.php` |
| Validar instalación | `quick_test_padron_sync.php` |
| Arquitectura | `PADRON_SYNC_ARQUITECTURA_FLUJOS.md` |

---

## ✨ Lo que Funciona

- ✅ Sincronización automática cada 2 horas
- ✅ Materialización on-demand de socios
- ✅ Búsqueda por DNI/SID/barcode
- ✅ Asignación a profesores
- ✅ Almacenamiento de datos raw (auditoría)
- ✅ Manejo robusto de errores

---

## 🎯 Caso de Uso Típico

```php
// En controller: Asignar socio a profesor
$socio = GymSocioMaterializer::materializeByDniOrSid('12345678');
$professor->assignedSocios()->attach($socio->id);
// ✅ Hecho
```

---

## 🧪 Validar Instalación

```bash
php artisan tinker
> include 'quick_test_padron_sync.php'
```

Muestra ✓ o ❌ en cada componente.

---

## 📊 Archivos Creados

**Migraciones** (2)
- `database/migrations/2026_02_03_000000_create_socios_padron_table.php`
- `database/migrations/2026_02_03_000001_create_sync_states_table.php`

**Modelos** (2)
- `app/Models/SocioPadron.php`
- `app/Models/SyncState.php`

**Servicio** (1)
- `app/Services/VmServerPadronClient.php`

**Command** (1)
- `app/Console/Commands/PadronSyncCommand.php`

**Helper** (1)
- `app/Support/GymSocioMaterializer.php`

**Config** (2)
- `config/services.php` (actualizado)
- `.env.example` (actualizado)

**Kernel** (1)
- `app/Console/Kernel.php` (scheduler)

**Documentación** (9)
- Guías, ejemplos, referencias, arquitectura

---

## ⚡ Comandos

```bash
# Sincronizar (normal)
php artisan padron:sync

# Sincronizar desde fecha
php artisan padron:sync --since="2026-02-01"

# Sincronizar con opciones
php artisan padron:sync --per-page=1000 --since="2026-02-01"

# Verificar instalación
php quick_test_padron_sync.php
```

---

## 🔍 Búsquedas Rápidas

```php
// Por DNI o SID
$socio = \App\Models\SocioPadron::findByDniOrSid('DNI');

// Por barcode
$socio = \App\Models\SocioPadron::findByBarcode('BAR');

// Ver último sync
$last = \App\Models\SyncState::getValue('padron_last_sync_at');
```

---

## 🎓 Orden de Lectura

1. **Aquí** (este archivo)
2. `PADRON_SYNC_QUICK_REFERENCE.md` (referencia rápida)
3. `PADRON_SYNC_USAGE_EXAMPLES.php` (ejemplos)
4. `docs/PADRON_SYNC_IMPLEMENTATION.md` (técnico)

---

## ❓ FAQ

**P: ¿Necesito instalar paquetes?**
A: No. Todo es nativo de Laravel.

**P: ¿Automático o manual?**
A: Ambos. Automático cada 2h + manual con comando.

**P: ¿Se crean usuarios masivamente?**
A: No. Solo se sincroniza padrón. Usuarios on-demand.

**P: ¿Token seguro?**
A: Sí. En header, no en query string.

**P: ¿Para qué es raw JSON?**
A: Auditoría y recuperación de datos.

---

## 🚨 Si Algo Falla

1. Ejecutar: `php quick_test_padron_sync.php`
2. Ver: `PADRON_SYNC_QUICK_REFERENCE.md` (sección Errores)
3. Revisar: `docs/PADRON_SYNC_IMPLEMENTATION.md` (Troubleshooting)

---

## ✅ Checklist

- [ ] `php artisan migrate`
- [ ] Configurar `.env` (3 vars)
- [ ] `php artisan padron:sync`
- [ ] Verificar: `\App\Models\SocioPadron::count()`
- [ ] Ver docs: `PADRON_SYNC_QUICK_REFERENCE.md`
- [ ] Probar: `GymSocioMaterializer::materializeByDniOrSid('DNI')`

---

## 📞 Soporte

Archivos de ayuda en raíz del proyecto:
- `PADRON_SYNC_QUICK_REFERENCE.md` ← Comienza aquí
- `PADRON_SYNC_USAGE_EXAMPLES.php` ← Ejemplos
- `docs/PADRON_SYNC_IMPLEMENTATION.md` ← Detalles técnicos

---

**¡Listo para usar!** ✅

Próximo paso: `php artisan migrate`
