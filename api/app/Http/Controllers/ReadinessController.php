<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Problems\ProblemDetails;
use App\Support\Readiness;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Readiness', 'Whether the PokéPrices API can serve requests.')]
final class ReadinessController
{
    /**
     * Readiness of the PokéPrices API and the services it needs.
     *
     * Probes Postgres and both Redis roles and reports each check as ok or fail. Every check ok answers 200; any failed check answers 503 problem+json carrying the same checks map.
     *
     * @operationId getReady
     */
    #[Response(200, description: 'Every dependency answered.')]
    #[Response(503, description: 'At least one dependency did not answer.', mediaType: 'application/problem+json')]
    public function __invoke(Request $request, Readiness $readiness): JsonResponse
    {
        $checks = $readiness->run();
        if (in_array('fail', $checks, true)) {
            return ProblemDetails::response(503, 'not-ready', 'Service Not Ready', $request, 'One or more dependencies did not answer.', ['checks' => $checks])
                ->header('Cache-Control', 'no-store');
        }

        return (new JsonResponse(['status' => 'ok', 'checks' => $checks]))->header('Cache-Control', 'no-store');
    }
}
