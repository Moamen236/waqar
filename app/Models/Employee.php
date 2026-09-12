<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Employee extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, RecordsActivity, SoftDeletes;

    protected function activityLogName(): string
    {
        return 'access';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'full_name',
            'email',
            'phone',
            'is_active',
            'team_leader_id',
        ];
    }

    /**
     * Roles/permissions resolve against the "employee" guard, never
     * "customer" — customers never carry a role of their own (spec
     * Section 24, schema confirmation #13).
     */
    protected $guard_name = 'employee';

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'password',
        'residence_address',
        'national_id_number',
        'is_active',
        'team_leader_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'national_id_number', // PII — never serialize by default
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The Customer Service Team Leader this employee reports to (spec
     * Section 15, Question 16). Only ever set for the Customer Service
     * role; null for a Team Leader's own record.
     */
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_id');
    }

    /**
     * The agents this employee leads, if they hold the Customer Service
     * Team Leader role.
     */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(Employee::class, 'team_leader_id');
    }

    /**
     * Orders this employee created on a customer's behalf — only
     * non-empty when order_source = customer_service (Section 03).
     */
    public function ordersCreated(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_employee_id');
    }

    /**
     * Question 16's employee-visibility half of the Team Leader
     * data-scoping rule: sees only the agents whose team_leader_id
     * points to them, plus their own record. Every other role is
     * unscoped — mirrors Order::scopeVisibleTo().
     */
    public function scopeVisibleTo(Builder $query, Employee $viewer): Builder
    {
        if (! $viewer->hasRole('Customer Service Team Leader')) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('team_leader_id', $viewer->id)->orWhere('id', $viewer->id));
    }
}
