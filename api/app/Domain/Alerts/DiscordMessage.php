<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use stdClass;

/**
 * Discord as a notification channel (plan P5-i1 D4, D6, D13; T03). A Discord webhook receives plain text in the
 * envelope {"content", "username", "allowed_mentions"} (mentions off), with no signature headers. Content over
 * Discord's 2000 characters is cut on a line boundary and ends with a "…and <k> more" line. A Discord webhook URL is
 * https on one of the four Discord hosts with the path /api/webhooks/<id>/<token> and nothing else; it carries a
 * token, so it is stored encrypted and never shown, logged or echoed.
 *
 * moveLine() renders one move of a price_events row as the digest does (D4), so the live switch's test message
 * (pokeprices:alerts:discord-test) shows real closes with their source and day; T06's renderer shares the rule.
 */
final class DiscordMessage
{
    public const int MAX_CHARACTERS = 2000;

    public const string USERNAME = 'PokéPrices';

    public const string TEST_TEXT = 'PokéPrices test message. Your alerts and daily digest arrive here when you choose Discord.';

    /** The four Discord hosts, the webhook path, no port, user-info, query or fragment. */
    private const string URL = '~^https://(?:discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$~D';

    /** The exact JSON body sent to Discord for $text (cut to the limit). */
    public static function envelope(string $text, string $moreSuffix = ''): string
    {
        return json_encode([
            'content' => self::content($text, $moreSuffix),
            'username' => self::USERNAME,
            'allowed_mentions' => ['parse' => []],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * $text when it fits in 2000 characters; otherwise as many whole leading lines as fit beside a last line
     * "…and <k> more<suffix>" (k = the lines left out). A first line too long on its own is shortened with "…".
     */
    public static function content(string $text, string $moreSuffix = ''): string
    {
        if (mb_strlen($text) <= self::MAX_CHARACTERS) {
            return $text;
        }
        $lines = explode("\n", $text);
        $count = count($lines);
        $kept = [];
        $length = 0;
        foreach ($lines as $i => $line) {
            $candidate = $length + ($i > 0 ? 1 : 0) + mb_strlen($line);
            if ($candidate + mb_strlen(self::more($count - $i - 1, $moreSuffix)) > self::MAX_CHARACTERS) {
                break;
            }
            $kept[] = $line;
            $length = $candidate;
        }
        if ($kept === []) {
            $more = $count > 1 ? self::more($count - 1, $moreSuffix) : '';

            return mb_substr($lines[0], 0, self::MAX_CHARACTERS - mb_strlen($more) - 1).'…'.$more;
        }

        return implode("\n", $kept).self::more($count - count($kept), $moreSuffix);
    }

    /** Whether $url is a Discord webhook URL (D6's shape; the address guard still runs on it). */
    public static function isWebhookUrl(#[\SensitiveParameter] string $url): bool
    {
        return preg_match(self::URL, $url) === 1;
    }

    /**
     * One move as a digest line (D4): "<card label> · <variant label>: $<previous close> → $<close>, <change line>".
     * The label (catalog name, else "Card <card_id>") is CatalogName::forEmail's, or forDiscord's when $forDiscord.
     *
     * @param  stdClass  $event  a price_events row selected with PriceMovedPayload::COLUMNS
     * @param  array{name: string, set_name: string, number: string}|null  $card
     */
    public static function moveLine(stdClass $event, ?array $card, bool $forDiscord = false): string
    {
        $name = $card === null ? 'Card '.(string) $event->card_id : $card['name'];
        $label = $forDiscord ? CatalogName::forDiscord($name) : CatalogName::forEmail($name);

        return $label.' · '.self::variantLabel((string) $event->variant_key)
            .': $'.self::grouped((string) $event->previous_close).' → $'.self::grouped((string) $event->close)
            .', '.self::changeLine((string) $event->change, (string) $event->change_pct, (string) $event->previous_day);
    }

    /** The web's variantLabel (web/src/format.ts): finish, edition, treatment without '-' or '', else the key. */
    public static function variantLabel(string $variantKey): string
    {
        return VariantLabel::of($variantKey);
    }

    /** The web's grouped(): an unsigned decimal string with ',' between thousands, digits copied, never parsed. */
    public static function grouped(string $amount): string
    {
        if (preg_match('/^(\d+)(\.\d+)?$/D', $amount, $m) !== 1) {
            return $amount;
        }

        return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $m[1]).($m[2] ?? '');
    }

    /** The web's changeLine() text: "▲ +$<change> (+<pct>%) vs previous close (<day>)", or ▼ and - for a fall. */
    public static function changeLine(string $change, string $changePct, string $previousDay): string
    {
        $versus = "vs previous close ({$previousDay})";
        $negative = str_starts_with($change, '-');
        $digits = (string) preg_replace('/^[-+]/', '', $change);
        if (preg_match('/^0+(\.0+)?$/D', $digits) === 1) {
            return "No change {$versus}";
        }
        $pct = (string) preg_replace('/^[-+]/', '', $changePct);
        $pctSign = str_starts_with($changePct, '-') ? '-' : '+';

        return ($negative ? '▼ -' : '▲ +').'$'.self::grouped($digits)." ({$pctSign}{$pct}%) {$versus}";
    }

    private static function more(int $left, string $suffix): string
    {
        return "\n…and {$left} more{$suffix}";
    }
}
