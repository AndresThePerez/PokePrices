<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accounts\AccountLimits;
use App\Http\Rules\ValidVariantKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @ignoreSchema
 */
final class PutQuantityRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer:strict', 'min:1', 'max:'.AccountLimits::quantityMax()],
        ];
    }

    /** The validated quantity, 1 to accounts.limits.quantity_max. */
    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }

    /** The key's shape is checked before any query and before the body (plan P4-i1 D5). */
    protected function prepareForValidation(): void
    {
        ValidVariantKey::check($this->route('variantKey'));
    }
}
