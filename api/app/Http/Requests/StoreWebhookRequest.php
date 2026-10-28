<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Alerts\WebhookEndpoints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @ignoreSchema
 */
final class StoreWebhookRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', Rule::in(WebhookEndpoints::KINDS)],
            'url' => ['bail', 'required', 'string'],
            'description' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (! mb_check_encoding((string) $value, 'UTF-8')) {
                    $fail('A description must be UTF-8 text.');
                }
            }, 'min:1', 'max:100', 'not_regex:/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.not_regex' => 'A description cannot hold control or text-direction characters.',
            'kind.required' => WebhookEndpoints::KIND,
            'kind.string' => WebhookEndpoints::KIND,
            'kind.in' => WebhookEndpoints::KIND,
        ];
    }

    /** standard or discord. */
    public function kind(): string
    {
        return (string) $this->validated('kind');
    }

    public function webhookUrl(): string
    {
        return (string) $this->validated('url');
    }

    /** The description, or null when none was sent. */
    public function description(): ?string
    {
        $description = $this->validated('description');

        return $description === null ? null : (string) $description;
    }
}
