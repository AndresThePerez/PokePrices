<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\NullQueue;
use Tests\TestCase;

// Every queued job's runtime policy (plan Q1-i1 T02, review b B2). The classes are discovered by scanning app/ for
// ShouldQueue implementers, never listed (R255), so a new job without a timeout or tries policy fails here. The
// policy is read the way the queue reads it: from the payload Laravel builds when the job is pushed.

uses(TestCase::class);

/** The worker's --tries in compose.dev.yml (pinned by tests/p0/T18-edge.sh), the tries of a job declaring none. */
const Q1T02_WORKER_TRIES = 3;

/** @return array<string, array{0: class-string}> every concrete ShouldQueue class under app/ */
function q1t02QueuedClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file->getFilename(), '.php')) {
            continue;
        }
        $source = (string) file_get_contents((string) $file->getPathname());
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
            || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls) !== 1) {
            continue;
        }
        $class = $ns[1].'\\'.$cls[1];
        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[$cls[1]] = [$class];
        }
    }
    ksort($classes);

    return $classes;
}

/** The payload the queue builds for $class (constructor skipped: the policy lives on the class). */
function q1t02Payload(string $class): array
{
    $queue = new class extends NullQueue
    {
        public function payloadFor(object $job): array
        {
            return $this->createObjectPayload($job, 'default');
        }
    };
    $queue->setContainer(app());

    return $queue->payloadFor((new ReflectionClass($class))->newInstanceWithoutConstructor());
}

test('the scan finds the queued job classes under app/', function (): void {
    expect(q1t02QueuedClasses())->not->toBe([]);
});

test('a queued job declares a timeout below the redis queue retry_after', function (string $class): void {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $timeout = q1t02Payload($class)['timeout'];

    expect($retryAfter)->toBeGreaterThan(0)
        ->and($timeout)->toBeInt("{$class} declares no timeout")
        ->and($timeout)->toBeGreaterThan(0)
        ->and($timeout)->toBeLessThan($retryAfter, "{$class} timeout {$timeout} is not below retry_after {$retryAfter}");
})->with(q1t02QueuedClasses());

test('a queued job keeps three tries: its own or the worker\'s, or retryUntil with three real exceptions', function (string $class): void {
    $payload = q1t02Payload($class);

    if ($payload['retryUntil'] !== null) {
        // Contention releases until the deadline; three real exceptions (timeouts count) still fail the job.
        expect($payload['maxExceptions'])->toBe(Q1T02_WORKER_TRIES, "{$class} retries until a deadline without a three-exception budget")
            ->and($payload['retryUntil'])->toBeGreaterThan(time());
    } else {
        expect($payload['maxTries'] ?? Q1T02_WORKER_TRIES)->toBe(Q1T02_WORKER_TRIES);
    }
})->with(q1t02QueuedClasses());
