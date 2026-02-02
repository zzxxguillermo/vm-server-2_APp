<?php

namespace App\Models;

use App\Enums\UserType;
use App\Enums\PromotionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'dni',
        'user_type',
        'promotion_status',
        'promoted_at',
        'nombre',
        'apellido',
        'nacionalidad',
        'nacimiento',
        'domicilio',
        'localidad',
        'telefono',
        'celular',
        'categoria',
        'socio_id',
        'socio_n',
        'barcode',
        'saldo',
        'semaforo',
        'estado_socio',
        'avatar_path',
        'foto_url',
        'api_updated_at',
        // Nuevos campos de la API completa
        'tipo_dni',
        'r1',
        'r2',
        'tutor',
        'observaciones',
        'deuda',
        'descuento',
        'alta',
        'suspendido',
        'facturado',
        'fecha_baja',
        'monto_descuento',
        'update_ts',
        'validmail_st',
        'validmail_ts',
        // Campos de administración
        'is_admin',
        'permissions',
        'admin_notes',
        'account_status',
        'professor_since',
        'student_gym',
        'student_gym_since',
        'session_timeout',
        'allowed_ips',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'user_type' => UserType::class,
        'promotion_status' => PromotionStatus::class,
        'promoted_at' => 'datetime',
        'nacimiento' => 'date',
        'api_updated_at' => 'datetime',
        'alta' => 'date',
        'fecha_baja' => 'date',
        'update_ts' => 'datetime',
        'validmail_ts' => 'datetime',
        'saldo' => 'decimal:2',
        'deuda' => 'decimal:2',
        'descuento' => 'decimal:2',
        'monto_descuento' => 'decimal:2',
        'suspendido' => 'boolean',
        'facturado' => 'boolean',
        'validmail_st' => 'boolean',
        'semaforo' => 'integer',
        // Campos de administración
        'is_admin' => 'boolean',
        'permissions' => 'array',
        'professor_since' => 'datetime',
        'student_gym' => 'boolean',
        'student_gym_since' => 'datetime',
        'allowed_ips' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'foto_url',
        'type_label',
    ];

    /**
     * Scope para usuarios locales
     */
    public function scopeLocal($query)
    {
        return $query->where('user_type', UserType::LOCAL);
    }

    /**
     * Scope para usuarios API
     */
    public function scopeApi($query)
    {
        return $query->where('user_type', UserType::API);
    }

    /**
     * Scope para usuarios que necesitan refresh desde la API
     */
    public function scopeNeedsRefresh($query, int $hours = 24)
    {
        return $query->where('user_type', UserType::API)
                    ->where(function ($q) use ($hours) {
                        $q->whereNull('api_updated_at')
                          ->orWhere('api_updated_at', '<', now()->subHours($hours));
                    });
    }

    /**
     * Scope para usuarios con datos completos
     */
    public function scopeComplete($query)
    {
        return $query->where('user_type', UserType::API)
                    ->whereNotNull('socio_id');
    }

    /**
     * Scope para usuarios elegibles para promoción
     */
    public function scopeEligibleForPromotion($query)
    {
        return $query->where('user_type', UserType::LOCAL)
                    ->where('promotion_status', PromotionStatus::NONE);
    }

    /**
     * Accessor para el nombre de display
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user_type === UserType::API && $this->apellido && $this->nombre
                ? trim("{$this->apellido}, {$this->nombre}")
                : $this->name
        );
    }

    /**
     * Accessor para el nombre completo (API users)
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user_type === UserType::API && $this->apellido && $this->nombre
                ? trim("{$this->apellido}, {$this->nombre}")
                : $this->name
        );
    }

    /**
     * Get the user's avatar URL.
     * Prioriza foto_url (URL directa) sobre avatar_path (almacenamiento local)
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->foto_url 
                ? $this->foto_url
                : ($this->avatar_path ? asset("storage/{$this->avatar_path}") : null),
        );
    }

    /**
     * Accessor para foto_url - prioriza URL directa sobre avatar local
     */
    protected function fotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['foto_url'] 
                ?? ($this->avatar_path ? asset("storage/{$this->avatar_path}") : null)
        );
    }

    /**
     * Accessor para type_label - etiqueta legible del tipo de usuario
     */
    protected function typeLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => match($this->user_type) {
                UserType::LOCAL => 'Usuario Local',
                UserType::API => 'Usuario API',
                default => 'Usuario'
            }
        );
    }

    /**
     * Determina si el usuario puede ser promocionado
     */
    public function canPromote(): bool
    {
        return $this->user_type === UserType::LOCAL && 
               $this->promotion_status === PromotionStatus::NONE;
    }

    /**
     * Determina si el usuario tiene datos completos
     */
    public function isComplete(): bool
    {
        return $this->user_type === UserType::API;
    }

    /**
     * Determina si el usuario es local
     */
    public function isLocal(): bool
    {
        return $this->user_type === UserType::LOCAL;
    }

    /**
     * Determina si el usuario es API
     */
    public function isApi(): bool
    {
        return $this->user_type === UserType::API;
    }

    /**
     * Determina si el usuario necesita refresh desde la API
     */
    public function needsRefresh(int $hours = 24): bool
    {
        return $this->user_type === UserType::API && (
            $this->api_updated_at === null || 
            $this->api_updated_at->diffInHours(now()) > $hours
        );
    }

    /**
     * Marca el usuario como actualizado desde la API
     */
    public function markAsRefreshed(): void
    {
        $this->update(['api_updated_at' => now()]);
    }

    /**
     * Promociona el usuario de local a API
     */
    public function promoteToApi(array $apiData): void
    {
        $this->update([
            'user_type' => UserType::API,
            'promotion_status' => PromotionStatus::APPROVED,
            'promoted_at' => now(),
            ...$apiData
        ]);
    }

    /**
     * Obtiene los campos permitidos para edición según el tipo de usuario
     */
    public function getEditableFields(): array
    {
        return match($this->user_type) {
            UserType::LOCAL => ['name', 'email', 'phone', 'password'],
            UserType::API => ['phone'], // Solo algunos campos para usuarios API
        };
    }

    // ==================== MÉTODOS DE ADMINISTRACIÓN ====================

    /**
     * Scope para administradores
     */
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true);
    }

    /**
     * Scope para profesores
     */
    public function scopeProfessors($query)
    {
        return $query->where('is_professor', true);
    }

    /**
     * Scope para estudiantes de gimnasio
     */
    public function scopeGymStudents($query)
    {
        return $query->where('student_gym', true);
    }

    /**
     * Scope para usuarios activos
     */
    public function scopeActive($query)
    {
        return $query->where('account_status', 'active');
    }

    /**
     * Scope para usuarios suspendidos
     */
    public function scopeSuspended($query)
    {
        return $query->where('account_status', 'suspended');
    }

    /**
     * Determina si el usuario es administrador
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Determina si el usuario es super administrador
     */
    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->hasPermission('super_admin');
    }

    /**
     * Determina si el usuario tiene un permiso específico
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Determina si el usuario puede gestionar otros usuarios
     */
    public function canManageUsers(): bool
    {
        return $this->isAdmin() || $this->hasPermission('user_management');
    }

    /**
     * Determina si el usuario puede gestionar el gimnasio
     */
    public function canManageGym(): bool
    {
        return $this->is_professor || $this->hasPermission('gym_admin');
    }

    /**
     * Determina si el usuario puede ver reportes
     */
    public function canViewReports(): bool
    {
        return $this->isAdmin() || $this->hasPermission('reports_access');
    }

    /**
     * Determina si el usuario puede ver logs de auditoría
     */
    public function canViewAuditLogs(): bool
    {
        return $this->isAdmin() || $this->hasPermission('audit_logs');
    }

    /**
     * Asigna rol de administrador
     */
    public function assignAdminRole(array $permissions = []): void
    {
        $this->update([
            'is_admin' => true,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Remueve rol de administrador
     */
    public function removeAdminRole(): void
    {
        $this->update([
            'is_admin' => false,
            'permissions' => null,
        ]);
    }

    /**
     * Asigna rol de profesor
     */
    public function assignProfessorRole(array $qualifications = []): void
    {
        $this->update([
            'is_professor' => true,
            'professor_since' => now(),
            'admin_notes' => $qualifications['notes'] ?? null,
        ]);
    }

    /**
     * Remueve rol de profesor
     */
    public function removeProfessorRole(): void
    {
        $this->update([
            'is_professor' => false,
            'professor_since' => null,
        ]);
    }

    /**
     * Asigna acceso a gimnasio
     */
    public function grantGymAccess(): void
    {
        $this->update([
            'student_gym' => true,
            'student_gym_since' => now(),
        ]);
    }

    /**
     * Remueve acceso a gimnasio
     */
    public function revokeGymAccess(): void
    {
        $this->update([
            'student_gym' => false,
            'student_gym_since' => null,
        ]);
    }

    /**
     * Verifica si el usuario tiene acceso a gimnasio
     */
    public function hasGymAccess(): bool
    {
        return $this->student_gym === true;
    }

    /**
     * Suspende la cuenta del usuario
     */
    public function suspend(string $reason = null): void
    {
        $this->update([
            'account_status' => 'suspended',
            'admin_notes' => $reason ? "Suspendido: {$reason}" : 'Cuenta suspendida',
        ]);
    }

    /**
     * Activa la cuenta del usuario
     */
    public function activate(): void
    {
        $this->update([
            'account_status' => 'active',
        ]);
    }

    /**
     * Obtiene estadísticas del profesor (si es profesor)
     */
    public function getProfessorStats(): array
    {
        if (!$this->is_professor) {
            return [];
        }

        return [
            'students_count' => \App\Models\Gym\WeeklyAssignment::where('created_by', $this->id)
                ->distinct('user_id')->count('user_id'),
            'templates_created' => \App\Models\Gym\DailyTemplate::where('created_by', $this->id)->count(),
            'active_assignments' => \App\Models\Gym\WeeklyAssignment::where('created_by', $this->id)
                ->where('week_end', '>=', now())->count(),
            'total_assignments' => \App\Models\Gym\WeeklyAssignment::where('created_by', $this->id)->count(),
        ];
    }

    /**
     * Obtiene el historial de actividad reciente
     */
    public function getRecentActivity(int $limit = 10): array
    {
        return \App\Models\AuditLog::where('user_id', $this->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    // ==================== RELACIONES ====================

    /**
     * Asignaciones semanales creadas por este usuario (si es profesor)
     */
    public function createdAssignments()
    {
        return $this->hasMany(\App\Models\Gym\WeeklyAssignment::class, 'created_by');
    }

    /**
     * Plantillas diarias creadas por este usuario (si es profesor)
     */
    public function createdDailyTemplates()
    {
        return $this->hasMany(\App\Models\Gym\DailyTemplate::class, 'created_by');
    }

    /**
     * Plantillas semanales creadas por este usuario (si es profesor)
     */
    public function createdWeeklyTemplates()
    {
        return $this->hasMany(\App\Models\Gym\WeeklyTemplate::class, 'created_by');
    }

    /**
     * Asignaciones semanales recibidas por este usuario (si es estudiante)
     */
    public function receivedAssignments()
    {
        return $this->hasMany(\App\Models\Gym\WeeklyAssignment::class, 'user_id');
    }

    /**
     * Logs de auditoría de este usuario
     */
    public function auditLogs()
    {
        return $this->hasMany(\App\Models\AuditLog::class);
    }

    /**
     * Asignaciones profesor-estudiante cuando el usuario es profesor
     */
    public function professorAssignments()
    {
        return $this->hasMany(\App\Models\Gym\ProfessorStudentAssignment::class, 'professor_id');
    }

    /**
     * Asignaciones profesor-estudiante cuando el usuario es estudiante
     */
    public function studentAssignments()
    {
        return $this->hasMany(\App\Models\Gym\ProfessorStudentAssignment::class, 'student_id');
    }

    /**
     * Estudiantes asignados a este profesor (relación through)
     */
    public function assignedStudents()
    {
        return $this->hasManyThrough(
            User::class,
            \App\Models\Gym\ProfessorStudentAssignment::class,
            'professor_id',  // FK en professor_student_assignments
            'id',            // FK en users
            'id',            // Local key en users (profesor)
            'student_id'     // Local key en professor_student_assignments
        )->where('professor_student_assignments.status', 'active');
    }
        /**
     * Socios (usuarios API) asignados a este profesor
     * Pivot: professor_socio (professor_id, socio_id, assigned_by)
     */
    public function sociosAsignados()
    {
        return $this->belongsToMany(
            User::class,
            'professor_socio',
            'professor_id',
            'socio_id'
        )->withTimestamps()
         ->withPivot(['assigned_by']);
    }

    /**
     * Alias para compatibilidad
     */
    public function assignedSocios()
    {
        return $this->sociosAsignados();
    }

    /**
     * Profesores asignados a este socio (usuario API)
     * Pivot: professor_socio (professor_id, socio_id, assigned_by)
     */
    public function profesoresAsignados()
    {
        return $this->belongsToMany(
            User::class,
            'professor_socio',
            'socio_id',
            'professor_id'
        )->withTimestamps()
         ->withPivot(['assigned_by']);
    }

    /**
     * Alias para compatibilidad
     */
    public function assignedProfessors()
    {
        return $this->profesoresAsignados();
    }

}
