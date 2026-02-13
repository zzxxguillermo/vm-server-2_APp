<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PublicAccess\GymShareTokenException;
use App\Services\PublicAccess\GymShareTokenValidator;
use App\Support\GymSocioMaterializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StudentPublicTemplatesController extends Controller
{
    public function myTemplates(Request $request, GymShareTokenValidator $validator): JsonResponse
    {
        $token = $request->query('token');
        $dni = null;

        if ($token) {
            try {
                $data = $validator->validate($token);
            } catch (GymShareTokenException $e) {
                if ($e->getMessage() === 'missing_secret') {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Internal server error',
                    ], 500);
                }

                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $dni = $data['dni'];
        } else {
            $bypassEnabled = config('services.public_templates.bypass_enabled');

            if (!$bypassEnabled) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $demoKey = config('services.public_templates.demo_key');
            $headerKey = $request->header('X-Demo-Key');

            if (!$demoKey || $headerKey !== $demoKey) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $dni = $request->query('dni');
            $dni = is_null($dni) ? null : (string) $dni;

            if (!is_string($dni) || !preg_match('/^\d{7,9}$/', $dni)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Invalid dni',
                ], 422);
            }
        }

        $user = User::where('dni', $dni)->first();

        if (!$user) {
            $baseUrl = config('services.vmserver.base_url');
            $token   = config('services.vmserver.internal_token') ?: config('services.vmserver.token');
            $timeout = (int) (config('services.vmserver.timeout') ?: 8);

            if ($baseUrl && $token) {
                $url = rtrim($baseUrl, '/') . '/api/internal/users/by-dni/' . $dni;

                try {
                    $resp = Http::withToken($token)->timeout($timeout)->get($url);
                } catch (\Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Upstream server error',
                    ], 502);
                }

                if ($resp->status() === 404) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'User not found',
                    ], 404);
                }

                if (!$resp->successful()) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Upstream server error',
                    ], 502);
                }

                $remote = $resp->json('user') ?? null;

                if (is_array($remote) && !empty($remote['dni'])) {
                    $user = User::updateOrCreate(
                        ['dni' => (string) $remote['dni']],
                        [
                            'name'  => $remote['name'] ?? (string) $remote['dni'],
                            'email' => $remote['email'] ?? null,
                        ]
                    );
                }
            }
        }

        if (!$user) {
			try {
				$user = GymSocioMaterializer::materializeByDniOrSid((string) $dni);
			} catch (\Throwable $e) {
				// Mantener el comportamiento actual: devolver 404 si no se puede materializar
			}
		}

        if (!$user) {
            return response()->json([
                'ok' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Reutilizar el controlador existente de estudiante para mantener la misma respuesta
        $internalRequest = Request::create($request->getRequestUri(), 'GET', $request->query());
        $internalRequest->setUserResolver(function () use ($user) {
            return $user;
        });

        $assignmentController = app(\App\Http\Controllers\Gym\Student\AssignmentController::class);

        /** @var JsonResponse $response */
        $response = app()->call([$assignmentController, 'myTemplates'], ['request' => $internalRequest]);

        return $response;
    }
}
