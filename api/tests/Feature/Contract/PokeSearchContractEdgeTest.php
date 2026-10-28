<?php

declare(strict_types=1);

use App\Domain\Catalog\PokeSearchClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Opis\JsonSchema\Validator;

// Reviewer edge tests for T51: the contract validator must reject bad documents (a validator that passes everything
// is the classic C4 hole), the denominator must come from the real directory listing, and the fixtures must stay
// the raw bytes of a curl -s recording.

pest()->group('contract');

const T51E_DIR = __DIR__.'/../../Fixtures/pokesearch';

const T51E_URI = 'https://pokesearch.example/schema/';

/** @return array<mixed> */
function t51eJson(string $relative): array
{
    return json_decode((string) file_get_contents(T51E_DIR.'/'.$relative), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, array{request: string, status: int, schema: string}> */
function t51eRecordings(): array
{
    return t51eJson('RECORDINGS.json')['fixtures'];
}

function t51eValid(array $document, string $schema): bool
{
    $validator = new Validator;
    $validator->resolver()->registerPrefix(T51E_URI, T51E_DIR.'/schema/');

    return $validator->validate(json_decode(json_encode($document, JSON_THROW_ON_ERROR)), T51E_URI.$schema)->isValid();
}

/** Every fixture file on disk: every .json under the fixture dir except schema/ and the two manifests. */
function t51eFixturesOnDisk(): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(T51E_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr((string) $file->getPathname(), strlen(T51E_DIR) + 1);
        if (str_ends_with($relative, '.json') && ! str_starts_with($relative, 'schema/') && ! in_array($relative, ['RECORDINGS.json', 'SCHEMA_SOURCE.json'], true)) {
            $found[] = $relative;
        }
    }
    sort($found);

    return $found;
}

it('has exactly the fixtures RECORDINGS.json lists on disk: no unvalidated stray file, no listed ghost', function (): void {
    $listed = array_keys(t51eRecordings());
    sort($listed);

    expect(t51eFixturesOnDisk())->toBe($listed)
        ->and(count(t51eFixturesOnDisk()))->toBeGreaterThanOrEqual(8);
});

it('rejects each fixture with any one of its schema required keys removed', function (): void {
    $rejected = 0;
    foreach (t51eRecordings() as $fixture => $recording) {
        $schema = t51eJson('schema/'.$recording['schema']);
        $document = t51eJson($fixture);
        expect(t51eValid($document, $recording['schema']))->toBeTrue("{$fixture} should start valid");
        expect($schema['required'])->not->toBe([]);
        foreach ($schema['required'] as $key) {
            $mutated = $document;
            unset($mutated[$key]);
            expect(t51eValid($mutated, $recording['schema']))->toBeFalse("{$fixture} without {$key} must be rejected by {$recording['schema']}");
            $rejected++;
        }
        $extra = $document;
        $extra['unexpected_member'] = true;
        expect(t51eValid($extra, $recording['schema']))->toBeFalse("{$fixture} with an unknown top-level member must be rejected");
    }

    expect($rejected)->toBeGreaterThanOrEqual(20);
});

it('rejects a wrong type inside a card reached through a $ref in the card, batch and search documents', function (): void {
    $card = t51eJson('cards/base1-4.json');
    $card['data']['set_total'] = '102';

    $batch = t51eJson('batch/mixed-missing.json');
    $batch['data'][1]['release_date'] = '1999/01/09';

    $search = t51eJson('search/charizard-p1.json');
    $search['results'][0]['hp'] = 'one hundred';

    $nested = t51eJson('cards/base1-4.json');
    $nested['data']['attacks'][0]['converted_cost'] = -1;

    expect(t51eValid($card, 'card-response.json'))->toBeFalse()
        ->and(t51eValid($batch, 'cards-batch-response.json'))->toBeFalse()
        ->and(t51eValid($search, 'search.json'))->toBeFalse()
        ->and(t51eValid($nested, 'card-response.json'))->toBeFalse();
});

it('rejects a bad seed through the cross-file $ref to card-response.json#/$defs/seed in the batch and sets documents', function (): void {
    $batch = t51eJson('batch/mixed-missing.json');
    $batch['seed']['ref'] = 12345;
    $sets = t51eJson('sets.json');
    unset($sets['seed']['seeded_at']);
    $setRow = t51eJson('sets.json');
    $setRow['data'][0]['card_count'] = 0;
    $meta = t51eJson('meta.json');
    $meta['docs'] = '20635';

    expect(t51eValid($batch, 'cards-batch-response.json'))->toBeFalse()
        ->and(t51eValid($sets, 'sets-response.json'))->toBeFalse()
        ->and(t51eValid($setRow, 'sets-response.json'))->toBeFalse()
        ->and(t51eValid($meta, 'meta.json'))->toBeFalse();
});

it('throws on a schema it cannot resolve instead of passing the document', function (): void {
    $validator = new Validator;
    $validator->resolver()->registerPrefix(T51E_URI, T51E_DIR.'/schema/');
    $thrown = false;
    try {
        $result = $validator->validate((object) [], T51E_URI.'does-not-exist.json');
        $thrown = ! $result->isValid();
    } catch (Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('validates in draft 2020-12: prefixItems and unevaluatedProperties are enforced, not ignored', function (): void {
    $validator = new Validator;
    $tuple = (object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'prefixItems' => [(object) ['type' => 'string']], 'items' => false];
    $unevaluated = (object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'properties' => (object) ['a' => (object) []], 'unevaluatedProperties' => false];

    expect($validator->validate([1], $tuple)->isValid())->toBeFalse()
        ->and($validator->validate(['x'], $tuple)->isValid())->toBeTrue()
        ->and($validator->validate((object) ['a' => 1, 'b' => 2], $unevaluated)->isValid())->toBeFalse()
        ->and($validator->validate((object) ['a' => 1], $unevaluated)->isValid())->toBeTrue();
});

it('stores every fixture as the raw single-line body a curl -s recording yields: no pretty printing, valid UTF-8 JSON', function (): void {
    foreach (t51eFixturesOnDisk() as $fixture) {
        $raw = (string) file_get_contents(T51E_DIR.'/'.$fixture);
        $body = rtrim($raw, "\n");

        expect($body)->not->toContain("\n", "{$fixture} was reformatted")
            ->and($body)->not->toBe('')
            ->and(mb_check_encoding($raw, 'UTF-8'))->toBeTrue()
            ->and(json_validate($raw))->toBeTrue()
            ->and($body)->not->toStartWith(' ');
    }
});

it('keeps hygiene: no home path, host name or address beyond RFC 5737 and the dev ports in any tracked fixture', function (): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(T51E_DIR, FilesystemIterator::SKIP_DOTS));
    $seen = 0;
    foreach ($iterator as $file) {
        $content = (string) file_get_contents((string) $file->getPathname());
        $seen++;
        expect(preg_match('#/home/|/Users/|\.home\b|\.ts\.net|\.local\b#', $content))->toBe(0, $file->getFilename().' carries a host or home path');
        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $content, $addresses);
        foreach ($addresses[0] as $address) {
            expect($address)->toMatch('/\A(?:127\.0\.0\.1|192\.0\.2\.\d+|198\.51\.100\.\d+|203\.0\.113\.\d+)\z/');
        }
        if (! str_contains((string) $file->getPathname(), '/schema/')) {
            expect(preg_match('/x-api-key|api_key|bearer\s|authorization/i', $content))->toBe(0, $file->getFilename().' mentions a credential word');
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(17);
});

it('fails loudly on a request no fixture recorded rather than answering empty', function (): void {
    config(['services.pokesearch.url' => 'http://192.0.2.10:8081', 'services.pokesearch.api_key' => null]);
    Http::fake(function (ClientRequest $request) {
        $recorded = array_map(fn (array $r): string => $r['request'], array_values(t51eRecordings()));
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        foreach ($recorded as $r) {
            if (rawurldecode((string) parse_url($r, PHP_URL_PATH)) === rawurldecode($path)) {
                return Http::response('{}', 200);
            }
        }
        throw new RuntimeException('unrecorded '.$path);
    });
    $client = new PokeSearchClient;

    $thrown = null;
    try {
        $client->card('zzz-unrecorded-9');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(Throwable::class);
});
