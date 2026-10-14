<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use Illuminate\Container\Attributes\Bind;

/**
 * Resolves a host name to its A and AAAA addresses for the address guard (plan P5-i1 D6). The one non-final type in
 * App\Domain\Alerts (the arch rule's named exception), so tests can answer DNS themselves; the container binds
 * DnsHostResolver. An abstract class rather than an interface: the arch rule's toBeClasses() check reaches every
 * type in the namespace, while its ignoring() reaches only toBeFinal().
 */
#[Bind(DnsHostResolver::class)]
abstract class HostResolver
{
    /** @return list<string> the host's IPv4 and IPv6 addresses as text; empty when it has none */
    abstract public function resolve(string $host): array;
}
