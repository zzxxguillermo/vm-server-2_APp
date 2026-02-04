<?php

namespace App\Http\Controllers\Profesor;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SocioPadron;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SocioController extends Controller
{
    /**
     * GET /api/profesor/socios
     * Lista socios asignados al profesor autenticado.
     * Query params: q (búsqueda), per_page (default 50, max 200)
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function index(Request $request): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $q = trim((string) $request->query('q', ''));

        $query = $profesor->sociosAsignados()
            ->where('users.user_type', 'API')
            ->select([
                'users.id',
                'users.dni',
                'users.socio_id',
                'users.socio_n',
                'users.apellido',
                'users.nombre',
                'users.barcode',
                'users.saldo',
                'users.semaforo',
                'users.estado_socio',
                'users.avatar_path',
            ])
            ->orderBy('users.apellido')
            ->orderBy('users.nombre');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('users.dni', 'like', "%{$q}%")
                    ->orWhere('users.socio_id', 'like', "%{$q}%")
                    ->orWhere('users.socio_n', 'like', "%{$q}%")
                    ->orWhere('users.apellido', 'like', "%{$q}%")
                    ->orWhere('users.nombre', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(users.apellido, ' ', users.nombre) LIKE ?", ["%{$q}%"]);
            });
        }

        $result = $query->paginate($perPage)->appends($request->query());

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * Lista socios desde SocioPadron NOT asignados a ningún profesor.
     * Query params: q (búsqueda por dni/apynom/sid), per_page (default 50, max 200)
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function disponibles(Request $request): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $q = trim((string) $request->query('q', ''));

        // Subquery: socios asignados a cualquier profesor
        $assignedSidsSub = DB::table('professor_socio')
            ->join('socios_padron', 'professor_socio.socio_id', '=', 'socios_padron.id')
            ->select('socios_padron.id')
            ->distinct();

        $query = SocioPadron::query()
            ->whereNotIn('id', $assignedSidsSub)
            ->select([
                'id',
                'dni',
                'sid',
                'apynom',
                'barcode',
                'saldo',
                'semaforo',
                'hab_controles',
            ])
            ->orderBy('apynom')
            ->orderBy('dni');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('dni', 'like', "%{$q}%")
                    ->orWhere('sid', 'like', "%{$q}%")
                    ->orWhere('apynom', 'like', "%{$q}%");
            });
        }

        $padronResult = $query->paginate($perPage)->appends($request->query());

        // Transformar a shape compatible
        $data = $padronResult->map(function (SocioPadron $item) {
            $apellido = '';
            $nombre = '';

            // Parsear apynom: "APELLIDO, NOMBRE" o "APELLIDO NOMBRE"
            if (!empty($item->apynom)) {
                if (strpos($item->apynom, ',') !== false) {
                    [$apellido, $nombre] = array_map('trim', explode(',', $item->apynom, 2));
                } else {
                    $parts = array_map('trim', explode(' ', $item->apynom));
                    $apellido = $parts[0] ?? '';
                    $nombre = implode(' ', array_slice($parts, 1));
                }
            }

            return [
                'id' => $item->id,
                'dni' => $item->dni,
                'socio_id' => $item->sid, // Para compatibilidad
                'socio_n' => $item->sid,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'estado_socio' => null,
                'avatar_path' => null,
                'foto_url' => null,
                'type_label' => 'Socio', // Diferencia clave: es del padrón, no usuario
            ];
        });

        // Reconstruir paginación con datos transformados
        $response = $padronResult->setCollection($data);

        return response()->json(['success' => true, 'data' => $response]);
    }

    /**
     * POST /api/profesor/socios/{socio}
     * Asigna un socio (desde SocioPadron) al profesor autenticado.
     * El parámetro {socio} puede ser un ID de SocioPadron o un User (legacy).
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function store(Request $request, $socioId): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        // Buscar socio en padrón (fallará con 404 si no existe)
        $socio = SocioPadron::findOrFail($socioId);

        // Insertar evitando duplicados (updateOrInsert garantiza idempotencia)
        DB::table('professor_socio')->updateOrInsert(
            ['professor_id' => $profesor->id, 'socio_id' => $socio->id],
            ['assigned_by' => $profesor->id, 'updated_at' => now(), 'created_at' => now()]
        );

        $responseData = [
            'professor_id' => $profesor->id,
            'socio_padron_id' => $socio->id,
            'dni' => $socio->dni,
            'sid' => $socio->sid,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Socio asignado',
            'data' => $responseData,
        ], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socio}
     * Desasigna un socio del profesor autenticado.
     * El parámetro {socio} puede ser un ID de SocioPadron o un User (legacy).
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function destroy(Request $request, $socioId): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $socio = SocioPadron::findOrFail($socioId);

        // Eliminar la asignación si existe
        $deleted = DB::table('professor_socio')
            ->where('professor_id', $profesor->id)
            ->where('socio_id', $socio->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'El socio no está asignado'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Socio desasignado',
            'data' => [
                'professor_id' => $profesor->id,
                'socio_padron_id' => $socio->id,
                'dni' => $socio->dni,
                'sid' => $socio->sid,
            ],
        ]);
    }
}
