<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\DiscordMessage;
use App\Domain\Pricing\PriceJobProblems;
use App\Domain\Pricing\ReportedPriceProblems;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The price-job report: one Discord message when a problem of the price job starts and one when it is gone, never a
 * repeat. The scheduler runs it itself every hour (it is not queued), so a stopped worker is still reported.
 *
 * What is wrong is PriceJobProblems::all() and nothing else, so the freshness answer, the log and Discord cannot
 * disagree. A problem's identity is its key: every key gets its own message, so a refused or stuck day inside the
 * span of ingested days is posted under both its kinds. What was posted is kept in reported_price_problems
 * (ReportedPriceProblems), so a restart repeats nothing. Each run posts the problems that have no open report, in the
 * list's order, then a "cleared" message for each open report whose key is no longer listed, oldest first. A problem
 * that starts and ends between two runs is never posted; the same problem starting again after it was cleared is new.
 *
 * config('alerts.discord.price_job_webhook_url') comes from PRICE_JOB_DISCORD_WEBHOOK_URL. Unset or empty: it prints
 * that nothing was sent and exits 0. Each message is one attempt through the alert sender's guarded, pinned Discord
 * POST. A report is written only after its message was accepted (2xx); a failed post writes a
 * price_problem_report_failed warning with the key, started or cleared, the HTTP status and the error, leaves the
 * report as it was and is tried again on the next run. It prints {"posted", "cleared", "failed"} and exits 1 when a
 * post failed. One run at a time: a run that finds the report lock held prints so and exits 0. The address is never
 * printed, logged or stored.
 */
#[Description('Post each PokéPrices price-job problem to Discord once when it starts and once when it clears.')]
#[Signature('pokeprices:prices:report-problems')]
final class PricesReportProblems extends Command
{
    public const string NOT_SET = 'PRICE_JOB_DISCORD_WEBHOOK_URL is not set; nothing was sent.';

    public const string LOCKED = 'Another price-job report is running; nothing was sent.';

    /** The sentence of the problem, then the instant the report first saw it. */
    public const string STARTED = 'PokéPrices price job problem: %s Seen since %s.';

    /** The sentence as it was reported, the instant it was first seen, the instant of the run that found it gone. */
    public const string CLEARED = 'PokéPrices price job problem cleared: %s That was seen since %s and is over as of %s.';

    public function handle(DeliverySender $sender): int
    {
        $url = config('alerts.discord.price_job_webhook_url');
        if (! is_string($url) || trim($url) === '') {
            $this->line(self::NOT_SET);

            return self::SUCCESS;
        }
        $url = trim($url);

        $db = DB::connection();
        if (! ReportedPriceProblems::tryLock($db)) {
            $this->line(self::LOCKED);

            return self::SUCCESS;
        }

        $posted = $cleared = $failed = 0;
        try {
            $now = CarbonImmutable::now()->utc()->startOfSecond();
            $current = PriceJobProblems::all($db);
            $open = ReportedPriceProblems::open($db);
            $reported = array_column($open, 'key');
            $listed = array_column($current, 'key');

            foreach ($current as $problem) {
                if (in_array($problem['key'], $reported, true)) {
                    continue;
                }
                $text = sprintf(self::STARTED, $problem['sentence'], self::instant($now));
                if (self::post($sender, $url, $text, $problem['key'], 'started') && ReportedPriceProblems::report($problem, $now, $db)) {
                    $posted++;
                } else {
                    $failed++;
                }
            }
            foreach ($open as $report) {
                if (in_array($report['key'], $listed, true)) {
                    continue;
                }
                $text = sprintf(self::CLEARED, $report['sentence'], self::instant($report['reported_at']), self::instant($now));
                if (self::post($sender, $url, $text, $report['key'], 'cleared') && ReportedPriceProblems::clear($report['id'], $now, $db)) {
                    $cleared++;
                } else {
                    $failed++;
                }
            }
        } finally {
            ReportedPriceProblems::unlock($db);
        }

        $this->line(json_encode(['posted' => $posted, 'cleared' => $cleared, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** One attempt to post $text; whether Discord accepted it. A failure is logged without the address. */
    private static function post(DeliverySender $sender, #[\SensitiveParameter] string $url, string $text, string $key, string $event): bool
    {
        $exception = null;
        try {
            [$status, $error] = $sender->discord($url, DiscordMessage::envelope($text));
        } catch (Throwable $e) {
            // Containment: an exception message can carry the address, so only its class name is kept.
            [$status, $error, $exception] = [null, 'internal', class_basename($e)];
        }
        if ($error === null && $status !== null && $status >= 200 && $status < 300) {
            return true;
        }

        $context = ['key' => $key, 'event' => $event, 'status' => $status, 'error' => $error ?? 'http_status'];
        if ($exception !== null) {
            $context['exception'] = $exception;
        }
        Log::warning('price_problem_report_failed', $context);

        return false;
    }

    private static function instant(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
