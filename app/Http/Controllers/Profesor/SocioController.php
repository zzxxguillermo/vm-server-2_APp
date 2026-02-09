<?php

namespace App\Http\Controllers\Profesor;

use App\Http\Controllers\Controller;
use App\Models\SocioPadron;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SocioController extends Controller
{
    private function ensureProfessorOrAdmin($user): void
    {
        abort_unless(
            $user && ((bool)($user->is_professor ?? false) || (bool)($user->is_admin ?? false) || (bool)($user->is_super_admin ?? false)),
            403,
            'No autorizado'
        );
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => (int) $paginator->currentPage(),
            'per_page'     => (int) $paginator->perPage(),
            'total'        => (int) $paginator->total(),
            'last_page'    => (int) $paginator->lastPage(),
        ];
    }

    private function mapPadronItem($item): array
    {
        $apellido = '';
        $nombre = '';

        $apynom = (string) ($item->apynom ?? '');
        if ($apynom !== '') {
            if (strpos($apynom, ',') !== false) {
                [$apellido, $nombre] = array_map('trim', explode(',', $apynom, 2));
            } else {
                $parts = array_map('trim', preg_split('/\s+/', $apynom));
                $apellido = $parts[0] ?? '';
                $nombre = implode(' ', array_slice($parts, 1));
            }
        }

        return [
            'id'        => (int) $item->id,      // id socios_padron
            'dni'       => $item->dni,
            'sid'       => $item->sid,
            'socio_id'  => $item->sid,           // compat
            'socio_n'   => $item->sid,           // compat
            'apellido'  => $apellido,
            'nombre'    => $nombre,
            'apynom'    => $item->apynom,
            'barcode'   => $item->barcode,
            'saldo'     => $item->saldo,
            'semaforo'  => $item->semaforo,
            'hab_controles' => $item->hab_controles,
            'estado_socio'  => null,
            'avatar_path'   => null,
            'foto_url'      => null,
            'type_label'    => 'Socio',
        ];
    }

    /**
     * GET /api/profesor/socios
     * Lista socios (SocioPadron) asignados al profesor autenticado.
     * Permite profesor/admin/super_admin (por si estás usando el panel con admin).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // ✅ antes era “solo profesores” -> ahora permite admin/super_admin también
        $this->ensureProfessorOrAdmin($user);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $page    = (int) $request->query('page', 1);
        $q       = trim((string) $request->query('q', ''));

        $query = SocioPadron::query()
            ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
            ->where('professor_socio.professor_id', $user->id)
            ->select([
                'socios_padron.id',
                'socios_padron.dni',
                'socios_padron.sid',
                'socios_padron.apynom',
                'socios_padron.barcode',
                'socios_padron.saldo',
                'socios_padron.semaforo',
                'socios_padron.hab_controles',
            ])
            ->orderBy('socios_padron.apynom')
            ->orderBy('socios_padron.dni');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('socios_padron.dni', 'like', "%{$q}%")
                  ->orWhere('socios_padron.sid', 'like', "%{$q}%")
                  ->orWhere('socios_padron.apynom', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $socios = $paginator->getCollection()->map(fn($item) => $this->mapPadronItem($item))->values();

        return response()->json([
            'success' => true,
            'socios' => $socios,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * SociosPadron NO asignados a ningún profesor.
     * Permite profesor/admin/super_admin.
     */
    public function disponibles(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // ✅ antes era “solo profesores”
        $this->ensureProfessorOrAdmin($user);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $page    = (int) $request->query('page', 1);
        $q       = trim((string) $request->query('q', ''));

        $assignedIdsSub = DB::table('professor_socio')
            ->select('socio_id')
            ->distinct();

        $query = SocioPadron::query()
            ->whereNotIn('id', $assignedIdsSub)
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

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $socios = $paginator->getCollection()->map(fn($item) => $this->mapPadronItem($item))->values();

        return response()->json([
            'success' => true,
            'socios' => $socios,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * POST /api/profesor/socios/{socio}
     * {socio} = SocioPadron id
     */
    public function store(Request $request, $socioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $this->ensureProfessorOrAdmin($user);

        $socio = SocioPadron::findOrFail($socioId);

        DB::table('professor_socio')->updateOrInsert(
            ['professor_id' => $user->id, 'socio_id' => $socio->id],
            ['assigned_by' => $user->id, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Socio asignado',
            'data' => [
                'professor_id' => $user->id,
                'socio_padron_id' => $socio->id,
                'dni' => $socio->dni,
                'sid' => $socio->sid,
            ],
        ], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socio}
     * {socio} = SocioPadron id
     */
    public function destroy(Request $request, $socioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $this->ensureProfessorOrAdmin($user);

        $socio = SocioPadron::findOrFail($socioId);

        $deleted = DB::table('professor_socio')
            ->where('professor_id', $user->id)
            ->where('socio_id', $socio->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'El socio no está asignado'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Socio desasignado',
            'data' => [
                'professor_id' => $user->id,
                'socio_padron_id' => $socio->id,
                'dni' => $socio->dni,
                'sid' => $socio->sid,
            ],
        ]);
    }
}
