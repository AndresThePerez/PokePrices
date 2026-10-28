<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerts\DeliverableDay;
use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Alerts\WebhookEndpoints;
use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\PricingConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * The live Discord switch (plan P5-i1 D13, R230, HG-04). config('alerts.discord.test_webhook_url') comes from
 * DISCORD_TEST_WEBHOOK_URL. Unset or empty: it prints that nothing was sent and exits 1 (the switch is off). Set: it
 * checks the URL as a Discord webhook (D6; on failure the field message, exit 1), then sends one Discord envelope
 * synchronously through the sender's guarded, pinned POST: "PokéPrices test message." and the three largest moves of
 * the deliverable day (D2) as digest lines with their source and day, or a line saying there is none; it prints
 * {"status": <HTTP status or null>, "delivered": <bool>} and exits 0 on a 2xx, else 1. It never prints or logs the
 * URL, and it writes nothing: no delivery, no dispatch_days row (a day not dispatched yet shows all its events).
 */
#[Description('Send one PokéPrices test message to the Discord webhook in DISCORD_TEST_WEBHOOK_URL.')]
#[Signature('pokeprices:alerts:discord-test')]
final class AlertsDiscordTest extends Command
{
    public const string NOT_SET = 'DISCORD_TEST_WEBHOOK_URL is not set; nothing was sent.';

    public function handle(DeliverySender $sender, WebhookEndpoints $endpoints, CatalogRepository $catalog): int
    {
        $url = config('alerts.discord.test_webhook_url');
        if (! is_string($url) || trim($url) === '') {
            $this->line(self::NOT_SET);

            return self::FAILURE;
        }
        $url = trim($url);
        [$error] = $endpoints->check('discord', $url);
        if ($error !== null) {
            $this->line($error);

            return self::FAILURE;
        }

        $body = DiscordMessage::envelope(self::text($catalog, CarbonImmutable::now()));
        try {
            [$status] = $sender->discord($url, $body);
        } catch (Throwable) {
            // Containment (D7, review A08): an exception message can carry the URL, so it is never printed or logged.
            $status = null;
        }
        $delivered = $status !== null && $status >= 200 && $status < 300;
        $this->line(json_encode(['status' => $status, 'delivered' => $delivered], JSON_THROW_ON_ERROR));

        return $delivered ? self::SUCCESS : self::FAILURE;
    }

    /** The message text: the test line, then the deliverable day's three largest moves, or why there is none. */
    private static function text(CatalogRepository $catalog, CarbonImmutable $now): string
    {
        $source = PricingConfig::source();
        $db = DB::connection();
        $terms = DeliverableDay::terms($db, $source, $now);
        $day = $terms['newest'];
        if ($day === null || ! DeliverableDay::allows($day, $terms['floor'], $terms['watermark'])) {
            return "PokéPrices test message.\nNo finalized day yet.";
        }

        // Read only: the day's recorded ceiling when it was dispatched (null: nothing), else every event of the day.
        $recorded = $db->selectOne('SELECT event_id_ceiling FROM dispatch_days WHERE source = ? AND day = ?::date', [$source, $day], false);
        $ceiling = $recorded instanceof stdClass ? ($recorded->event_id_ceiling === null ? 0 : (int) $recorded->event_id_ceiling) : PHP_INT_MAX;
        $events = array_values(array_filter($db->select(
            'SELECT '.PriceMovedPayload::COLUMNS.' FROM price_events e WHERE e.source = ? AND e.day = ?::date AND e.id <= ?::bigint
             ORDER BY abs(e.change_pct) DESC, e.variant_key LIMIT 3',
            [$source, $day, $ceiling],
            false,
        ), fn (mixed $row): bool => $row instanceof stdClass));
        if ($events === []) {
            return "PokéPrices test message.\nNo card crossed the movement rule on {$day}.";
        }

        $ids = array_values(array_unique(array_map(fn (stdClass $e): string => (string) $e->card_id, $events)));
        try {
            $cards = $catalog->cardNames($ids)['cards'];
        } catch (Throwable) {
            $cards = [];
        }
        $lines = ['PokéPrices test message.', "The largest moves between two daily closes, as of {$day} from TCGCSV (daily close):"];
        foreach ($events as $event) {
            $lines[] = DiscordMessage::moveLine($event, $cards[(string) $event->card_id] ?? null, forDiscord: true);
        }
        $lines[] = 'TCGplayer market prices via TCGCSV, collected once a day.';

        return implode("\n", $lines);
    }
}
