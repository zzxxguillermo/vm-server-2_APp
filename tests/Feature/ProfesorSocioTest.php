<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ProfesorSocioTest extends TestCase
{
    use RefreshDatabase;

    protected $profesor;
    protected $socio1;
    protected $socio2;
    protected $socio3;
    protected $noProfesor;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear profesor
        $this->profesor = User::factory()->create([
            'name' => 'Profesor Test',
            'email' => 'profesor@test.com',
            'dni' => '11111111',
            'is_professor' => true,
            'user_type' => UserType::LOCAL,
        ]);

        // Crear socios (usuarios API)
        $this->socio1 = User::factory()->create([
            'name' => 'Socio 1',
            'email' => 'socio1@test.com',
            'dni' => '22222222',
            'nombre' => 'Juan',
            'apellido' => 'García',
            'user_type' => UserType::API,
        ]);

        $this->socio2 = User::factory()->create([
            'name' => 'Socio 2',
            'email' => 'socio2@test.com',
            'dni' => '33333333',
            'nombre' => 'María',
            'apellido' => 'López',
            'user_type' => UserType::API,
        ]);

        $this->socio3 = User::factory()->create([
            'name' => 'Socio 3',
            'email' => 'socio3@test.com',
            'dni' => '44444444',
            'nombre' => 'Carlos',
            'apellido' => 'Martínez',
            'user_type' => UserType::API,
        ]);

        // Usuario que no es profesor
        $this->noProfesor = User::factory()->create([
            'name' => 'No Profesor',
            'email' => 'noprof@test.com',
            'dni' => '55555555',
            'is_professor' => false,
            'user_type' => UserType::LOCAL,
        ]);
    }

    /**
     * Prueba: GET /api/profesor/socios
     * - Sin autenticación: 401
     * - Como no profesor: 403
     * - Como profesor: 200 con lista vacía inicialmente
     */
    public function test_profesor_socios_index_requires_authentication()
    {
        $response = $this->getJson('/api/profesor/socios');
        $response->assertUnauthorized();
    }

    public function test_profesor_socios_index_requires_professor_role()
    {
        $response = $this->actingAs($this->noProfesor)
            ->getJson('/api/profesor/socios');
        $response->assertForbidden();
    }

    public function test_profesor_socios_index_returns_empty_list()
    {
        $response = $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios');

        $response->assertOk()
                 ->assertJson([
                     'ok' => true,
                     'data' => [
                         'data' => [],
                     ],
                 ]);
    }

    public function test_profesor_socios_index_returns_assigned_socios()
    {
        // Asignar socios al profesor
        $this->profesor->sociosAsignados()->attach([$this->socio1->id, $this->socio2->id]);

        $response = $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios');

        $response->assertOk()
                 ->assertJson(['ok' => true])
                 ->assertJsonCount(2, 'data.data');
    }

    public function test_profesor_socios_index_search_by_dni()
    {
        $this->profesor->sociosAsignados()->attach($this->socio1->id);

        $response = $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios?search=22222222');

        $response->assertOk()
                 ->assertJson(['ok' => true])
                 ->assertJsonCount(1, 'data.data');
    }

    /**
     * Prueba: GET /api/profesor/socios/disponibles
     */
    public function test_profesor_socios_disponibles_returns_unassigned()
    {
        // Asignar solo socio1
        $this->profesor->sociosAsignados()->attach($this->socio1->id);

        $response = $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios/disponibles');

        $response->assertOk()
                 ->assertJson(['ok' => true])
                 ->assertJsonCount(2, 'data.data'); // socio2 y socio3
    }

    public function test_profesor_socios_disponibles_excludes_assigned()
    {
        $this->profesor->sociosAsignados()->attach([$this->socio1->id, $this->socio2->id]);

        $response = $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios/disponibles');

        $response->assertOk()
                 ->assertJson(['ok' => true])
                 ->assertJsonCount(1, 'data.data'); // solo socio3
    }

    /**
     * Prueba: POST /api/profesor/socios/{socioId}
     */
    public function test_profesor_puede_asignarse_socio()
    {
        $response = $this->actingAs($this->profesor)
            ->postJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertCreated()
                 ->assertJson([
                     'ok' => true,
                     'message' => 'Socio asignado correctamente',
                     'data' => [
                         'profesor_id' => $this->profesor->id,
                         'socio_id' => $this->socio1->id,
                     ],
                 ]);

        // Verificar que se creó el registro en la tabla pivot
        $this->assertTrue(
            $this->profesor->sociosAsignados()->where('socio_id', $this->socio1->id)->exists()
        );
    }

    public function test_profesor_no_puede_asignarse_usuario_local()
    {
        $usuarioLocal = User::factory()->create([
            'user_type' => UserType::LOCAL,
        ]);

        $response = $this->actingAs($this->profesor)
            ->postJson("/api/profesor/socios/{$usuarioLocal->id}");

        $response->assertUnprocessable();
    }

    public function test_profesor_no_puede_asignarse_socio_duplicado()
    {
        $this->profesor->sociosAsignados()->attach($this->socio1->id);

        $response = $this->actingAs($this->profesor)
            ->postJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertUnprocessable()
                 ->assertJson([
                     'ok' => false,
                     'message' => 'El socio ya está asignado a este profesor',
                 ]);
    }

    public function test_no_profesor_no_puede_asignarse_socio()
    {
        $response = $this->actingAs($this->noProfesor)
            ->postJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertForbidden();
    }

    /**
     * Prueba: DELETE /api/profesor/socios/{socioId}
     */
    public function test_profesor_puede_desasignarse_socio()
    {
        $this->profesor->sociosAsignados()->attach($this->socio1->id);

        $response = $this->actingAs($this->profesor)
            ->deleteJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertOk()
                 ->assertJson([
                     'ok' => true,
                     'message' => 'Socio desasignado correctamente',
                     'data' => [
                         'profesor_id' => $this->profesor->id,
                         'socio_id' => $this->socio1->id,
                     ],
                 ]);

        // Verificar que se eliminó el registro en la tabla pivot
        $this->assertFalse(
            $this->profesor->sociosAsignados()->where('socio_id', $this->socio1->id)->exists()
        );
    }

    public function test_profesor_no_puede_desasignarse_socio_no_asignado()
    {
        $response = $this->actingAs($this->profesor)
            ->deleteJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertNotFound();
    }

    public function test_no_profesor_no_puede_desasignarse_socio()
    {
        $this->profesor->sociosAsignados()->attach($this->socio1->id);

        $response = $this->actingAs($this->noProfesor)
            ->deleteJson("/api/profesor/socios/{$this->socio1->id}");

        $response->assertForbidden();
    }

    /**
     * Prueba: Integración completa
     */
    public function test_flujo_completo_asignacion_socios()
    {
        // 1. Profesor ve que no tiene socios
        $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios')
            ->assertJson(['ok' => true, 'data' => ['data' => []]]);

        // 2. Profesor ve todos los socios disponibles
        $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios/disponibles')
            ->assertJsonCount(3, 'data.data');

        // 3. Profesor se asigna socio1
        $this->actingAs($this->profesor)
            ->postJson("/api/profesor/socios/{$this->socio1->id}")
            ->assertCreated();

        // 4. Profesor ve que tiene 1 socio asignado
        $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios')
            ->assertJsonCount(1, 'data.data');

        // 5. Profesor ve que quedan 2 socios disponibles
        $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios/disponibles')
            ->assertJsonCount(2, 'data.data');

        // 6. Profesor se desasigna el socio
        $this->actingAs($this->profesor)
            ->deleteJson("/api/profesor/socios/{$this->socio1->id}")
            ->assertOk();

        // 7. Profesor vuelve a tener 0 socios asignados
        $this->actingAs($this->profesor)
            ->getJson('/api/profesor/socios')
            ->assertJson(['ok' => true, 'data' => ['data' => []]]);
    }
}
