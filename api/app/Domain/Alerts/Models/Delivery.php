<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbound message (plan P5-i1 D1, D7). body is the exact bytes sent; message_id (msg_ + ULID) is the
 * webhook-id and never changes across attempts; event_id is a price_events id without a foreign key.
 *
 * @property int $id
 * @property string $message_id
 * @property int $user_id
 * @property string $kind
 * @property string $channel
 * @property int|null $endpoint_id
 * @property int|null $event_id
 * @property CarbonImmutable|null $day
 * @property string $body
 * @property string $status
 * @property int $attempts
 * @property int $claims
 * @property CarbonImmutable $next_attempt_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable $created_at
 */
#[Table('deliveries', timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
#[Guarded(['id'])]
final class Delivery extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'endpoint_id' => 'integer',
            'event_id' => 'integer',
            'day' => 'immutable_date',
            'attempts' => 'integer',
            'claims' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_status' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
