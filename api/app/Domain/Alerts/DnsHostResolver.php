<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/** The system resolver's A and AAAA records (dns_get_record), for the address guard (plan P5-i1 D6). */
final class DnsHostResolver extends HostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
