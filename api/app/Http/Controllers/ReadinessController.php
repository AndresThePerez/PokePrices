<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Problems\ProblemDetails;
use App\Support\Readiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadinessController
{
    /**
     * Readiness of the PokéPrices API and the services it needs.
     *
     * @operationId getReady
     */
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
