<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's signed (standard) webhook or Discord webhook (plan P5-i1 D1). url and secret go through the encrypted
 * casts (APP_KEY), so the database never holds them in clear, and both are hidden: they never appear in toArray()
 * or toJson(). url_hint (scheme, host and port) is what is ever shown back.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $url
 * @property string $url_hint
 * @property string|null $secret
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $disabled_at
 */
#[Table('webhook_endpoints', timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
#[Guarded(['id'])]
#[Hidden(['url', 'secret'])]
final class WebhookEndpoint extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }
}
