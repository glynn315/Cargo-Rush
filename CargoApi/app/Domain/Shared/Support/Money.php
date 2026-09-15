<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

/**
 * The one thing this system does with money that is not arithmetic: writing it
 * out in words.
 *
 * Every figure in this codebase is integer centavos and stays that way — the
 * API never formats a peso, and the clients decide how to print one (DESIGN.md
 * section 7.1). This is the exception, and it is not formatting: an amount in
 * words is part of the *document*. "Twenty-four thousand pesos only" cannot be
 * turned into ₱124,000 with a pen, which is why printed invoices, cheques and
 * receipts have carried one for a century, and why an invoice without it looks
 * to an accounts department like something somebody typed rather than something
 * a business issued.
 *
 * `NumberFormatter::SPELLOUT` does the hard part where the intl extension is
 * present, which is where this runs. The fallback below is not a nicety either:
 * an invoice that printed an empty line, or the word "false", would be worse
 * than one printing the figure twice — so the fallback says the amount in
 * digits rather than nothing at all.
 */
final class Money
{
    /**
     * The amount, written out, ready to print on a document.
     *
     *     Money::inWords(2400000) === 'Twenty-four thousand pesos only'
     *     Money::inWords(2400050) === 'Twenty-four thousand pesos and 50/100'
     *
     * Centavos are given as a fraction rather than in words, which is what
     * Philippine invoices and cheques do: "and 50/100" is read at a glance and
     * "and fifty centavos" is a second clause to parse. A round amount ends
     * "only", which is the convention that says nothing has been cut off the
     * end of the line.
     */
    public static function inWords(int $cents, string $currency = 'PHP'): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        $units = intdiv($cents, 100);
        $fraction = $cents % 100;

        $words = self::spell($units);
        $unitName = self::unitName($currency, $units);

        $amount = sprintf(
            '%s %s %s',
            ucfirst($words),
            $unitName,
            $fraction === 0 ? 'only' : sprintf('and %02d/100', $fraction),
        );

        // A credit note, or a payable read the other way. Said in words too,
        // because a minus sign in front of a written amount is exactly the
        // pen-stroke ambiguity the words are there to remove.
        return $negative ? 'Minus '.lcfirst($amount) : $amount;
    }

    /**
     * The whole-number part, spelled out.
     *
     * British-style "and" is stripped — "one hundred and twenty" becomes "one
     * hundred twenty", which is how a Philippine invoice reads and how the
     * amount is dictated over a phone. Hyphens are kept: "twenty-four" is one
     * word to a reader and two to a machine, and the hyphen is what makes it
     * unambiguous on paper.
     */
    private static function spell(int $units): string
    {
        if (! class_exists(\NumberFormatter::class)) {
            // No intl. Print the figure rather than a blank line — see the
            // note at the top of this class.
            return number_format($units);
        }

        $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
        $words = $formatter->format($units);

        if ($words === false) {
            return number_format($units);
        }

        return trim(str_replace([' and ', '  '], [' ', ' '], $words));
    }

    /**
     * What to call the currency in words.
     *
     * Only the currency this system bills in is spelled out; anything else
     * falls back to its code, which is honest — "24,000 USD only" is readable,
     * and inventing an English plural for every ISO code is a table nobody
     * would maintain.
     */
    private static function unitName(string $currency, int $units): string
    {
        return match (strtoupper($currency)) {
            'PHP' => $units === 1 ? 'peso' : 'pesos',
            default => strtoupper($currency),
        };
    }
}
