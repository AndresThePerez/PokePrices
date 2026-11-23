<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingConfig;

// The movers sentence a visitor reads (web/src/pages/MoverList.tsx, and the same sentence in docs/WEB.md) writes its
// thresholds as words; the rule is PricingConfig's six MOVERS_* constants. This test spells the sentence out of the
// constants and demands it appears in both files, and that no other number stands in the sentence, so a constant moved
// without the sentence (or the reverse) fails here. The web has no way to read the PHP constants, so the check lives on
// the PHP side, which can read the web sources as text.

const MOVERS_WORDS = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];

/** A ratio of the low listing as the sentence says it; a ratio this test cannot phrase fails, so it must be taught. */
function moversLowRatioPhrase(string $ratio): string
{
    $known = ['0.5' => 'half', '0.25' => 'a quarter', '0.75' => 'three quarters'];
    // A plain lookup: toHaveKey would read the dot of "0.5" as a path.
    expect(array_key_exists($ratio, $known))->toBeTrue("the low-listing ratio {$ratio} has no wording in this test: teach it the phrase the sentence now uses");

    return $known[$ratio];
}

/** The sentence the constants imply, with the previous-close floor left as a placeholder. */
function moversSentenceFromConstants(): string
{
    $pct = rtrim(rtrim(PricingConfig::MOVERS_MIN_CHANGE_PCT, '0'), '.');
    $distinct = MOVERS_WORDS[PricingConfig::MOVERS_ACTIVITY_MIN_DISTINCT] ?? null;
    $closes = MOVERS_WORDS[PricingConfig::MOVERS_ACTIVITY_CLOSES] ?? null;
    expect($distinct)->not->toBeNull()->and($closes)->not->toBeNull();

    return 'that moved at least '.$pct.'% and at least $'.PricingConfig::MOVERS_MIN_CHANGE
        .', had '.$distinct.' different closes among their last '.$closes
        .', and whose two closes each sat between '.moversLowRatioPhrase(PricingConfig::MOVERS_LISTING_LOW_RATIO)
        .' the lowest listing and '.PricingConfig::MOVERS_LISTING_MID_RATIO.' times the mid listing of their own day.';
}

function moversRepoFile(string $relative): string
{
    $text = file_get_contents(dirname(__DIR__, 3).'/'.$relative);
    expect($text)->not->toBeFalse();

    return (string) preg_replace('/\s+/', ' ', (string) $text);
}

test('the movers sentence in MoverList.tsx states every MOVERS_* threshold of PricingConfig and no other number', function (): void {
    $tsx = moversRepoFile('web/src/pages/MoverList.tsx');
    $implied = moversSentenceFromConstants();

    expect($tsx)->toContain($implied);

    // Everything the sentence says after the previous-close floor, minus the figures the constants give, holds no digit.
    preg_match('/Ranked by change vs previous close among variants whose previous close was at least \$\$\{[^}]*\}, (.*?)`/', $tsx, $found);
    expect($found)->toHaveCount(2);
    $rest = str_replace(['at least '.rtrim(rtrim(PricingConfig::MOVERS_MIN_CHANGE_PCT, '0'), '.').'%', '$'.PricingConfig::MOVERS_MIN_CHANGE,
        PricingConfig::MOVERS_LISTING_MID_RATIO.' times'], '', $found[1]);
    expect($rest)->not->toMatch('/\d/');
});

test('docs/WEB.md says the same movers sentence as the page', function (): void {
    $docs = moversRepoFile('docs/WEB.md');

    expect($docs)->toContain(moversSentenceFromConstants());
});

test('the sentence this test spells from the constants is one a visitor can read, not a placeholder', function (): void {
    $sentence = moversSentenceFromConstants();

    expect($sentence)->toBe('that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.');
});
