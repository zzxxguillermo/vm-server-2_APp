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
