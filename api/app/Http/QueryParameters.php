<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The query parameters a public read accepts. A parameter the route does not take is refused, never ignored and never
 * forwarded: the 422 problem names each one under errors.
 */
final class QueryParameters
{
    /** The 422 message under each refused parameter. */
    public const string REFUSED = 'This query parameter is not accepted.';

    /**
     * @param  list<string>  $accepted
     *
     * @throws ValidationException 422 errors.<name> for every query parameter outside $accepted
     */
    public static function only(Request $request, array $accepted): void
    {
        $unknown = array_diff(array_map('strval', array_keys($request->query())), $accepted);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, self::REFUSED));
        }
    }
}
