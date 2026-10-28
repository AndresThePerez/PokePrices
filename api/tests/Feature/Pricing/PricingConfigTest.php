<?php

declare(strict_types=1);

use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Pricing\PricingConfig;
use App\Http\Resources\CardPricesResource;
use App\Http\Resources\PriceBlock;
use Illuminate\Http\Request;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan Q1-i4 T03 (A7, HG-43 #1 default): the five provenance blocks (movers meta, portfolio meta, card prices meta,
// PriceBlock, the price.moved payload) carry PricingConfig::provenance()'s source, feed, basis and currency; the
// three that carry cadence today still do, the two that do not still lack it, and every key list stays exact.

beforeEach(function (): void {
    Accounts::setUp();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** @return array<string, array<string, mixed>> the five blocks, built through their public surfaces */
function pcBlocks(object $test): array
{
    $movers = $test->getJson('/v1/movers')->assertOk()->json('meta');
    $portfolio = AccountRows::signIn($test, Accounts::user())->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio')->assertOk()->json('meta');
    $prices = (new CardPricesResource([], '1M', null, null, null))->with(Request::create('/'))['meta'];
    $block = PriceBlock::fromRow([
        'price_variant_key' => 'ex5-17:reverse:-:-', 'price_source' => PricingConfig::source(), 'price_series_epoch' => 1,
        'price_close' => '26.34', 'price_day' => '2026-10-12', 'price_as_of' => '2026-10-12 20:05:45+00',
        'price_previous_close' => '25.00', 'price_previous_day' => '2026-10-11', 'price_change' => '1.34', 'price_change_pct' => '5.36',
    ]);
    $event = (object) [
        'id' => 7, 'variant_key' => 'ex5-17:reverse:-:-', 'card_id' => 'ex5-17', 'direction' => 'up', 'close' => '26.34',
        'previous_close' => '25.00', 'change' => '1.34', 'change_pct' => '5.36', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z',
        'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z', 'series_epoch' => 1, 'rule' => 'draft-v1',
        'source' => PricingConfig::source(),
    ];
    $payload = json_decode(PriceMovedPayload::encode($event, null), true, 512, JSON_THROW_ON_ERROR)['data'];

    expect($block)->not->toBeNull();

    return ['movers' => $movers, 'portfolio' => $portfolio, 'prices' => $prices, 'block' => (array) $block, 'payload' => $payload];
}

it('states the provenance once: source and feed from config, basis market, currency USD, cadence daily', function (): void {
    expect(PricingConfig::provenance())->toBe(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD'])
        ->and(PricingConfig::CADENCE)->toBe('daily')
        ->and(PricingConfig::source())->toBe('tcgplayer')
        ->and(PricingConfig::feed())->toBe('tcgcsv')
        ->and(PricingConfig::queue())->toBe('default')
        ->and(PricingConfig::moversLimit())->toBe(50)
        ->and(PricingConfig::MOVERS_DEFAULT_LIMIT)->toBe(20);
});

it('gives the five provenance blocks PricingConfig::provenance()\'s values and today\'s exact key lists, cadence only on three', function (): void {
    $blocks = pcBlocks($this);
    $provenance = PricingConfig::provenance();

    foreach ($blocks as $name => $block) {
        expect(array_intersect_key($block, $provenance))->toBe($provenance, $name);
    }
    expect(array_keys($blocks['movers']))->toBe(['day', 'as_of', 'source', 'feed', 'basis', 'currency', 'window', 'min_previous_close', 'direction', 'limit', 'degraded'])
        ->and(array_keys($blocks['portfolio']))->toBe(['count', 'limit', 'total_value', 'priced', 'unpriced', 'first_day', 'last_day', 'source', 'feed', 'basis', 'currency', 'degraded'])
        ->and(array_keys($blocks['prices']))->toBe(['source', 'feed', 'basis', 'currency', 'cadence', 'range', 'from', 'to', 'as_of'])
        ->and(array_keys($blocks['block']))->toBe(['source', 'feed', 'basis', 'currency', 'cadence', 'close', 'day', 'as_of', 'previous_close', 'previous_day', 'change', 'change_pct', 'series_epoch'])
        ->and(array_keys($blocks['payload']))->toBe(['event_id', 'variant_key', 'card_id', 'card', 'direction', 'close', 'previous_close', 'change', 'change_pct', 'day', 'as_of', 'previous_day', 'previous_as_of', 'series_epoch', 'rule', 'source', 'feed', 'basis', 'currency', 'cadence'])
        ->and([$blocks['prices']['cadence'], $blocks['block']['cadence'], $blocks['payload']['cadence']])->toBe([PricingConfig::CADENCE, PricingConfig::CADENCE, PricingConfig::CADENCE]);
});

it('follows a changed pricing.feed in every block through the one accessor', function (): void {
    config(['pricing.feed' => 'example-feed']);

    $blocks = pcBlocks($this);

    expect(PricingConfig::feed())->toBe('example-feed')
        ->and(array_map(fn (array $block): mixed => $block['feed'], $blocks))
        ->toBe(['movers' => 'example-feed', 'portfolio' => 'example-feed', 'prices' => 'example-feed', 'block' => 'example-feed', 'payload' => 'example-feed']);
});
