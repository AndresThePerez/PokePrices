<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\IsoDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Records the pokemontcg.io price oracle for one collected day, once.
 *
 * Keyless GETs of every cards page with the PokéPrices user agent, at least 2 s apart; a page answered with a 5xx,
 * an HTML body or a body that is not a cards page is retried up to 5 times, 2 s apart. Each page body is written
 * verbatim to <oracle root>/<day>/cards-NNN.json and the run to fetch.json. The run must start inside
 * [marker, marker+24h) of the collected day; --allow-late lifts the upper bound and records late: true (the oracle comparison then
 * reports a fetch-window violation). It refuses inside a gate run (GATES_OUT set), never overwrites a recorded
 * day and never deletes a file. This command is run by hand by the operator, never by a test or a gate.
 */
#[Description('Record the pokemontcg.io price oracle for one collected day (by hand, once; never inside a gate).')]
#[Signature('pokeprices:oracle:fetch
    {--day= : The collected day, YYYY-MM-DD}
    {--allow-late : Fetch after marker+24h and record late: true}
    {--snapshots= : The snapshots root holding <day>/marker.txt (default: services.tcgcsv.snapshots)}
    {--oracle-root= : Where <day>/ is written (default: services.oracle.root)}')]
final class OracleFetch extends Command
{
    public const string BASE_URL = 'https://api.pokemontcg.io/v2/cards';

    public const string USER_AGENT = 'pokeprices/0.1 (+https://andrestheperez.com)';

    public const int PAGE_SIZE = 250;

    public const int SPACING_SECONDS = 2;

    public const int RETRIES = 5;

    private bool $sent = false;

    public function handle(): int
    {
        $this->sent = false;
        $gatesOut = getenv('GATES_OUT');
        if (is_string($gatesOut) && $gatesOut !== '') {
            $this->error('the oracle is recorded by hand, never inside a gate run (GATES_OUT is set)');

            return self::FAILURE;
        }
        $day = $this->stringOption('day');
        if (! IsoDay::isDay($day)) {
            $this->error('--day must be a date in the form YYYY-MM-DD');

            return self::FAILURE;
        }
        $snapshots = $this->stringOption('snapshots');
        if ($snapshots === '') {
            $configured = config('services.tcgcsv.snapshots');
            $snapshots = is_string($configured) ? $configured : '';
        }
        $markerPath = rtrim($snapshots, '/')."/{$day}/marker.txt";
        $markerText = is_file($markerPath) ? trim((string) file_get_contents($markerPath)) : '';
        $marker = self::parseMarker($markerText);
        if ($marker === null) {
            $this->error("{$markerPath} is missing or unreadable; the oracle is recorded against a collected day");

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        if ($now->lessThan($marker)) {
            $this->error("it is before the day's marker {$markerText}; refusing");

            return self::FAILURE;
        }
        $late = $now->greaterThanOrEqualTo($marker->addDay());
        if ($late && $this->option('allow-late') !== true) {
            $this->error("outside the window [{$markerText}, +24h); refusing (use --allow-late to record a late oracle)");

            return self::FAILURE;
        }

        $root = $this->stringOption('oracle-root');
        if ($root === '') {
            $configured = config('services.oracle.root');
            $root = is_string($configured) ? $configured : '';
        }
        $dir = rtrim($root, '/').'/'.$day;
        if (is_file($dir.'/fetch.json') || (glob($dir.'/cards-*.json') ?: []) !== []) {
            $this->error("{$dir} already holds a recorded oracle; it is never overwritten");

            return self::FAILURE;
        }
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("could not create {$dir}");

            return self::FAILURE;
        }

        $run = ['day' => $day, 'marker' => $markerText, 'started_at' => self::stamp($now), 'finished_at' => null, 'pages_expected' => null, 'late' => $late, 'pages' => []];
        for ($n = 1; $run['pages_expected'] === null || $n <= $run['pages_expected']; $n++) {
            $page = $this->fetchPage($n);
            $run['pages'][] = ['n' => $n, 'status' => $page['status'], 'attempts' => $page['attempts'], 'fetched_at' => $page['fetched_at']];
            if ($page['body'] === null) {
                return $this->finish($dir, $run, "page {$n} failed after {$page['attempts']} attempts (last status {$page['status']})");
            }
            if ($n === 1) {
                $total = $page['total'];
                $run['pages_expected'] = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
            }
            $handle = @fopen(sprintf('%s/cards-%03d.json', $dir, $n), 'xb');
            if ($handle === false || fwrite($handle, $page['body']) !== strlen($page['body'])) {
                return $this->finish($dir, $run, "could not write page {$n}");
            }
            fclose($handle);
            $this->line("page {$n}/{$run['pages_expected']}: {$page['status']} after {$page['attempts']} attempt(s)");
        }

        return $this->finish($dir, $run, null);
    }

    /**
     * One page with its retries. A page counts when it is a 200 JSON cards page with an integer totalCount.
     *
     * @return array{status: int, attempts: int, fetched_at: string, body: string|null, total: int}
     */
    private function fetchPage(int $n): array
    {
        $url = self::BASE_URL."?page={$n}&pageSize=".self::PAGE_SIZE.'&select=id,set,tcgplayer';
        $status = 0;
        for ($attempt = 1; $attempt <= 1 + self::RETRIES; $attempt++) {
            if ($this->sent) {
                Sleep::for(self::SPACING_SECONDS)->seconds();
            }
            $this->sent = true;
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->accept('application/json')->timeout(60)->get($url);
            } catch (ConnectionException) {
                $status = 0;

                continue;
            }
            $status = $response->status();
            $at = self::stamp(CarbonImmutable::now('UTC'));
            if ($status >= 500 || self::isHtml($response)) {
                continue;
            }
            if ($status !== 200) {
                return ['status' => $status, 'attempts' => $attempt, 'fetched_at' => $at, 'body' => null, 'total' => 0];
            }
            $document = json_decode($response->body(), true);
            if (! is_array($document) || ! is_array($document['data'] ?? null) || ! is_int($document['totalCount'] ?? null)) {
                continue;
            }

            return ['status' => $status, 'attempts' => $attempt, 'fetched_at' => $at, 'body' => $response->body(), 'total' => $document['totalCount']];
        }

        return ['status' => $status, 'attempts' => 1 + self::RETRIES, 'fetched_at' => self::stamp(CarbonImmutable::now('UTC')), 'body' => null, 'total' => 0];
    }

    private static function isHtml(Response $response): bool
    {
        return str_contains(strtolower($response->header('Content-Type')), 'text/html') || str_starts_with(ltrim($response->body()), '<');
    }

    /** @param  array<string, mixed>  $run */
    private function finish(string $dir, array $run, ?string $error): int
    {
        $run['finished_at'] = self::stamp(CarbonImmutable::now('UTC'));
        $written = file_put_contents($dir.'/fetch.json', json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        if ($error !== null || $written === false) {
            $this->error('oracle fetch failed: '.($error ?? "could not write {$dir}/fetch.json"));

            return self::FAILURE;
        }
        $this->info(sprintf('recorded %d pages into %s', count($run['pages']), $dir));

        return self::SUCCESS;
    }

    private static function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function parseMarker(string $text): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/', $text) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($text)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
