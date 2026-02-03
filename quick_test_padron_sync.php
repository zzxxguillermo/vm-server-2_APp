<?php

/**
 * QUICK TEST - Validar implementación de Padron Sync
 * 
 * Ejecutar desde la raíz del proyecto:
 * php quick_test_padron_sync.php
 * 
 * O en artisan tinker:
 * > include 'quick_test_padron_sync.php'
 */

use App\Models\SocioPadron;
use App\Models\SyncState;
use App\Models\User;
use App\Services\VmServerPadronClient;
use App\Support\GymSocioMaterializer;

echo "\n";
echo "========================================\n";
echo "  PADRON SYNC - QUICK TEST\n";
echo "========================================\n\n";

// ============================================================================
// 1. VERIFICAR CONFIGURACIÓN
// ============================================================================

echo "1️⃣  VERIFICANDO CONFIGURACIÓN...\n";

$config = config('services.vmserver');

if (!$config['base_url']) {
    echo "  ❌ VMSERVER_BASE_URL no configurado\n";
    echo "     Agregá en .env: VMSERVER_BASE_URL=...\n";
} else {
    echo "  ✓ Base URL: {$config['base_url']}\n";
}

if (!$config['internal_token']) {
    echo "  ❌ VMSERVER_INTERNAL_TOKEN no configurado\n";
    echo "     Agregá en .env: VMSERVER_INTERNAL_TOKEN=...\n";
} else {
    echo "  ✓ Token interno: " . substr($config['internal_token'], 0, 10) . "...\n";
}

echo "  ✓ Timeout: {$config['timeout']} segundos\n\n";

// ============================================================================
// 2. VERIFICAR TABLAS
// ============================================================================

echo "2️⃣  VERIFICANDO TABLAS...\n";

try {
    $padronCount = SocioPadron::count();
    echo "  ✓ Tabla socios_padron: OK ($padronCount registros)\n";
} catch (\Exception $e) {
    echo "  ❌ Tabla socios_padron: ERROR - " . $e->getMessage() . "\n";
    echo "     Ejecutá: php artisan migrate\n";
}

try {
    $syncCount = SyncState::count();
    echo "  ✓ Tabla sync_states: OK ($syncCount registros)\n";
} catch (\Exception $e) {
    echo "  ❌ Tabla sync_states: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 3. VERIFICAR MODELOS
// ============================================================================

echo "3️⃣  VERIFICANDO MODELOS...\n";

try {
    $socio = new SocioPadron();
    echo "  ✓ Modelo SocioPadron: OK\n";
    echo "    Fillable: " . implode(', ', $socio->getFillable()) . "\n";
} catch (\Exception $e) {
    echo "  ❌ Modelo SocioPadron: ERROR\n";
}

try {
    $state = new SyncState();
    echo "  ✓ Modelo SyncState: OK\n";
} catch (\Exception $e) {
    echo "  ❌ Modelo SyncState: ERROR\n";
}

echo "\n";

// ============================================================================
// 4. VERIFICAR SERVICE
// ============================================================================

echo "4️⃣  VERIFICANDO VmServerPadronClient...\n";

try {
    $client = app(VmServerPadronClient::class);
    echo "  ✓ Cliente inyectado correctamente\n";
    
    // Intentar una llamada de prueba (va a fallar si no está configurado)
    try {
        $response = $client->fetchSocios(['page' => 1, 'per_page' => 1]);
        echo "  ✓ Llamada a vmServer: OK\n";
        echo "    Estructura: " . implode(', ', array_keys($response)) . "\n";
    } catch (\Exception $e) {
        echo "  ⚠️  Llamada a vmServer: ERROR (esperado si no está configurado)\n";
        echo "     " . substr($e->getMessage(), 0, 80) . "...\n";
    }
} catch (\Exception $e) {
    echo "  ❌ Cliente no disponible: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 5. VERIFICAR MATERIALIZER
// ============================================================================

echo "5️⃣  VERIFICANDO GymSocioMaterializer...\n";

try {
    // Crear un socio de prueba
    $testSocio = SocioPadron::create([
        'dni' => '99999999',
        'sid' => 'TEST999',
        'apynom' => 'Testeo, Usuario',
        'barcode' => 'TEST-BARCODE-' . time(),
        'saldo' => 50.0,
        'semaforo' => 1,
        'acceso_full' => true,
    ]);
    
    echo "  ✓ Socio de prueba creado: DNI=99999999\n";

    // Intentar materializar
    try {
        $user = GymSocioMaterializer::materializeByDniOrSid('99999999');
        echo "  ✓ Materialización exitosa\n";
        echo "    Usuario ID: {$user->id}\n";
        echo "    DNI: {$user->dni}\n";
        echo "    Nombre: {$user->name}\n";
        echo "    Tipo: {$user->user_type}\n";
        
        // Limpiar
        $testSocio->delete();
        $user->delete();
        echo "  ✓ Limpios de prueba eliminados\n";
    } catch (\Exception $e) {
        echo "  ❌ Materialización falló: " . $e->getMessage() . "\n";
        $testSocio->delete();
    }
} catch (\Exception $e) {
    echo "  ❌ Error creando socio de prueba: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 6. VERIFICAR SYNC STATE
// ============================================================================

echo "6️⃣  VERIFICANDO SyncState...\n";

try {
    // Escribir
    SyncState::setValue('test_key', 'test_value');
    echo "  ✓ Escritura de SyncState: OK\n";
    
    // Leer
    $value = SyncState::getValue('test_key');
    if ($value === 'test_value') {
        echo "  ✓ Lectura de SyncState: OK\n";
    } else {
        echo "  ❌ Lectura de SyncState: Valor incorrecto\n";
    }
    
    // Timestamp
    $timestamp = SyncState::getLastSyncTimestamp('test_key');
    echo "  ✓ Timestamp: " . $timestamp . "\n";
    
    // Limpiar
    SyncState::where('key', 'test_key')->delete();
} catch (\Exception $e) {
    echo "  ❌ Error en SyncState: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 7. VERIFICAR COMANDO
// ============================================================================

echo "7️⃣  VERIFICANDO Comando padron:sync...\n";

try {
    // Verificar que el comando existe
    $commands = Artisan::all();
    $padronSyncExists = isset($commands['padron:sync']);
    
    if ($padronSyncExists) {
        echo "  ✓ Comando 'padron:sync' registrado\n";
        echo "\n    Uso:\n";
        echo "      php artisan padron:sync\n";
        echo "      php artisan padron:sync --since=\"2026-02-01\"\n";
        echo "      php artisan padron:sync --per-page=1000\n";
    } else {
        echo "  ❌ Comando 'padron:sync' no encontrado\n";
        echo "     Verificá que PadronSyncCommand.php existe\n";
    }
} catch (\Exception $e) {
    echo "  ⚠️  No se pudo verificar comando: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 8. RESUMEN
// ============================================================================

echo "========================================\n";
echo "  ✅ TEST COMPLETADO\n";
echo "========================================\n\n";

echo "PRÓXIMOS PASOS:\n\n";
echo "1. Configurar variables de entorno en .env:\n";
echo "   VMSERVER_BASE_URL=https://...\n";
echo "   VMSERVER_INTERNAL_TOKEN=...\n";
echo "   VMSERVER_TIMEOUT=20\n\n";

echo "2. Ejecutar primera sincronización:\n";
echo "   php artisan padron:sync\n\n";

echo "3. Verificar datos sincronizados:\n";
echo "   php artisan tinker\n";
echo "   > \\App\\Models\\SocioPadron::count()\n";
echo "   > \\App\\Models\\SyncState::getValue('padron_last_sync_at')\n\n";

echo "4. Probar materialización:\n";
echo "   > \\App\\Support\\GymSocioMaterializer::materializeByDniOrSid('dni_existente')\n\n";

echo "📖 Ver documentación en: docs/PADRON_SYNC_IMPLEMENTATION.md\n";
echo "📝 Ejemplos de uso en: PADRON_SYNC_USAGE_EXAMPLES.php\n\n";
