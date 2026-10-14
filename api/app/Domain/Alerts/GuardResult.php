<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The address guard's answer for one URL (plan P5-i1 D6). error is null when the URL may be requested, else one of
 * scheme (not https, and not an allow-listed http host:port), invalid (malformed, user-info, a fragment, a host
 * that is not a plain name or a canonical address), too_long (over 2048 bytes), blocked_address, unresolvable.
 * address is the approved address to pin, or null for an allow-listed host:port (which is never resolved).
 */
final readonly class GuardResult
{
    public function __construct(
        public ?string $error,
        public string $host = '',
        public int $port = 0,
        public ?string $address = null,
    ) {}

    public static function refused(string $error): self
    {
        return new self($error);
    }

    public function allowed(): bool
    {
        return $this->error === null;
    }

    /**
     * The CURLOPT_RESOLVE entries that pin the request to the approved address (none for an IP literal or an
     * allow-listed host:port).
     *
     * @return list<string>
     */
    public function curlResolve(): array
    {
        if ($this->error !== null || $this->address === null || filter_var(trim($this->host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        $address = str_contains($this->address, ':') ? '['.$this->address.']' : $this->address;

        return ["{$this->host}:{$this->port}:{$address}"];
    }
}
