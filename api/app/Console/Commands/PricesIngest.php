<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\PriceDigest;
use App\Support\IsoDay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

#[Description('Ingest one collected TCGCSV day into the PokéPrices price tables; the snapshot is only read.')]
#[Signature('pokeprices:prices:ingest
    {--day= : The collected day, YYYY-MM-DD}
    {--sync : Run start, every chunk and finalize in this process, in one transaction}
    {--queued : Start the run here, then ingest its chunks and finalize it through queued jobs}
    {--resume : Re-dispatch the chunks not done of the newest running or failed queued run of the day\'s marker}
    {--wait= : With --queued or --resume, poll the run for at most this many seconds and print its counts}
    {--queue-connection=redis : The queue connection of the queued jobs}
    {--snapshots= : The snapshots root holding <day>/ (default: services.tcgcsv.snapshots)}
    {--connection= : The database connection to write to (default: the default connection)}
    {--force : Re-ingest a marker that already has an ok run (idempotent)}
    {--digest : Add the determinism digest of the price tables to the JSON line}')]
final class PricesIngest extends Command
{
    public function handle(): int
    {
        $day = $this->stringOption('day');
        if (! IsoDay::isDay($day)) {
            $this->error('--day must be a date in the form YYYY-MM-DD');

            return self::FAILURE;
        }
        $modes = array_keys(array_filter(['sync' => $this->option('sync'), 'queued' => $this->option('queued'), 'resume' => $this->option('resume')],
            fn (mixed $on): bool => $on === true));
        if (count($modes) !== 1) {
            $this->error('choose one mode: --sync, --queued or --resume');

            return self::FAILURE;
        }
        $wait = $this->stringOption('wait');
        if ($wait !== '' && preg_match('/\A\d{1,6}\z/', $wait) !== 1) {
            $this->error('--wait must be a whole number of seconds');

            return self::FAILURE;
        }
        $snapshots = $this->stringOption('snapshots');
        if ($snapshots === '') {
            $configured = config('services.tcgcsv.snapshots');
            $snapshots = is_string($configured) ? $configured : '';
        }
        $name = $this->stringOption('connection');

        try {
            $db = DB::connection($name === '' ? null : $name);
        } catch (InvalidArgumentException $e) {
            $this->error("--connection {$name} is not a configured connection: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($modes[0] !== 'sync') {
            return $this->queued($modes[0], $day, $snapshots, $name === '' ? null : $name, $db, $wait === '' ? null : (int) $wait);
        }

        $result = (new DayIngest($db, $snapshots))->sync($day, $this->option('force') === true);
        $marker = $result['marker']?->utc()->format('Y-m-d\TH:i:s\Z');

        if ($result['status'] === 'locked') {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }
        if ($result['status'] === 'refused' || $result['status'] === 'failed') {
            $this->error("ingest {$result['status']} (run {$result['run_id']}): {$result['error']}");

            return self::FAILURE;
        }

        $line = ['run_id' => $result['run_id'], 'day' => $day, 'marker' => $marker];
        if ($result['status'] === 'skipped_unchanged') {
            $line['status'] = 'skipped_unchanged';
        } else {
            foreach (['rows', 'mapped', 'unmapped', 'null_market', 'upserted', 'unchanged', 'daily_rows'] as $key) {
                $line[$key] = $result['counts'][$key] ?? 0;
            }
        }
        if ($this->option('digest') === true) {
            $line['digest'] = PriceDigest::compute($db);
        }
        $this->line(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * --queued runs StartDayIngest's work here (validation, the run, the chunk plan, the batch dispatch), so the run id
     * is known at once and a refusal or the held lock exits 1 like --sync; the chunks and finalize run on the queue.
     * --resume re-opens the newest running or failed queued run of the day's marker. With --wait the command polls the
     * run row (no shell sleep) until it is `ok`, `failed` or `superseded`, or the bound is reached, prints the run's
     * JSON line with the price-table digests, and exits 0 only for `ok`.
     *
     * @param  'queued'|'resume'  $mode
     */
    private function queued(string $mode, string $day, string $snapshots, ?string $database, ConnectionInterface $db, ?int $wait): int
    {
        $connection = $this->stringOption('queue-connection');
        $connection = $connection === '' ? StartDayIngest::CONNECTION : $connection;

        try {
            $result = $mode === 'queued'
                ? (new StartDayIngest($day, $snapshots, $this->option('force') === true, $connection, $database))->begin()
                : (new DayIngest($db, $snapshots))->resume($day, $connection, $database);
        } catch (Throwable $e) {
            $this->error("ingest {$mode} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $marker = $result['marker']?->utc()->format('Y-m-d\TH:i:s\Z');

        if ($result['status'] === 'locked' || $result['status'] === 'none') {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }
        if ($result['status'] === 'refused') {
            $this->error("ingest refused (run {$result['run_id']}): {$result['error']}");

            return self::FAILURE;
        }
        if ($result['status'] === 'skipped_unchanged') {
            $this->line(json_encode(['run_id' => $result['run_id'], 'day' => $day, 'marker' => $marker, 'status' => 'skipped_unchanged'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $runId = (int) $result['run_id'];
        if ($result['error'] !== null) {
            $this->warn("run {$runId}: {$result['error']}");
        }

        if ($wait === null) {
            $line = ['run_id' => $runId, 'status' => $this->status($db, $runId)];
            if ($mode === 'resume') {
                $line['resumed_chunks'] = count($result['chunks']);
            }
            $this->line(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $line['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $deadline = hrtime(true) + $wait * 1_000_000_000;
        while (! in_array($status = $this->status($db, $runId), ['ok', 'failed', 'superseded'], true) && hrtime(true) < $deadline) {
            usleep(250_000);
        }

        $run = $db->selectOne('SELECT counts, error FROM ingest_runs WHERE id = ?', [$runId], false);
        $counts = $run instanceof stdClass ? json_decode((string) $run->counts, true, 512, JSON_THROW_ON_ERROR) : [];
        $line = ['run_id' => $runId, 'day' => $day, 'marker' => $marker, 'status' => $status];
        foreach (['rows', 'mapped', 'unmapped', 'null_market', 'upserted', 'unchanged', 'daily_rows'] as $key) {
            $line[$key] = is_array($counts) && is_int($counts[$key] ?? null) ? $counts[$key] : 0;
        }
        $line['digests'] = PriceDigest::compute($db);
        $this->line(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($status === 'ok') {
            return self::SUCCESS;
        }
        $this->error($status === 'running'
            ? "run {$runId} is still running after --wait={$wait} seconds"
            : "run {$runId} ended {$status}: ".($run instanceof stdClass ? (string) $run->error : ''));

        return self::FAILURE;
    }

    private function status(ConnectionInterface $db, int $runId): string
    {
        $status = $db->table('ingest_runs')->where('id', $runId)->value('status');

        return is_string($status) ? $status : 'missing';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
