<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Alerts\AlertThresholds;
use App\Domain\Pricing\MovementRule;
use App\Http\Rules\ValidVariantKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @ignoreSchema
 */
final class PutAlertRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'pct' => ['bail', 'required', 'integer:strict', 'min:'.MovementRule::alertFloor(), 'max:'.AlertThresholds::MAX],
        ];
    }

    /**
     * The floor comes from the movement rule; every refusal of pct shows the same range.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = 'Choose a whole percentage from '.MovementRule::alertFloor().' to '.AlertThresholds::MAX.'.';

        return [
            'pct.required' => $message,
            'pct.integer' => $message,
            'pct.min' => $message,
            'pct.max' => $message,
        ];
    }

    /** The validated threshold, a whole percentage from the movement rule's floor to 100. */
    public function pct(): int
    {
        return (int) $this->validated('pct');
    }

    /** The key's shape is checked before any query and before the body. */
    protected function prepareForValidation(): void
    {
        ValidVariantKey::check($this->route('variantKey'));
    }
}
