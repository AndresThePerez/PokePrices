<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Pricing\PricingConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * A person's portfolio: the variants they hold with a quantity, read with their latest closes
 * and valued in one SQL statement, set and removed by variant key. All money arithmetic happens in Postgres numeric:
 * a holding's value is quantity × close as numeric(20,2) text, the total sums the priced holdings only, and
 * first_day/last_day are the oldest and newest close days it rests on. A holding without a close has no value and is
 * never counted as zero. Setting counts then inserts under the per-user lock (AccountLock), so the portfolio never
 * holds more than accounts.limits.portfolio rows.
 *
 * The estimate's change (figures()): change sums quantity × (close − previous close) over the holdings that have
 * both, the previous close being the one each row's price block shows (price_latest); change_pct is change against
 * the sum of quantity × previous close over the same holdings, × 100, rounded once to two decimals (half away from
 * zero), null when that sum is 0.00 or no holding has both. no_previous_close counts the holdings with a close and no
 * previous close: they are in the total and not in the change.
 */
final class Portfolio
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The user's holdings ordered by card id, then variant key (each row: variant_key, card_id, finish, edition,
     * treatment, quantity, value and the price_<column> values of its latest close), with the portfolio's figures
     * (figures()) over all of them, in one statement.
     *
     * @return array{rows: list<array<string, mixed>>, count: int, priced: int, unpriced: int, no_previous_close: int, total_value: string|null, change: string|null, change_pct: string|null, first_day: string|null, last_day: string|null}
     */
    public function holdings(int $userId): array
    {
        $sql = 'SELECT '.VariantRows::selectAndJoin('h').', h.quantity,'
            .' (h.quantity * p.close)::numeric(20,2)::text AS value, '
            .self::figures(' OVER ()')
            .' FROM portfolio_holdings h '.VariantRows::joins('h')
            .' WHERE h.user_id = ? '.VariantRows::orderBy('h');
        $rows = VariantRows::arrays($this->db->select($sql, [PricingConfig::source(), $userId], false));

        return ['rows' => $rows, ...self::figuresOf($rows[0] ?? [])];
    }

    /**
     * The portfolio's figures alone, as holdings() gives them, in one aggregate statement over the holdings and their
     * latest closes (no catalog read): what GET /v1/me carries for the header.
     *
     * @return array{count: int, priced: int, unpriced: int, no_previous_close: int, total_value: string|null, change: string|null, change_pct: string|null, first_day: string|null, last_day: string|null}
     */
    public function summary(int $userId): array
    {
        $sql = 'SELECT '.self::figures('')
            .' FROM portfolio_holdings h'
            .' LEFT JOIN price_latest p ON p.variant_key = h.variant_key AND p.source = ?'
            .' WHERE h.user_id = ?';
        $rows = VariantRows::arrays($this->db->select($sql, [PricingConfig::source(), $userId], false));

        return self::figuresOf($rows[0] ?? []);
    }

    /**
     * The select list of the figures over portfolio_holdings h LEFT JOIN price_latest p: aggregates, each followed by
     * $over (' OVER ()' in the per-row read, '' in the summary), so both reads compute them with the same expressions.
     */
    private static function figures(string $over): string
    {
        $change = "sum(h.quantity * (p.close - p.previous_close)){$over}";
        $previous = "sum(h.quantity * p.previous_close){$over}";

        return "count(*){$over} AS count, count(p.close){$over} AS priced,"
            ." count(*) FILTER (WHERE p.close IS NOT NULL AND p.previous_close IS NULL){$over} AS no_previous_close,"
            ." (sum(h.quantity * p.close){$over})::numeric(20,2)::text AS total_value,"
            ." ({$change})::numeric(20,2)::text AS change,"
            ." CASE WHEN {$previous} IS NULL OR {$previous} = 0 THEN NULL ELSE round({$change} / {$previous} * 100, 2)::text END AS change_pct,"
            ." to_char(min(p.day){$over}, 'YYYY-MM-DD') AS first_day,"
            ." to_char(max(p.day){$over}, 'YYYY-MM-DD') AS last_day";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{count: int, priced: int, unpriced: int, no_previous_close: int, total_value: string|null, change: string|null, change_pct: string|null, first_day: string|null, last_day: string|null}
     */
    private static function figuresOf(array $row): array
    {
        $count = is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0;
        $priced = is_numeric($row['priced'] ?? null) ? (int) $row['priced'] : 0;
        $text = fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return [
            'count' => $count,
            'priced' => $priced,
            'unpriced' => $count - $priced,
            'no_previous_close' => is_numeric($row['no_previous_close'] ?? null) ? (int) $row['no_previous_close'] : 0,
            'total_value' => $text('total_value'),
            'change' => $text('change'),
            'change_pct' => $text('change_pct'),
            'first_day' => $text('first_day'),
            'last_day' => $text('last_day'),
        ];
    }

    /**
     * Sets the quantity the user holds of the variant (never adds to it). The quantity is validated by the caller
     * (1 to accounts.limits.quantity_max; the database checks 1 to 9999 too).
     *
     * @throws ValidationException errors.variant_key when the key names no variant, or the portfolio is full
     */
    public function set(int $userId, string $variantKey, int $quantity): void
    {
        if (! VariantKey::isValid($variantKey)) {
            throw self::refused(AccountLimits::UNKNOWN_VARIANT);
        }

        $this->db->transaction(function () use ($userId, $variantKey, $quantity): void {
            AccountLock::forUser($this->db, $userId);
            if (! Variants::exist($this->db, $variantKey)) {
                throw self::refused(AccountLimits::UNKNOWN_VARIANT);
            }
            $updated = $this->db->update(
                'UPDATE portfolio_holdings SET quantity = ?, updated_at = now() WHERE user_id = ? AND variant_key = ?',
                [$quantity, $userId, $variantKey],
            );
            if ($updated > 0) {
                return;
            }
            if (Variants::count($this->db, 'portfolio_holdings', $userId) >= AccountLimits::portfolio()) {
                throw self::refused(AccountLimits::portfolioFull());
            }
            $this->db->insert('INSERT INTO portfolio_holdings (user_id, variant_key, quantity) VALUES (?, ?, ?)', [$userId, $variantKey, $quantity]);
        });
    }

    /** Removes the holding; nothing happens when it is not there or the key is malformed. */
    public function remove(int $userId, string $variantKey): void
    {
        if (VariantKey::isValid($variantKey)) {
            $this->db->delete('DELETE FROM portfolio_holdings WHERE user_id = ? AND variant_key = ?', [$userId, $variantKey]);
        }
    }

    private static function refused(string $message): ValidationException
    {
        return ValidationException::withMessages(['variant_key' => [$message]]);
    }
}
