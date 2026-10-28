<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Problems\UpstreamContract;
use App\Domain\Catalog\Problems\UpstreamUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks every card the product map holds against the PokéSearch catalog (plan T45, F-P1-031, R129 queue half).
 *
 * Queued when a catalog read sees a seed ref other than the remembered one (CatalogRepository) and after each
 * pokeprices:map:build. The distinct card_variants.card_id values go to GET /api/v1/cards?ids= in chunks of 100;
 * an id in the answer's data becomes catalog_status ok, one in its missing list catalog_missing, and every row
 * of a checked id gets the answer's seed ref and the check time. Rows are never deleted and no mapping or epoch
 * is touched. All answers are collected before anything is written, so a failure part-way leaves every status
 * as it was.
 *
 * A failed call (UpstreamUnavailable: a refused connection, a timeout, a 5xx or the open request breaker) is
 * handled by ThrottlesExceptionsWithRedis(5, 30) keyed by "pokesearch" on redis-queue under the circuit
 * breaker's prefix: the job is released, and after five failures within 30 s further runs are released
 * without calling PokéSearch until the 30 s are over.
 *
 * Timeout 80 s, below the redis queue's retry_after (90 s); three tries, as the worker's --tries gave it before (plan
 * Q1-i1 T02, review b b4: attributes only).
 */
#[Tries(3)]
#[Timeout(80)]
final class VerifyCatalog implements ShouldQueue
{
    use Queueable;

    public const int CHUNK = 100;

    /** @param string|null $database the database connection whose card_variants are checked (default: the default) */
    public function __construct(public readonly ?string $database = null) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptionsWithRedis(5, 30))
                ->by('pokesearch')
                ->withPrefix(CircuitBreaker::keyPrefix().'queue:')
                ->connection('default'),
        ];
    }

    /**
     * @throws UpstreamUnavailable when PokéSearch cannot answer (the middleware releases the job)
     * @throws UpstreamContract when an answer does not account for every id it was asked about
     */
    public function handle(PokeSearchClient $client): void
    {
        $db = DB::connection($this->database);

        /** @var list<string> $ids */
        $ids = $db->table('card_variants')->distinct()->orderBy('card_id')->pluck('card_id')->all();

        // status => seed ref segment => ids; the segment is the ref, or '' for an unseeded index.
        $verdicts = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            [$found, $missing, $seedRef] = self::verdicts($client->cards($chunk));
            foreach ($chunk as $id) {
                $status = match (true) {
                    isset($found[$id]) => 'ok',
                    isset($missing[$id]) => 'catalog_missing',
                    default => throw new UpstreamContract("PokéSearch answered neither data nor missing for card {$id}."),
                };
                $verdicts[$status][$seedRef ?? ''][] = $id;
            }
        }

        $checkedAt = Carbon::now();
        $db->transaction(function () use ($db, $verdicts, $checkedAt): void {
            foreach ($verdicts as $status => $bySeed) {
                foreach ($bySeed as $seedRef => $statusIds) {
                    foreach (array_chunk($statusIds, 1000) as $batch) {
                        $db->table('card_variants')->whereIn('card_id', $batch)->update([
                            'catalog_status' => $status,
                            'catalog_seed_ref' => $seedRef === '' ? null : $seedRef,
                            'catalog_checked_at' => $checkedAt,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * The found ids, the missing ids (both as sets) and the seed ref of one batch answer.
     *
     * @param  array<mixed>  $answer
     * @return array{0: array<string, true>, 1: array<string, true>, 2: string|null}
     *
     * @throws UpstreamContract when data, missing or seed is not shaped as PokéSearch documents them
     */
    private static function verdicts(array $answer): array
    {
        $data = $answer['data'] ?? null;
        $missing = $answer['missing'] ?? null;
        if (! is_array($data) || ! array_is_list($data) || ! is_array($missing) || ! array_is_list($missing)) {
            throw new UpstreamContract('PokéSearch batch answer has no data and missing lists.');
        }

        $found = [];
        foreach ($data as $card) {
            if (! is_array($card) || ! is_string($card['id'] ?? null)) {
                throw new UpstreamContract('PokéSearch batch answer has a card without a string id.');
            }
            $found[$card['id']] = true;
        }
        $absent = [];
        foreach ($missing as $id) {
            if (! is_string($id)) {
                throw new UpstreamContract('PokéSearch batch answer has a missing id that is not a string.');
            }
            $absent[$id] = true;
        }

        // A v1 answer always carries the seed object; an index seeded before provenance stamping sends nulls.
        $seed = $answer['seed'] ?? null;
        if (is_array($seed) && ($seed['ref'] ?? null) === null) {
            $seed = null;
        }
        $seed = SeedRef::fromMeta(['seed' => $seed]);

        return [$found, $absent, $seed->ref];
    }
}
