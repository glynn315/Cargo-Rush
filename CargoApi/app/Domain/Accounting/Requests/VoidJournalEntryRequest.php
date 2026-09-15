<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * Withdrawing a posted entry, and the one thing it asks for.
 *
 * A reason, and it is required. Voiding is the only way the books take
 * something back, so the void *is* the record of the correction — and a
 * correction with no explanation is worse than the mistake it fixes, because
 * whoever reads the ledger next can see that something was withdrawn and has
 * nothing to tell them why.
 *
 * Its own request class rather than a field on the entry form, because it is
 * its own act: nothing else about the entry may change while it is being
 * voided, and a form that accepted a memo alongside would be offering to edit
 * a posted entry through the back door.
 */
class VoidJournalEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the entry is being voided — it stays in the books with the reason on it.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
