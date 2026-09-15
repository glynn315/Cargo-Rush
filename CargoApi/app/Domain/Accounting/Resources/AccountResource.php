<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Resources;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One line of the chart of accounts.
 *
 * `type_label` and `normal_balance` are derived and sent anyway, for the reason
 * DESIGN.md section 7.1 gives about colours: the client should not be the
 * second place that knows an expense grows on the debit side. A form that reads
 * `normal_balance` can put the cursor in the right column without a copy of
 * the accounting rules in TypeScript.
 *
 * @mixin Account
 */
class AccountResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            /** The picker's label: `1010 · Cash on hand`. */
            'label' => $this->label(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            /** Which side increases it — `debit` or `credit`. */
            'normal_balance' => $this->normalBalance()->value,
            /** True for the balance-sheet three, false for income and expense. */
            'permanent' => $this->type->isPermanent(),
            'group' => $this->group,
            'description' => $this->description,
            /**
             * Seeded, and therefore not deletable.
             *
             * The clients grey the delete action rather than offering one that
             * comes back with a sentence about why not.
             */
            'is_system' => (bool) $this->is_system,
            'position' => $this->position,
            'status' => $this->status->value,

            ...$this->stamps(),
        ];
    }
}
