<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One webhook as GET /v1/webhooks lists it (plan P5-i1 D9): its id, kind (standard: a signed webhook; discord), the
 * URL's scheme and host only (url_hint; the path, query and any token never leave the server), the description,
 * when it was added, whether it is turned off, and its newest delivery. Never the URL or the signing secret.
 */
final class WebhookEndpointResource extends JsonResource
{
    /** @param array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null} $row */
    public function __construct(array $row)
    {
        parent::__construct($row);
    }

    /**
     * @return array{id: int, kind: 'standard'|'discord', url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: 'pending'|'delivered'|'failed', at: string}|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null} $row */
        $row = $this->resource;

        return self::fields($row);
    }

    /**
     * The endpoint fields, shared with WebhookCreatedResource.
     *
     * @param  array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null}  $row
     * @return array{id: int, kind: 'standard'|'discord', url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: 'pending'|'delivered'|'failed', at: string}|null}
     */
    public static function fields(array $row): array
    {
        $last = $row['last_delivery'];

        return [
            /** @var int */
            'id' => $row['id'],
            'kind' => $row['kind'] === 'discord' ? 'discord' : 'standard',
            'url_hint' => $row['url_hint'],
            /** @var string|null */
            'description' => $row['description'],
            /** @var string RFC 3339 UTC */
            'created_at' => $row['created_at'],
            /** @var bool */
            'disabled' => $row['disabled'],
            /** @var array{status: 'pending'|'delivered'|'failed', at: string}|null */
            'last_delivery' => $last === null ? null : ['status' => self::status($last['status']), 'at' => $last['at']],
        ];
    }

    /** @return 'pending'|'delivered'|'failed' */
    private static function status(string $status): string
    {
        return match ($status) {
            'delivered' => 'delivered',
            'failed' => 'failed',
            default => 'pending',
        };
    }
}
