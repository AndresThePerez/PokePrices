<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Pricing\PricingConfig;
use stdClass;

/**
 * The Standard Webhooks body of a price.moved message (plan P5-i1 D4, D11). Every value comes from one price_events
 * row verbatim: money and change_pct are the database's numeric(12,2) text, never a float; timestamp and as_of are
 * the event's as_of (the data's own instant, never the send time). card is the PokéSearch catalog name, or null
 * when the catalog read had none. The body is stored and resent byte for byte, so its key order is fixed here.
 */
final class PriceMovedPayload
{
    /**
     * The event columns SELECT must give, as names: id, variant_key, card_id, direction, close, previous_close,
     * change, change_pct, day (YYYY-MM-DD), as_of and previous_as_of (RFC 3339 UTC to the second), previous_day,
     * series_epoch, rule and source. COLUMNS selects them from a price_events alias `e`.
     */
    public const string COLUMNS = <<<'SQL'
        e.id, e.variant_key, e.card_id, e.direction, e.close::text AS close, e.previous_close::text AS previous_close,
        e.change::text AS change, e.change_pct::text AS change_pct, e.day::text AS day,
        to_char(e.as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of, e.previous_day::text AS previous_day,
        to_char(e.previous_as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS previous_as_of,
        e.series_epoch, e.rule, e.source
        SQL;

    /**
     * @param  stdClass  $event  a row selected with COLUMNS
     * @param  array{name: string, set_name: string, number: string}|null  $card
     */
    public static function encode(stdClass $event, ?array $card): string
    {
        return json_encode([
            'type' => 'price.moved',
            'timestamp' => (string) $event->as_of,
            'data' => [
                'event_id' => 'evt_'.(int) $event->id,
                'variant_key' => (string) $event->variant_key,
                'card_id' => (string) $event->card_id,
                'card' => $card === null ? null : ['name' => $card['name'], 'set_name' => $card['set_name'], 'number' => $card['number']],
                'direction' => (string) $event->direction,
                'close' => (string) $event->close,
                'previous_close' => (string) $event->previous_close,
                'change' => (string) $event->change,
                'change_pct' => (string) $event->change_pct,
                'day' => (string) $event->day,
                'as_of' => (string) $event->as_of,
                'previous_day' => (string) $event->previous_day,
                'previous_as_of' => (string) $event->previous_as_of,
                'series_epoch' => (int) $event->series_epoch,
                'rule' => (string) $event->rule,
                ...PricingConfig::provenance((string) $event->source),
                'cadence' => PricingConfig::CADENCE,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
