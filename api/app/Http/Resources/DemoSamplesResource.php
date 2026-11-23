<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The read-only demo's samples as GET /v1/demo/samples answers them: the published sample secret, the alert, the
 * delivered sample alert email and the signed sample webhook delivery (each null when not seeded). Never a stored
 * secret, a webhook URL or another account's row.
 */
final class DemoSamplesResource extends JsonResource
{
    /** @param array{sample_secret: string, alert: array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, alert_pct: int}|null, message: array{message_id: string, kind: string, channel: string, status: string, delivered_at: string, subject: string, text: string}|null, webhook: array{message_id: string, kind: string, url_hint: string, status: string, delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string}|null} $samples */
    public function __construct(array $samples)
    {
        parent::__construct($samples);
    }

    /**
     * @return array{sample_secret: string, alert: array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, alert_pct: int}|null, message: array{message_id: string, kind: 'alert.triggered', channel: 'email', status: 'delivered', delivered_at: string, subject: string, text: string}|null, webhook: array{message_id: string, kind: 'price.moved', url_hint: string, status: 'delivered', delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string}|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array{sample_secret: string, alert: array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, alert_pct: int}|null, message: array{message_id: string, kind: string, channel: string, status: string, delivered_at: string, subject: string, text: string}|null, webhook: array{message_id: string, kind: string, url_hint: string, status: string, delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string}|null} $samples */
        $samples = $this->resource;
        $message = $samples['message'];
        $webhook = $samples['webhook'];

        return [
            /** @var string the published sample signing secret (whsec_…); it belongs to no account */
            'sample_secret' => $samples['sample_secret'],
            /** @var array{variant_key: string, card_id: string, finish: string, edition: string, treatment: string, card: array{name: string, set_name: string, number: string}|null, alert_pct: int}|null */
            'alert' => $samples['alert'],
            /** @var array{message_id: string, kind: 'alert.triggered', channel: 'email', status: 'delivered', delivered_at: string, subject: string, text: string}|null */
            'message' => $message === null ? null : [...$message, 'kind' => 'alert.triggered', 'channel' => 'email', 'status' => 'delivered'],
            /** @var array{message_id: string, kind: 'price.moved', url_hint: string, status: 'delivered', delivered_at: string, headers: array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}, body: string}|null */
            'webhook' => $webhook === null ? null : [...$webhook, 'kind' => 'price.moved', 'status' => 'delivered'],
        ];
    }
}
