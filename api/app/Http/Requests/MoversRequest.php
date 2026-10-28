<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Pricing\PricingConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * limit stops at the rows price_movers keeps per day and direction (PricingConfig::moversLimit(), D9).
 */
final class MoversRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', 'required', 'string', Rule::in(['up', 'down'])],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PricingConfig::moversLimit()],
        ];
    }

    /** up or down; up when the query names none. */
    public function direction(): string
    {
        $direction = $this->validated('direction');

        return $direction === null ? 'up' : (string) $direction;
    }

    /** 1 to PricingConfig::moversLimit(); PricingConfig::defaultMoversLimit() when the query names none. */
    public function limit(): int
    {
        $limit = $this->validated('limit');

        return $limit === null ? PricingConfig::defaultMoversLimit() : (int) $limit;
    }
}
