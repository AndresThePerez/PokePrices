<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\VariantKey;
use App\Domain\Alerts\AlertThresholds;
use App\Http\Requests\PutAlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class AlertController
{
    public function __construct(private readonly AlertThresholds $thresholds) {}

    /**
     * Set an alert on a watchlist card.
     *
     * Sets the alert threshold of one variant on the signed-in account's watchlist: a whole percentage from the movement rule's 5 (and at least $1.00) to 100. When that card's daily close moves by at least the threshold against the previous close, an alert is sent where alerts go (GET /v1/me/notifications). A key that names no PokéPrices variant or a variant not on the watchlist answers 422 with errors.variant_key; a pct that is not such a whole number answers 422 with errors.pct. Only a verified account may set alerts (403 email-not-verified); the demo account is read-only (403 demo-read-only).
     *
     * @operationId putAlert
     *
     * @throws ValidationException 422 errors.variant_key or errors.pct
     */
    public function put(PutAlertRequest $request, #[CurrentUser] User $user, string $variantKey): AlertResource
    {
        $pct = $request->pct();
        $this->thresholds->set($user->id, $variantKey, $pct);

        return new AlertResource($variantKey, $pct);
    }

    /**
     * Turn an alert off.
     *
     * Clears the alert threshold of the variant on the signed-in account's watchlist; 204 also when it had none, the variant is not on the watchlist or the key is malformed. The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteAlert
     */
    public function destroy(#[CurrentUser] User $user, string $variantKey): Response
    {
        if (VariantKey::isValid($variantKey)) {
            $this->thresholds->clear($user->id, $variantKey);
        }

        return response()->noContent();
    }
}
