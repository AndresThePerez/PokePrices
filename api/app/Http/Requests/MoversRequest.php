<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Pricing\PricingConfig;
use App\Http\QueryParameters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * limit stops at the rows price_movers keeps per day and direction (PricingConfig::moversLimit()). direction and limit
 * are the only query parameters accepted: anything else (a day, which the route never read) is a 422.
 */
final class MoversRequest extends FormRequest
{
    /** The only query parameters accepted. */
    private const array PARAMETERS = ['direction', 'limit'];

    /**
     * Refuses an unknown query parameter before the rules run, as the card search does.
     *
     * @throws ValidationException 422 errors.<name> for every query parameter outside PARAMETERS
     */
    protected function prepareForValidation(): void
    {
        QueryParameters::only($this, self::PARAMETERS);
    }

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
