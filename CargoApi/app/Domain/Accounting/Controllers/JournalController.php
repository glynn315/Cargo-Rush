<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Requests\JournalEntryRequest;
use App\Domain\Accounting\Requests\VoidJournalEntryRequest;
use App\Domain\Accounting\Resources\JournalEntryResource;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The General Journal — DESIGN.md section 5.1's Finance group, the book the
 * others roll up from.
 *
 * Six endpoints, and the shape of the list is the point: an entry is created,
 * edited while it is a draft, posted once, and voided if it has to be
 * withdrawn. There is no PUT that quietly rewrites a posted entry, because a
 * journal that can be edited after the fact is not a journal. `JournalService`
 * holds those rules; this only translates them into HTTP.
 *
 * ## Posting is a verb, not a status PATCH
 *
 * `POST journal/{entry}/post` rather than `PATCH {status: "posted"}`, for the
 * same reason confirming a trip is a verb: posting stamps who did it and when,
 * checks the entry balances first, and tells the office. A status field that
 * did three things when set to one particular value would hide all three.
 *
 * Voiding is a verb for a stronger reason — it takes a reason with it, and a
 * withdrawal nobody explained is the thing whoever reads the books next has to
 * ring somebody about.
 */
class JournalController extends ApiController
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * The journal, newest first.
     *
     * Filterable by date, category, status and account — the four questions
     * somebody opens a journal with. The account filter reaches through to the
     * lines (see `JournalRepository`), which is how "everything that touched
     * 5300" is asked from this side.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            ...$this->filters($request),
            ...array_filter([
                'category' => $request->query('category'),
                'account_id' => $request->query('account_id'),
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];

        $entries = $this->journal->paginate($filters, $this->perPage($request));

        return $this->collection(JournalEntryResource::collection($entries), $entries);
    }

    /**
     * The categories a form has to offer, from the enum.
     *
     * Served rather than hardcoded in two clients: the list is the API's, and a
     * client with its own copy would offer a category the validator refuses the
     * first time one is added.
     */
    public function categories(): JsonResponse
    {
        return $this->payload(array_map(static fn (JournalCategory $category): array => [
            'value' => $category->value,
            'label' => $category->label(),
            'icon' => $category->icon(),
        ], JournalCategory::cases()));
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource($entry->load(['lines.account', 'postedBy'])));
    }

    /**
     * Write an entry.
     *
     * A draft by default and posted when the payload says so, because both are
     * real: an accountant part-way through a compound entry saves a draft, and
     * one keying yesterday's diesel posts it and moves on.
     */
    public function store(JournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journal->create($request->toData(), $this->user($request));

        return $this->item(new JournalEntryResource($entry), status: 201);
    }

    /** Edit a draft. Refused, with a sentence, for anything posted. */
    public function update(JournalEntryRequest $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->update($entry, $request->toData())
        ));
    }

    /** Put a draft in the books. */
    public function post(Request $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->post($entry, $this->user($request))
        ));
    }

    /**
     * Withdraw a posted entry.
     *
     * The row and its lines stay exactly as they were and stop counting, so the
     * books show both that it was posted and that it was taken back.
     */
    public function void(VoidJournalEntryRequest $request, JournalEntry $entry): JsonResponse
    {
        return $this->item(new JournalEntryResource(
            $this->journal->void($entry, $request->reason(), $this->user($request))
        ));
    }

    /** Delete a draft. A posted entry is voided instead. */
    public function destroy(JournalEntry $entry): JsonResponse
    {
        $this->journal->delete($entry);

        return $this->noContent();
    }

    /** Who is posting. Stamped on the entry, never taken from a payload. */
    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
