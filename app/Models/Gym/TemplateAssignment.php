<?php

namespace App\Models\Gym;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateAssignment extends Model
{
    protected $table = 'daily_assignments';

    protected $fillable = [
        'professor_student_assignment_id',
        'daily_template_id',
        'assigned_by',
        'start_date',
        'end_date',
        'frequency',
        'professor_notes',
        'status'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'frequency' => 'array',
    ];

    // Relaciones
    public function professorStudentAssignment(): BelongsTo
    {
        return $this->belongsTo(ProfessorStudentAssignment::class);
    }

    public function dailyTemplate(): BelongsTo
    {
        return $this->belongsTo(DailyTemplate::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(AssignmentProgress::class, 'daily_assignment_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForStudent($query, $studentId)
    {
        // Filtra por student_id y por el profesor autenticado
        return $query->whereHas('professorStudentAssignment', function($q) use ($studentId) {
            $q->where('student_id', $studentId)
              ->where('professor_id', auth()->id());
        });
    }

    public function scopeForProfessor($query, $professorId)
    {
        return $query->whereHas('professorStudentAssignment', function($q) use ($professorId) {
            $q->where('professor_id', $professorId);
        });
    }

    // Métodos auxiliares
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getFrequencyDaysAttribute(): array
    {
        return $this->frequency ?? [];
    }

    public function getTotalSessionsAttribute(): int
    {
        return $this->progress()->count();
    }

    public function getCompletedSessionsAttribute(): int
    {
        return $this->progress()->where('status', 'completed')->count();
    }

    public function getProgressPercentageAttribute(): float
    {
        $total = $this->getTotalSessionsAttribute();
        if ($total === 0) return 0;
        
        return round(($this->getCompletedSessionsAttribute() / $total) * 100, 1);
    }
}
