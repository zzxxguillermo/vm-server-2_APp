<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ProfesorSocioController extends Controller
{
    /**
     * GET /api/admin/profesores
     */
    public function profesores(Request $request)
    {
        $q = User::query()->where('is_professor', true);

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('name')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /api/admin/socios
     * socios = users.user_type = 'api'
     */
    public function socios(Request $request)
    {
        $q = User::query()->where('user_type', 'api');

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('apellido')->orderBy('nombre')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /api/admin/profesores/{profesor}/socios
     */
    public function sociosPorProfesor(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $q = $profesor->sociosAsignados()->where('user_type', 'api');

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    /**
     * POST /api/admin/profesores/{profesor}/socios
     * body: { socio_ids: number[] }
     * SYNC total
     */
    public function syncSocios(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $data = $request->validate([
            'socio_ids'   => 'array',
            'socio_ids.*' => 'integer|exists:users,id',
        ]);

        $ids = $data['socio_ids'] ?? [];

        // Asegurar que sean socios API
        $validSocios = User::query()
            ->whereIn('id', $ids)
            ->where('user_type', 'api')
            ->pluck('id')
            ->all();

        $pairs = [];
        $adminId = auth()->id();

        foreach ($validSocios as $sid) {
            $pairs[$sid] = ['assigned_by' => $adminId];
        }

        $profesor->sociosAsignados()->sync($pairs);

        return response()->json([
            'ok' => true,
            'assigned_count' => count($validSocios),
        ]);
    }
}
