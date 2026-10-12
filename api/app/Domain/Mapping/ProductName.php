<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use Normalizer;

/**
 * The tag grammar of a TCGplayer product name (plan T38 items 1–2, adversary rec 2). Every `(…)` and `[…]` tag is
 * removed and classified, then a trailing ` - NNN/NNN` number is stripped from what is left, which is the base name.
 *
 * Classes: `deck` (the trainer-kit decks), `delta`, `treatment` (ball and energy patterns, Cosmos, Cracked Ice,
 * error, misprint, glossy, stamps), `number_echo`, `neutral` and `unknown`. A product with an unknown tag has no
 * treatment and no finish: it goes to review, never to treatment `-`.
 */
final readonly class ProductName
{
    public const string DECK = 'deck';

    public const string DELTA = 'delta';

    public const string TREATMENT = 'treatment';

    public const string NUMBER_ECHO = 'number_echo';

    public const string NEUTRAL = 'neutral';

    public const string UNKNOWN = 'unknown';

    /** The four EX trainer-kit decks; the deck tag must equal the kit's deck (tk1a/tk1b/tk2a/tk2b). */
    private const array DECKS = ['latias' => 'Latias', 'latios' => 'Latios', 'plusle' => 'Plusle', 'minun' => 'Minun'];

    /** Pattern treatments: the finish is reverse whatever subTypeName says (F-P1-013). */
    private const array PATTERNS = ['pokeball', 'masterball', 'friendball', 'loveball', 'quickball', 'duskball', 'energy'];

    private const array TREATMENTS = [
        'energy symbol pattern' => 'energy',
        'cosmos holo' => 'cosmos',
        'cosmo holo' => 'cosmos',
        'cosmo holofoil' => 'cosmos',
        'cosmos holofoil' => 'cosmos',
        'cracked ice' => 'cracked-ice',
        'cracked ice holo' => 'cracked-ice',
        'black dot error' => 'error-black-dot',
        'misprint' => 'misprint',
        'glossy' => 'glossy',
        'glossy finish' => 'glossy',
        // OQ6 default: Base Set Machamp 107004 in group 1840 is edition 1st with treatment shadowless.
        'base set shadowless' => 'shadowless',
    ];

    /** Stamp tags; a product's stamp treatment is `stamp-` plus all of its stamp tags in order, joined with `-`. */
    private const array STAMPS = ['prerelease', 'staff', 'winner', 'e-league', 'pokemon center ny', 'jr east stamp rally'];

    private const array NEUTRALS = ['special', 'full art', 'non-holo'];

    /** The Pokémon a Ditto printing is disguised as (EX Delta Species); a look-alike tag, not a deck. */
    private const array DITTO_LOOKALIKES = ['bulbasaur', 'charmander', 'geodude', 'mr. mime', 'pikachu', 'squirtle'];

    private const string BALL = '/\A(poke|master|friend|love|quick|dusk) ball(?: pattern)?\z/';

    private const string DELTA_TAG = '/\Adelta species(?: - [a-z]+ forme)?\z/';

    private const string FORME = '/\A(?:attack|defense|normal|speed) forme\z/';

    private const string ECHO = '/\A(?:\d+|[A-Z!?])\z/';

    private const string TRAILING_NUMBER = '#\s+-\s+[A-Za-z]*\d+[A-Za-z]?(?:/[A-Za-z]*\d+)?\z#';

    /** @param list<array{tag: string, class: string, value: string|null}> $tags */
    private function __construct(public string $base, public array $tags) {}

    public static function parse(string $name): self
    {
        [$rest, $raw] = self::split($name);
        $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));
        $base = trim((string) preg_replace(self::TRAILING_NUMBER, '', $rest));

        $tags = [];
        foreach ($raw as [$tag, $malformed]) {
            [$class, $value] = $malformed ? [self::UNKNOWN, null] : self::classify($tag, $base);
            $tags[] = ['tag' => $tag, 'class' => $class, 'value' => $value];
        }

        return new self($base, $tags);
    }

    /**
     * Splits a name into the text outside brackets and its bracketed regions, scanning left to right. A balanced,
     * non-nested `(…)` or `[…]` is one tag. A nested or mismatched region, an opener that is never closed (the region
     * runs to the end of the name) and a stray closer are malformed: they leave the base and become unknown tags, so
     * no bracket text survives in the base and no partial tag set yields a treatment.
     *
     * @return array{string, list<array{string, bool}>} [text outside brackets, list of [tag text, malformed]]
     */
    private static function split(string $name): array
    {
        $out = '';
        $regions = [];
        $length = strlen($name);
        $i = 0;
        while ($i < $length) {
            $c = $name[$i];
            if ($c === ')' || $c === ']') {
                $regions[] = [$c, true];
                $out .= ' ';
                $i++;

                continue;
            }
            if ($c !== '(' && $c !== '[') {
                $out .= $c;
                $i++;

                continue;
            }

            $stack = [$c];
            $malformed = false;
            $j = $i + 1;
            while ($j < $length && $stack !== []) {
                $d = $name[$j];
                if ($d === '(' || $d === '[') {
                    $stack[] = $d;
                    $malformed = true;
                } elseif ($d === ')' || $d === ']') {
                    $open = array_pop($stack);
                    if (($open === '(') !== ($d === ')')) {
                        $malformed = true;
                    }
                }
                $j++;
            }
            $out .= ' ';
            if ($stack !== []) {
                $regions[] = [trim(substr($name, $i + 1)), true];

                break;
            }
            $regions[] = [trim(substr($name, $i + 1, $j - $i - 2)), $malformed];
            $i = $j;
        }

        return [$out, $regions];
    }

    /** The trainer-kit deck named by a deck tag (Latias, Latios, Plusle or Minun), or null. */
    public function deck(): ?string
    {
        foreach ($this->tags as $tag) {
            if ($tag['class'] === self::DECK) {
                return $tag['value'];
            }
        }

        return null;
    }

    public function isDelta(): bool
    {
        return in_array(self::DELTA, array_column($this->tags, 'class'), true);
    }

    public function hasUnknownTag(): bool
    {
        return in_array(self::UNKNOWN, array_column($this->tags, 'class'), true);
    }

    /**
     * The card_variants treatment: `-` without treatment tags, the slugs joined with `-` otherwise (stamps as one
     * `stamp-…` slug), and null when any tag is unknown.
     */
    public function treatment(): ?string
    {
        if ($this->hasUnknownTag()) {
            return null;
        }

        $parts = [];
        $stamps = [];
        foreach ($this->tags as $tag) {
            if ($tag['class'] !== self::TREATMENT || $tag['value'] === null) {
                continue;
            }
            if (str_starts_with($tag['value'], 'stamp-')) {
                $stamps[] = substr($tag['value'], strlen('stamp-'));
            } else {
                $parts[] = $tag['value'];
            }
        }
        if ($stamps !== []) {
            $parts[] = 'stamp-'.implode('-', $stamps);
        }

        return $parts === [] ? '-' : implode('-', $parts);
    }

    /**
     * The finish for one price row: reverse for any ball or energy pattern whatever subTypeName says, otherwise the
     * subtype's own finish. Null for an unknown tag or an unknown subtype.
     */
    public function finish(string $subTypeName): ?string
    {
        if ($this->hasUnknownTag()) {
            return null;
        }
        foreach ($this->tags as $tag) {
            if ($tag['class'] === self::TREATMENT && in_array($tag['value'], self::PATTERNS, true)) {
                return 'reverse';
            }
        }

        return match ($subTypeName) {
            'Normal', '1st Edition', 'Unlimited' => 'normal',
            'Holofoil', '1st Edition Holofoil', 'Unlimited Holofoil' => 'holo',
            'Reverse Holofoil' => 'reverse',
            default => null,
        };
    }

    /** @return array{string, string|null} [class, value] */
    private static function classify(string $tag, string $base): array
    {
        $key = self::key($tag);

        return match (true) {
            isset(self::DECKS[$key]) => [self::DECK, self::DECKS[$key]],
            preg_match(self::DELTA_TAG, $key) === 1 => [self::DELTA, null],
            preg_match(self::BALL, $key, $m) === 1 => [self::TREATMENT, $m[1].'ball'],
            isset(self::TREATMENTS[$key]) => [self::TREATMENT, self::TREATMENTS[$key]],
            in_array($key, self::STAMPS, true) => [self::TREATMENT, 'stamp-'.self::slug($key)],
            preg_match(self::ECHO, $tag) === 1 => [self::NUMBER_ECHO, $tag],
            in_array($key, self::NEUTRALS, true),
            preg_match(self::FORME, $key) === 1,
            self::key($base) === 'ditto' && in_array($key, self::DITTO_LOOKALIKES, true) => [self::NEUTRAL, null],
            default => [self::UNKNOWN, null],
        };
    }

    /** Lower case, accents folded (Poké Ball = Poke Ball), single spaces. */
    private static function key(string $text): string
    {
        $folded = Normalizer::normalize($text, Normalizer::FORM_KD);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $folded === false ? $text : $folded);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    }

    private static function slug(string $key): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $key), '-');
    }
}
