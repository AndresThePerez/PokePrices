<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeZone;
use Illuminate\Support\Facades\Context;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/** One JSON object per line with the PokéPrices top-level log schema (O2). */
final class JsonLineFormatter extends NormalizerFormatter
{
    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $requestId = Context::get('request_id');
        $line = [
            'ts' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'request_id' => is_string($requestId) ? $requestId : '',
            'route' => is_string($context['route'] ?? null) ? $context['route'] : '',
            'duration_ms' => is_int($context['duration_ms'] ?? null) || is_float($context['duration_ms'] ?? null) ? $context['duration_ms'] : null,
            'context' => $this->normalize(array_diff_key($context, ['route' => true, 'duration_ms' => true])),
        ];

        // JSON_PRESERVE_ZERO_FRACTION: a float such as price_freshness's age_hours 26.0 stays 26.0, the value the API
        // and the freshness command print (P2-i1 T10, D8).
        return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
    }
}
