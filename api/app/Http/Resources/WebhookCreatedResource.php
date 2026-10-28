<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The 201 answer to POST /v1/webhooks: the new webhook as GET /v1/webhooks lists it, plus its
 * signing secret (whsec_…) for a signed webhook, null for Discord. The only answer that ever carries the secret.
 */
final class WebhookCreatedResource extends JsonResource
{
    /** @param array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null} $row */
    public function __construct(array $row, #[\SensitiveParameter] private readonly ?string $secret)
    {
        parent::__construct($row);
    }

    /**
     * @return array{id: int, kind: 'standard'|'discord', url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: 'pending'|'delivered'|'failed', at: string}|null, secret: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: int, kind: string, url_hint: string, description: string|null, created_at: string, disabled: bool, last_delivery: array{status: string, at: string}|null} $row */
        $row = $this->resource;

        $fields = WebhookEndpointResource::fields($row);

        // Spelled out key by key, so the contract's 201 schema lists every field (Scramble reads the literal).
        return [
            /** @var int */
            'id' => $fields['id'],
            'kind' => $fields['kind'],
            'url_hint' => $fields['url_hint'],
            /** @var string|null */
            'description' => $fields['description'],
            /** @var string RFC 3339 UTC */
            'created_at' => $fields['created_at'],
            /** @var bool */
            'disabled' => $fields['disabled'],
            /** @var array{status: 'pending'|'delivered'|'failed', at: string}|null */
            'last_delivery' => $fields['last_delivery'],
            // whsec_ and the base64 key, shown once; null for Discord.
            'secret' => $this->secret,
        ];
    }
}
