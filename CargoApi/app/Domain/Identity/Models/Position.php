<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\Role as SystemRole;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title the office keeps a list of.
 *
 * Separate from a role because what somebody *is* and what they can *open* are
 * different questions. Conflating them means you cannot have two drivers where
 * one also keeps the books — and that person exists in every small fleet.
 */
class Position extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'description', 'default_role_id', 'position', 'status',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    /** The access somebody in this job normally gets. A default, not a rule. */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    /**
     * Does somebody in this job need a `drivers` record?
     *
     * Which is the same question as *do they use the handset* — every driver
     * endpoint is scoped to a `drivers` row, so an account with the driver's
     * access and no such row signs in and finds five empty screens. That makes
     * the default role the honest place to read this from rather than a second
     * flag beside it that could disagree with the first.
     *
     * It covers the helper as well as the driver, and it should: a helper is a
     * driver record without the keys (they ride along, they are named on the
     * trip, and the roster keeps their licence), which is why `PositionSeeder`
     * gives both the same default role.
     *
     * A company inventing "Long-haul Driver" gets this for free, because the
     * thing that makes it a driving job is the same thing that gives its people
     * the handset — there is nothing extra to remember to tick.
     */
    public function drives(): bool
    {
        return $this->defaultRole?->key === SystemRole::Driver->value;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
