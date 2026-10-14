<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Alerts\HostResolver;

/**
 * A DNS stand-in for the address guard tests (plan P5-i1 T02, D6): each host answers the addresses it was given,
 * any other host none. install() binds it in the container, so every UrlGuard the app builds uses it.
 */
final class FakeHostResolver extends HostResolver
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers = []) {}

    /** @param array<string, list<string>> $answers */
    public static function install(array $answers): self
    {
        $resolver = new self($answers);
        app()->instance(HostResolver::class, $resolver);

        return $resolver;
    }

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}
