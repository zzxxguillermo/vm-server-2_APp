<?php

/**
 * EJEMPLO DE INTEGRACIÓN - Controlador de Asignación de Socios a Profesores
 * 
 * Este archivo muestra cómo integrar el Padron Sync en un controller
 * real para la asignación de socios a profesores.
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SocioPadron;
use App\Support\GymSocioMaterializer;
use App\Services\VmServerPadronClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProfessorSocioAssignmentController extends Controller
{
    /**
     * Asignar un socio a un profesor
     * 
     * POST /api/professors/{professorId}/assign-socio
     * {
     *   "dni_or_sid": "12345678"  // DNI o SID del socio
     * }
     */
    public function assignSocio(Request $request, int $professorId): JsonResponse
    {
        $validated = $request->validate([
            'dni_or_sid' => 'required|string|min:5|max:20',
        ]);

        try {
            $professor = User::findOrFail($professorId);

            if (!$professor->is_professor) {
                return response()->json([
                    'error' => 'El usuario no es profesor',
                ], 403);
            }

            // Materializar el socio desde el padrón
            try {
                $socio = GymSocioMaterializer::materializeByDniOrSid(
                    $validated['dni_or_sid']
                );
            } catch (\Exception $e) {
                // Si no existe en padrón, intentar traerlo desde vmServer
                $socio = $this->fetchAndCreateSocio($validated['dni_or_sid']);
            }

            // Verificar que no esté ya asignado
            if ($professor->assignedSocios()->where('user_id', $socio->id)->exists()) {
                return response()->json([
                    'error' => 'Este socio ya está asignado al profesor',
                ], 409);
            }

            // Realizar asignación (ajustar según tu relación)
            $professor->assignedSocios()->attach($socio->id, [
                'assigned_at' => now(),
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Socio asignado exitosamente',
                'socio' => [
                    'id' => $socio->id,
                    'dni' => $socio->dni,
                    'name' => $socio->name,
                    'email' => $socio->email,
                    'barcode' => $socio->barcode,
                    'saldo' => $socio->saldo,
                    'semaforo' => $socio->semaforo,
                    'estado' => $socio->estado_socio,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo asignar el socio: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Asignar múltiples socios a un profesor
     * 
     * POST /api/professors/{professorId}/assign-socios
     * {
     *   "dni_list": ["12345678", "87654321", ...]
     * }
     */
    public function assignMultipleSocios(Request $request, int $professorId): JsonResponse
    {
        $validated = $request->validate([
            'dni_list' => 'required|array|min:1|max:100',
            'dni_list.*' => 'string|min:5|max:20',
        ]);

        try {
            $professor = User::findOrFail($professorId);

            if (!$professor->is_professor) {
                return response()->json([
                    'error' => 'El usuario no es profesor',
                ], 403);
            }

            // Materializar múltiples
            $result = GymSocioMaterializer::materializeMultiple($validated['dni_list']);

            $assigned = 0;
            $skipped = 0;
            $alreadyAssigned = [];

            foreach ($result['materialized'] as $socio) {
                if ($professor->assignedSocios()->where('user_id', $socio->id)->exists()) {
                    $alreadyAssigned[] = $socio->dni;
                    $skipped++;
                } else {
                    $professor->assignedSocios()->attach($socio->id, [
                        'assigned_at' => now(),
                        'assigned_by' => auth()->id(),
                    ]);
                    $assigned++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Procesados {$result['total']} socios",
                'stats' => [
                    'assigned' => $assigned,
                    'already_assigned' => $skipped,
                    'failed' => $result['failed'],
                    'already_assigned_list' => $alreadyAssigned,
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
     * Remover un socio de un profesor
     * 
     * DELETE /api/professors/{professorId}/socios/{socioId}
     */
    public function removeSocio(int $professorId, int $socioId): JsonResponse
    {
        try {
            $professor = User::findOrFail($professorId);
            $socio = User::findOrFail($socioId);

            $professor->assignedSocios()->detach($socio->id);

            return response()->json([
                'success' => true,
                'message' => 'Socio removido del profesor',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Listar socios asignados a un profesor
     * 
     * GET /api/professors/{professorId}/socios
     */
    public function listAssignedSocios(int $professorId): JsonResponse
    {
        try {
            $professor = User::findOrFail($professorId);

            $socios = $professor->assignedSocios()
                ->paginate(20)
                ->through(fn($socio) => [
                    'id' => $socio->id,
                    'dni' => $socio->dni,
                    'name' => $socio->name,
                    'email' => $socio->email,
                    'barcode' => $socio->barcode,
                    'saldo' => $socio->saldo,
                    'semaforo' => $socio->semaforo,
                    'estado' => $socio->estado_socio,
                    'assigned_at' => $socio->pivot->assigned_at ?? null,
                ]);

            return response()->json($socios);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Sincronizar todos los usuarios existentes con el padrón
     * 
     * POST /api/admin/sync-users-padron
     * Requiere: admin
     */
    public function syncAllUsersWithPadron(): JsonResponse
    {
        try {
            $this->authorize('isAdmin'); // O tu lógica de autorización

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

    /**
     * Buscar un socio en padrón o en vmServer
     * 
     * GET /api/socios/search?q=12345678
     */
    public function searchSocio(Request $request): JsonResponse
    {
        $search = $request->query('q');

        if (!$search || strlen($search) < 5) {
            return response()->json([
                'error' => 'Búsqueda debe tener al menos 5 caracteres',
            ], 400);
        }

        try {
            // Buscar en padrón local primero
            $socio = SocioPadron::findByDniOrSid($search);

            if ($socio) {
                return response()->json([
                    'found' => true,
                    'source' => 'local',
                    'data' => [
                        'dni' => $socio->dni,
                        'sid' => $socio->sid,
                        'name' => $socio->apynom,
                        'barcode' => $socio->barcode,
                        'saldo' => $socio->saldo,
                        'semaforo' => $socio->semaforo,
                        'acceso' => $socio->acceso_full,
                    ],
                ]);
            }

            // Si no encontré en local, buscar en vmServer
            $vmClient = app(VmServerPadronClient::class);
            $vmSocio = $vmClient->fetchSocioByDni($search) 
                ?? $vmClient->fetchSocioBySid($search);

            if ($vmSocio) {
                return response()->json([
                    'found' => true,
                    'source' => 'remote',
                    'data' => $vmSocio,
                    'message' => 'Socio encontrado en vmServer pero no sincronizado localmente. Use assign-socio para materializarlo.',
                ]);
            }

            return response()->json([
                'found' => false,
                'message' => 'Socio no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Helper: Traer socio desde vmServer y materializarlo
     */
    private function fetchAndCreateSocio(string $dniOrSid): User
    {
        $vmClient = app(VmServerPadronClient::class);

        // Intentar encontrar en vmServer
        $vmSocio = $vmClient->fetchSocioByDni($dniOrSid) 
            ?? $vmClient->fetchSocioBySid($dniOrSid);

        if (!$vmSocio) {
            throw new \Exception("Socio no encontrado en vmServer: {$dniOrSid}");
        }

        // Crear en padrón local
        $socioPadron = SocioPadron::create([
            'dni' => $vmSocio['dni'] ?? null,
            'sid' => $vmSocio['sid'] ?? null,
            'apynom' => $vmSocio['apynom'] ?? $vmSocio['apellido'] ?? null,
            'barcode' => $vmSocio['barcode'] ?? null,
            'saldo' => isset($vmSocio['saldo']) ? (float) $vmSocio['saldo'] : null,
            'semaforo' => isset($vmSocio['semaforo']) ? (int) $vmSocio['semaforo'] : null,
            'ult_impago' => isset($vmSocio['ult_impago']) ? (int) $vmSocio['ult_impago'] : null,
            'acceso_full' => (bool) ($vmSocio['acceso_full'] ?? false),
            'hab_controles' => (bool) ($vmSocio['hab_controles'] ?? true),
            'hab_controles_raw' => isset($vmSocio['hab_controles_raw']) ? (array) $vmSocio['hab_controles_raw'] : null,
            'raw' => $vmSocio,
        ]);

        // Materializar a usuario
        return GymSocioMaterializer::materializeByDniOrSid($dniOrSid);
    }
}

// ============================================================================
// ROUTES
// ============================================================================

/*
// routes/api.php

Route::middleware(['auth:sanctum'])->group(function () {
    // Asignación de socios
    Route::post('/professors/{professorId}/assign-socio', [
        ProfessorSocioAssignmentController::class, 'assignSocio'
    ])->name('assign.socio');

    Route::post('/professors/{professorId}/assign-socios', [
        ProfessorSocioAssignmentController::class, 'assignMultipleSocios'
    ])->name('assign.socios');

    Route::delete('/professors/{professorId}/socios/{socioId}', [
        ProfessorSocioAssignmentController::class, 'removeSocio'
    ])->name('remove.socio');

    Route::get('/professors/{professorId}/socios', [
        ProfessorSocioAssignmentController::class, 'listAssignedSocios'
    ])->name('list.socios');

    // Búsqueda
    Route::get('/socios/search', [
        ProfessorSocioAssignmentController::class, 'searchSocio'
    ])->name('search.socio');
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Admin only
    Route::post('/admin/sync-users-padron', [
        ProfessorSocioAssignmentController::class, 'syncAllUsersWithPadron'
    ])->name('sync.users.padron');
});
*/

// ============================================================================
// EJEMPLOS DE REQUESTS
// ============================================================================

/*
ASIGNAR UN SOCIO:
POST /api/professors/1/assign-socio
Content-Type: application/json

{
  "dni_or_sid": "12345678"
}

RESPUESTA:
{
  "success": true,
  "message": "Socio asignado exitosamente",
  "socio": {
    "id": 42,
    "dni": "12345678",
    "name": "Pérez, Juan",
    "email": "socio.12345678@gimnasio.local",
    "barcode": "BAR123456",
    "saldo": 100.50,
    "semaforo": 1,
    "estado": "ACTIVO"
  }
}

---

ASIGNAR MÚLTIPLES:
POST /api/professors/1/assign-socios
Content-Type: application/json

{
  "dni_list": ["12345678", "87654321", "11111111"]
}

RESPUESTA:
{
  "success": true,
  "message": "Procesados 3 socios",
  "stats": {
    "assigned": 2,
    "already_assigned": 1,
    "failed": 0,
    "already_assigned_list": ["11111111"],
    "errors": {}
  }
}

---

BUSCAR SOCIO:
GET /api/socios/search?q=12345678

RESPUESTA (en padrón local):
{
  "found": true,
  "source": "local",
  "data": {
    "dni": "12345678",
    "sid": "SID123",
    "name": "Pérez, Juan",
    "barcode": "BAR123456",
    "saldo": 100.50,
    "semaforo": 1,
    "acceso": true
  }
}

---

SINCRONIZAR TODOS LOS USUARIOS:
POST /api/admin/sync-users-padron

RESPUESTA:
{
  "success": true,
  "message": "Sincronización completada",
  "stats": {
    "updated": 45,
    "created": 0,
    "skipped": 15,
    "errors": []
  }
}
*/
