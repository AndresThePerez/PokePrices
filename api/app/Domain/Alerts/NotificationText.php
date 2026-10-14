<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use stdClass;

/**
 * The plain text of an alert and of the daily digest (plan P5-i1 D4, D11; T06), one renderer for email and Discord.
 *
 * Every price comes verbatim from one price_events row (the database's decimal text, grouped with ',' between
 * thousands and never re-rounded) and carries its source and close day: "as of <day> from TCGCSV (daily close)".
 * Texts show dates only, never a time of day. The card label is the PokéSearch catalog name, else "Card <card_id>";
 * the variant label is the web's variantLabel rule. The movement rule's figures (5 % and $1.00) come from
 * config('pricing.movement'), never from a literal.
 */
final class NotificationText
{
    public const string FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

    /**
     * The alert for one event at the person's threshold.
     *
     * @param  stdClass  $event  a price_events row selected with PriceMovedPayload::COLUMNS
     * @param  array{name: string, set_name: string, number: string}|null  $card
     * @return array{subject: string, text: string}
     */
    public static function alert(stdClass $event, ?array $card, int $threshold): array
    {
        $day = (string) $event->day;
        $label = self::cardLabel($event, $card);
        $pct = (string) $event->change_pct;
        $sign = str_starts_with($pct, '-') ? '-' : '+';
        $digits = (string) preg_replace('/^[-+]/', '', $pct);

        return [
            'subject' => "PokéPrices alert: {$label} moved {$sign}{$digits}% ({$day})",
            'text' => implode("\n", [
                $label.' · '.VariantLabel::of((string) $event->variant_key),
                'Market price $'.DiscordMessage::grouped((string) $event->close),
                DiscordMessage::changeLine((string) $event->change, $pct, (string) $event->previous_day),
                self::asOf($day),
                "Your alert threshold: {$threshold}% (and at least \$".self::ruleAbs().').',
                self::url('/card/'.rawurlencode((string) $event->card_id)),
            ]),
        ];
    }

    /**
     * The digest of one close day: the moves ordered by the size of the change (largest first, then variant key),
     * at most $maxLines of them, then a pointer to the watchlist for the rest, then the price footnote.
     *
     * @param  list<array{event: stdClass, card: array{name: string, set_name: string, number: string}|null}>  $moves
     * @return array{subject: string, text: string}
     */
    public static function digest(string $day, array $moves, int $maxLines): array
    {
        $ordered = self::ordered($moves);
        $shown = array_slice($ordered, 0, max(0, $maxLines));
        $lines = ['Cards on your watchlist and portfolio that moved by at least '.self::rulePct().'% and $'.self::ruleAbs()
            .' between two daily closes, '.self::asOf($day).':'];
        foreach ($shown as $move) {
            $lines[] = DiscordMessage::moveLine($move['event'], $move['card']);
        }
        $left = count($ordered) - count($shown);
        if ($left > 0) {
            $lines[] = "…and {$left} more: ".self::url('/watchlist');
        }
        $lines[] = self::FOOTNOTE;

        return ['subject' => "PokéPrices daily digest: closes of {$day}", 'text' => implode("\n", $lines)];
    }

    /**
     * The digest's text for Discord: as many move lines as fit in Discord's 2000 characters (at most the configured
     * number), the rest counted in the "…and <k> more" line, the footnote kept.
     *
     * @param  list<array{event: stdClass, card: array{name: string, set_name: string, number: string}|null}>  $moves
     */
    public static function discordDigest(string $day, array $moves): string
    {
        for ($lines = (int) config('alerts.digest.max_lines', 25); $lines > 0; $lines--) {
            $text = self::digest($day, $moves, $lines)['text'];
            if (mb_strlen($text) <= DiscordMessage::MAX_CHARACTERS) {
                return $text;
            }
        }

        return DiscordMessage::content(self::digest($day, $moves, 0)['text']);
    }

    /**
     * The stored body of an email delivery: {"subject", "text"} (the address is read at send time, never stored).
     *
     * @param  array{subject: string, text: string}  $message
     */
    public static function emailBody(array $message): string
    {
        return json_encode(['subject' => $message['subject'], 'text' => $message['text']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param  array{name: string, set_name: string, number: string}|null  $card */
    public static function cardLabel(stdClass $event, ?array $card): string
    {
        return $card === null ? 'Card '.(string) $event->card_id : $card['name'];
    }

    private static function asOf(string $day): string
    {
        return "as of {$day} from TCGCSV (daily close)";
    }

    /** The movement rule's percentage without trailing zeros: '5.00' gives '5', '7.50' gives '7.5'. */
    private static function rulePct(): string
    {
        $pct = (string) config('pricing.movement.pct');

        return str_contains($pct, '.') ? rtrim(rtrim($pct, '0'), '.') : $pct;
    }

    /** The movement rule's amount as money: '1.00' gives '1.00'. */
    private static function ruleAbs(): string
    {
        return DiscordMessage::grouped((string) config('pricing.movement.abs'));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * @param  list<array{event: stdClass, card: array{name: string, set_name: string, number: string}|null}>  $moves
     * @return list<array{event: stdClass, card: array{name: string, set_name: string, number: string}|null}>
     */
    private static function ordered(array $moves): array
    {
        usort($moves, function (array $a, array $b): int {
            return self::compareSize((string) $b['event']->change_pct, (string) $a['event']->change_pct)
                ?: strcmp((string) $a['event']->variant_key, (string) $b['event']->variant_key)
                ?: ((int) $a['event']->id <=> (int) $b['event']->id);
        });

        return $moves;
    }

    /** Compares the absolute values of two decimal strings digit by digit (never as floats). */
    private static function compareSize(string $a, string $b): int
    {
        [$aWhole, $aFraction] = self::digits($a);
        [$bWhole, $bFraction] = self::digits($b);
        $width = max(strlen($aFraction), strlen($bFraction));

        return (strlen($aWhole) <=> strlen($bWhole))
            ?: (strcmp($aWhole, $bWhole) <=> 0)
            ?: (strcmp(str_pad($aFraction, $width, '0'), str_pad($bFraction, $width, '0')) <=> 0);
    }

    /** @return array{0: string, 1: string} the whole digits without leading zeros and the fraction digits */
    private static function digits(string $amount): array
    {
        $parts = explode('.', (string) preg_replace('/^[-+]/', '', $amount), 2);

        return [ltrim($parts[0], '0'), $parts[1] ?? ''];
    }
}
