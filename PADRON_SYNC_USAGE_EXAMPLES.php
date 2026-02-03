<?php

/**
 * EJEMPLOS DE USO - Padrón Sync System
 * 
 * Este archivo muestra cómo usar el sistema de sincronización de socios
 * desde vmServer hacia la tabla local socios_padron
 */

// ============================================================================
// 1. SINCRONIZACIÓN VÍA ARTISAN COMMAND
// ============================================================================

// Sincronización normal (desde último sync registrado o últimas 24h)
// php artisan padron:sync

// Sincronización desde fecha específica
// php artisan padron:sync --since="2026-02-01T00:00:00Z"

// Sincronización con más registros por página
// php artisan padron:sync --per-page=1000

// Combinado: desde fecha + 750 por página
// php artisan padron:sync --since="2026-02-01" --per-page=750


// ============================================================================
// 2. USO PROGRAMÁTICO DEL CLIENTE VMSERVER
// ============================================================================

namespace App\Http\Controllers;

use App\Services\VmServerPadronClient;
use Illuminate\Http\Request;

class SocioImportController extends Controller
{
    protected VmServerPadronClient $padronClient;

    public function __construct(VmServerPadronClient $padronClient)
    {
        $this->padronClient = $padronClient;
    }

    /**
     * Ejemplo: Obtener socios modificados desde una fecha
     */
    public function importSociosFromDate(Request $request)
    {
        $since = $request->query('since', now()->subDay()->toIso8601String());
        
        try {
            $response = $this->padronClient->fetchSocios([
                'updated_since' => $since,
                'page' => 1,
                'per_page' => 100,
            ]);

            return response()->json([
                'count' => count($response['data'] ?? []),
                'data' => $response['data'] ?? [],
                'pagination' => $response['pagination'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Ejemplo: Buscar un socio específico por DNI
     */
    public function findSocioByDni($dni)
    {
        try {
            $socio = $this->padronClient->fetchSocioByDni($dni);
            
            if (!$socio) {
                return response()->json(['error' => 'Socio no encontrado'], 404);
            }

            return response()->json($socio);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Ejemplo: Buscar un socio específico por SID
     */
    public function findSocioBySid($sid)
    {
        try {
            $socio = $this->padronClient->fetchSocioBySid($sid);
            
            if (!$socio) {
                return response()->json(['error' => 'Socio no encontrado'], 404);
            }

            return response()->json($socio);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}


// ============================================================================
// 3. USO DEL MATERIALIZER - CONVERTIR SOCIO PADRÓN A USUARIO
// ============================================================================

namespace App\Http\Controllers;

use App\Support\GymSocioMaterializer;
use App\Models\User;
use Illuminate\Http\Request;

class ProfessorSocioAssignmentController extends Controller
{
    /**
     * Asignar socio a profesor (materializar on-demand)
     */
    public function assignSocioToProfessor(Request $request, User $professor)
    {
        $validated = $request->validate([
            'dni_or_sid' => 'required|string|min:5|max:20',
        ]);

        try {
            // Materializar el socio (crea/actualiza User desde SocioPadron)
            $socio = GymSocioMaterializer::materializeByDniOrSid(
                $validated['dni_or_sid']
            );

            // Aquí hacer la asignación específica según tu lógica
            // Ejemplo: agregar a relación many-to-many
            if (!$professor->assignedSocios()->where('user_id', $socio->id)->exists()) {
                $professor->assignedSocios()->attach($socio->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Socio asignado exitosamente',
                'socio' => $socio->only([
                    'id', 'dni', 'name', 'email', 'barcode', 'saldo', 'semaforo'
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo asignar el socio: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Asignar múltiples socios a profesor
     */
    public function assignMultipleSocios(Request $request, User $professor)
    {
        $validated = $request->validate([
            'dni_list' => 'required|array|min:1',
            'dni_list.*' => 'string|min:5|max:20',
        ]);

        try {
            $result = GymSocioMaterializer::materializeMultiple(
                $validated['dni_list']
            );

            if ($result['total'] > 0) {
                // Asignar todos los materializados
                foreach ($result['materialized'] as $socio) {
                    $professor->assignedSocios()->attach($socio->id);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Asignados {$result['total']} socios",
                'stats' => [
                    'assigned' => $result['total'],
                    'failed' => $result['failed'],
                    'errors' => $result['errors'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Sincronizar usuarios existentes con padrón
     */
    public function syncExistingUsersWithPadron()
    {
        try {
            $stats = GymSocioMaterializer::syncExistingUsers();

            return response()->json([
                'success' => true,
                'message' => 'Sincronización completada',
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}


// ============================================================================
// 4. USO DE SYNC STATES - RASTREAR SINCRONIZACIONES
// ============================================================================

namespace App\Services;

use App\Models\SyncState;

class SyncStateUsageExample
{
    public function examples()
    {
        // Obtener valor de un sync state
        $lastSync = SyncState::getValue('padron_last_sync_at');
        echo "Último sync: " . $lastSync . "\n";

        // Establecer un valor
        SyncState::setValue('padron_last_sync_at', now()->toIso8601String());

        // Obtener con default
        $lastSync = SyncState::getValue('padron_last_sync_at', 'Never');
        
        // Obtener timestamp de última actualización
        $timestamp = SyncState::getLastSyncTimestamp('padron_last_sync_at');
        echo "Actualizado en: " . $timestamp . "\n";

        // Múltiples sync states
        SyncState::setValue('templates_last_sync_at', now()->toIso8601String());
        SyncState::setValue('assignments_last_sync_at', now()->toIso8601String());
    }
}


// ============================================================================
// 5. CONSULTAS A LA TABLA SOCIOS_PADRON
// ============================================================================

namespace App\Http\Controllers;

use App\Models\SocioPadron;

class SocioPadronQueryController extends Controller
{
    public function examples()
    {
        // Buscar por DNI o SID (método helper)
        $socio = SocioPadron::findByDniOrSid('12345678');
        
        // Buscar por barcode
        $socio = SocioPadron::findByBarcode('BARCODE123');
        
        // Consultas manuales
        $socio = SocioPadron::where('dni', '12345678')->first();
        $socio = SocioPadron::where('sid', 'SID123')->first();
        
        // Múltiples resultados
        $socios = SocioPadron::where('acceso_full', true)->get();
        
        // Con paginación
        $socios = SocioPadron::paginate(50);
        
        // Filtrar por semáforo
        $activos = SocioPadron::where('semaforo', 1)->get();
        
        // Filtrar por rango de saldo
        $conDeuda = SocioPadron::where('saldo', '<', 0)->get();
        
        // Acceder a datos raw
        foreach ($socios as $socio) {
            $rawData = $socio->raw; // Array completo de la API
            $controlData = $socio->hab_controles_raw; // Array de controles
        }
    }
}


// ============================================================================
// 6. INTEGRACIÓN EN ROUTES (ejemplos)
// ============================================================================

/*
// routes/api.php (ejemplo)

Route::middleware(['auth:sanctum'])->group(function () {
    // Endpoint para asignar socio a profesor
    Route::post('/professors/{professor}/assign-socio', [
        'ProfessorSocioAssignmentController@assignSocioToProfessor'
    ])->name('assign.socio');

    // Endpoint para sincronizar usuarios
    Route::post('/sync-users-padron', [
        'ProfessorSocioAssignmentController@syncExistingUsersWithPadron'
    ])->name('sync.users.padron');

    // Endpoint para buscar socio
    Route::get('/socios/search', [
        'SocioImportController@findSocioByDni'
    ])->name('search.socio');
});
*/


// ============================================================================
// 7. TESTING EJEMPLOS
// ============================================================================

namespace Tests\Feature;

use App\Models\SocioPadron;
use App\Models\User;
use App\Support\GymSocioMaterializer;
use Tests\TestCase;

class PadronSyncTest extends TestCase
{
    /**
     * Test materializar socio
     */
    public function test_materialize_socio_by_dni()
    {
        // Crear socio en padrón
        $socio = SocioPadron::create([
            'dni' => '12345678',
            'sid' => 'SID123',
            'apynom' => 'Pérez, Juan',
            'barcode' => 'BAR123',
            'saldo' => 100.50,
            'semaforo' => 1,
            'acceso_full' => true,
        ]);

        // Materializar a usuario
        $user = GymSocioMaterializer::materializeByDniOrSid('12345678');

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('12345678', $user->dni);
        $this->assertEquals('SID123', $user->socio_id);
        $this->assertEquals('Juan', $user->nombre);
    }

    /**
     * Test sincronización batch
     */
    public function test_materialize_multiple_socios()
    {
        // Crear socios en padrón
        SocioPadron::create([
            'dni' => '11111111',
            'apynom' => 'Smith, John',
        ]);
        SocioPadron::create([
            'dni' => '22222222',
            'apynom' => 'Doe, Jane',
        ]);

        // Materializar múltiples
        $result = GymSocioMaterializer::materializeMultiple([
            '11111111',
            '22222222',
            '99999999', // Este va a fallar
        ]);

        $this->assertEquals(2, $result['total']);
        $this->assertEquals(1, $result['failed']);
        $this->assertArrayHasKey('99999999', $result['errors']);
    }

    /**
     * Test comando sync
     */
    public function test_padron_sync_command()
    {
        $this->artisan('padron:sync')
            ->expectsOutput('Sincronización completada')
            ->assertExitCode(0);
    }
}


// ============================================================================
// 8. ARTISAN TINKER - EJEMPLOS RÁPIDOS
// ============================================================================

/*
php artisan tinker

// Ver último sync
\App\Models\SyncState::getValue('padron_last_sync_at');

// Ver cantidad de socios en padrón
\App\Models\SocioPadron::count();

// Ver socios activos
\App\Models\SocioPadron::where('acceso_full', true)->count();

// Buscar socio
\App\Models\SocioPadron::where('dni', '12345678')->first();

// Materializar socio
\App\Support\GymSocioMaterializer::materializeByDniOrSid('12345678');

// Ver usuario materializado
\App\Models\User::where('dni', '12345678')->first();
*/
