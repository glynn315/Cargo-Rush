<?php

declare(strict_types=1);

namespace App\Domain\Identity\Resources;

use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Position
 */
class PositionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            // A default, not a rule — the account still names its own role.
            'default_role_id' => $this->default_role_id,
            'default_role_key' => $this->defaultRole?->key,
            'default_role_name' => $this->defaultRole?->name,
            /**
             * Whether registering somebody into this job also asks for a
             * licence.
             *
             * Sent as a flag rather than left for the client to work out from
             * `default_role_key`, so the rule is stated once — server-side,
             * where the validation that enforces it also lives. Two clients
             * each deciding what counts as a driving job is two places for the
             * answer to drift from the one the API will accept.
             */
            'drives' => $this->drives(),
            'position' => $this->position,
            'status' => $this->status->value,
            'employee_count' => $this->whenCounted('employees'),

            ...$this->stamps(),
        ];
    }
}
