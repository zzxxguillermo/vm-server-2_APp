<?php

namespace Tests\Feature\Gym;

use App\Models\User;
use App\Models\SocioPadron;
use App\Services\PublicAccess\GymShareTokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTemplatesContractTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function auth_student_without_professor_assignment_returns_stable_contract()
    {
        $user = User::factory()->create([
            'dni' => '12345678',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/my-templates');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'professor',
                'templates',
            ],
        ]);

        $this->assertNull($response->json('data.professor'));
        $this->assertIsArray($response->json('data.templates'));
        $this->assertSame([], $response->json('data.templates'));
    }

    /** @test */
    public function public_token_flow_uses_same_contract_when_no_professor_assignment()
    {
        // Ensure bypass path is not used
        config(['services.public_templates.bypass_enabled' => false]);

        $dni = '23456789';

        // Create local user for this DNI
        User::factory()->create([
            'dni' => $dni,
        ]);

        // Stub the token validator to return our DNI without touching real HMAC/env
        $fakeValidator = new class($dni) extends GymShareTokenValidator {
            public function __construct(private string $dni) {}

            public function validate(string $token): array
            {
                return [
                    'dni' => $this->dni,
                    'ts' => time(),
                ];
            }
        };

        $this->instance(GymShareTokenValidator::class, $fakeValidator);

        $response = $this->getJson('/api/public/student/my-templates?token=fake-token');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'professor',
                'templates',
            ],
        ]);

        $this->assertNull($response->json('data.professor'));
        $this->assertIsArray($response->json('data.templates'));
    }

    /** @test */
    public function public_token_flow_materializes_user_from_padron_and_respects_contract()
    {
        // Disable vmServer user lookup so we hit the SocioPadron fallback
        config(['services.vmserver.base_url' => null]);
        config(['services.vmserver.internal_token' => null]);
        config(['services.vmserver.token' => null]);
        config(['services.public_templates.bypass_enabled' => false]);

        $dni = '36329083';

        // Seed SocioPadron with this DNI but do NOT create a User row
        SocioPadron::create([
            'dni' => $dni,
            'sid' => 'SID-' . $dni,
            'apynom' => 'TEST, USER',
            'barcode' => 'BAR-' . $dni,
            'saldo' => 0,
            'semaforo' => 1,
            'ult_impago' => 0,
            'acceso_full' => true,
            'hab_controles' => true,
            'hab_controles_raw' => [],
            'raw' => [],
        ]);

        $fakeValidator = new class($dni) extends GymShareTokenValidator {
            public function __construct(private string $dni) {}

            public function validate(string $token): array
            {
                return [
                    'dni' => $this->dni,
                    'ts' => time(),
                ];
            }
        };

        $this->instance(GymShareTokenValidator::class, $fakeValidator);

        $response = $this->getJson('/api/public/student/my-templates?token=fake-token');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'professor',
                'templates',
            ],
        ]);

        $this->assertNull($response->json('data.professor'));
        $this->assertIsArray($response->json('data.templates'));

        // Ensure the user was materialized from SocioPadron
        $this->assertDatabaseHas('users', [
            'dni' => $dni,
        ]);
    }
}
