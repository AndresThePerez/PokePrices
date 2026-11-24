<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The freshness of the source's prices as GET /v1/prices/freshness answers it. age_hours keeps
 * its one decimal in JSON (26.0, never 26), so the API prints the same value as the command and the log line.
 */
final class PriceFreshnessResource extends JsonResource
{
    /**
     * @param  array{source: string, feed: string, as_of: string|null, day: string|null, age_hours: float|null, slo_hours: int, within_slo: bool, measured_at: string, last_run: array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null, missing_days: list<string>, missing_days_count: int}  $freshness
     */
    public function __construct(array $freshness)
    {
        parent::__construct($freshness);
    }

    /**
     * @return array{source: string, feed: string, as_of: string|null, day: string|null, age_hours: float|null, slo_hours: int, within_slo: bool, measured_at: string, last_run: array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null, missing_days: list<string>, missing_days_count: int}
     */
    public function toArray(Request $request): array
    {
        /** @var array{source: string, feed: string, as_of: string|null, day: string|null, age_hours: float|null, slo_hours: int, within_slo: bool, measured_at: string, last_run: array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null, missing_days: list<string>, missing_days_count: int} $freshness */
        $freshness = $this->resource;

        return [
            'source' => $freshness['source'],
            'feed' => $freshness['feed'],
            /** @var string|null */
            'as_of' => $freshness['as_of'],
            /** @var string|null */
            'day' => $freshness['day'],
            /** @var float|null */
            'age_hours' => $freshness['age_hours'],
            /** @var int */
            'slo_hours' => $freshness['slo_hours'],
            /** @var bool */
            'within_slo' => $freshness['within_slo'],
            'measured_at' => $freshness['measured_at'],
            /** @var array{id: int, status: string, mode: string, day: string, finished_at: string|null}|null */
            'last_run' => $freshness['last_run'],
            /** @var list<string> */
            'missing_days' => $freshness['missing_days'],
            /** @var int */
            'missing_days_count' => $freshness['missing_days_count'],
        ];
    }

    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}
