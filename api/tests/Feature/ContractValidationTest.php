<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ContractValidator;

function contractValidator(): ContractValidator
{
    return ContractValidator::forSpec(base_path('../openapi/api.json'));
}

it('accepts the real readiness response', function (): void {
    $response = $this->get('/v1/ready');
    $result = contractValidator()->validate('GET', '/v1/ready', $response->baseResponse);
    expect($result['validated'])->toBeTrue()->and($result['operation'])->not->toBeNull();
});

it('rejects a body with an undocumented property', function (): void {
    $bad = new Response('{"status":"ok","checks":{"database":"ok","redis_queue":"ok","redis_cache":"ok"},"extra":1}', 200, ['Content-Type' => 'application/json']);
    expect(contractValidator()->validate('GET', '/v1/ready', $bad)['validated'])->toBeFalse();
});

it('rejects an undocumented status code', function (): void {
    $bad = new Response('{"type":"x","title":"t","status":418}', 418, ['Content-Type' => 'application/problem+json']);
    expect(contractValidator()->validate('GET', '/v1/ready', $bad)['validated'])->toBeFalse();
});

it('rejects an error response that is not problem+json', function (): void {
    $bad = new Response('{"message":"down"}', 503, ['Content-Type' => 'application/json']);
    expect(contractValidator()->validate('GET', '/v1/ready', $bad)['validated'])->toBeFalse();
});

it('accepts a problem+json 404 for a path the contract does not have', function (): void {
    $response = $this->get('/v1/nowhere');
    $result = contractValidator()->validate('GET', '/v1/nowhere', $response->baseResponse);
    expect($result['validated'])->toBeTrue()->and($result['reason'])->toBe('unmatched_path_problem');
});
