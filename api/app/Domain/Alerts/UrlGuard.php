<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The address guard for outbound webhooks (plan P5-i1 D6; T03 calls it at registration, every send calls it again).
 *
 * A URL passes when: its scheme is https (http only for an exact allow-listed host:port); it has no user-info and
 * no fragment; it is at most 2048 printable ASCII bytes; its host is a plain DNS name or a canonical IP literal
 * (decimal, octal, hex and short IPv4 forms are refused unseen, so curl can never read one as loopback); and the
 * literal, or EVERY address the host resolves to, lies outside every block in BLOCKS (IPv4 embedded in a mapped or
 * compatible IPv6 address is checked as IPv4 too). Any other IPv6 address must also be global unicast (2000::/3)
 * outside the IETF protocol assignments (2001::/23), so every IPv6 range that embeds IPv4 in some other way is refused
 * whole. An allow-listed host:port (config alerts.webhooks.private_allow) skips resolution and the block check. The
 * sender pins the approved address (CURLOPT_RESOLVE) and never follows a redirect, so the address checked is the
 * address used.
 */
final class UrlGuard
{
    /**
     * Blocked ranges, written abbreviated (missing IPv4 octets are zero): this-network, private, CGNAT (Tailscale
     * included), loopback, link-local, IETF protocol assignments, benchmarking, multicast, reserved; the IPv6
     * unspecified, loopback, IPv4-mapped, NAT64, 6to4, unique-local (Tailscale's ULA included), link-local and
     * multicast ranges. The RFC 5737 documentation ranges are deliberately not blocked. IPv6 outside these blocks is
     * further held to GLOBAL_UNICAST minus IETF_ASSIGNMENTS, which still lets 2001:db8::/32 and 3fff::/20 through.
     */
    public const array BLOCKS = [
        '0/8', '10/8', '100.64/10', '127/8', '169.254/16', '172.16/12', '192.0.0/24', '192.168/16', '198.18/15', '224/4', '240/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** IPv6 global unicast: an IPv6 address that is not IPv4-mapped or compatible must lie inside it. */
    public const string GLOBAL_UNICAST = '2000::/3';

    /** IPv6 IETF protocol assignments (Teredo, benchmarking, ORCHID among them): refused even inside GLOBAL_UNICAST. */
    public const string IETF_ASSIGNMENTS = '2001::/23';

    public const int MAX_BYTES = 2048;

    private const string URL = '~^(?<scheme>[A-Za-z][A-Za-z0-9+.-]*)://(?<authority>[^/?#]*)(?<rest>[/?][^#]*)?(?<fragment>#.*)?$~D';

    private const string LABEL = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';

    public function __construct(private readonly HostResolver $resolver) {}

    public function check(#[\SensitiveParameter] string $url): GuardResult
    {
        if (strlen($url) > self::MAX_BYTES) {
            return GuardResult::refused(GuardError::TooLong);
        }
        if (preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1 || preg_match(self::URL, $url, $m) !== 1) {
            return GuardResult::refused(GuardError::Invalid);
        }
        $scheme = strtolower($m['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return GuardResult::refused(GuardError::Scheme);
        }
        if (isset($m['fragment'])) {
            return GuardResult::refused(GuardError::Invalid);
        }
        $authority = $m['authority'];
        if ($authority === '' || str_contains($authority, '@')) {
            return GuardResult::refused(GuardError::Invalid);
        }
        if (preg_match('~^(?<host>\[[^\]]*\]|[^:\[\]]+)(?::(?<port>[0-9]{1,5}))?$~D', $authority, $a) !== 1) {
            return GuardResult::refused(GuardError::Invalid);
        }
        $host = strtolower($a['host']);
        $port = isset($a['port']) ? (int) $a['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return GuardResult::refused(GuardError::Invalid);
        }

        if (in_array("{$host}:{$port}", self::allowList(), true)) {
            return new GuardResult(null, $host, $port, null);
        }
        if ($scheme !== 'https') {
            return GuardResult::refused(GuardError::Scheme);
        }

        $literal = self::literal($host);
        if ($literal === false) {
            return GuardResult::refused(GuardError::Invalid);
        }
        if ($literal === 'refused') {
            return GuardResult::refused(GuardError::BlockedAddress);
        }
        if ($literal !== null) {
            return self::blocked($literal) ? GuardResult::refused(GuardError::BlockedAddress) : new GuardResult(null, $host, $port, $literal);
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return GuardResult::refused(GuardError::Unresolvable);
        }
        foreach ($addresses as $address) {
            if (inet_pton($address) === false || self::blocked($address)) {
                return GuardResult::refused(GuardError::BlockedAddress);
            }
        }

        return new GuardResult(null, $host, $port, $addresses[0]);
    }

    /** Whether an address (IPv4 or IPv6 text) lies in a blocked range. Unparseable text counts as blocked. */
    public static function blocked(string $address): bool
    {
        $bytes = @inet_pton($address);
        if ($bytes === false) {
            return true;
        }
        foreach (self::ranges() as [$network, $prefix]) {
            if (strlen($network) === strlen($bytes) && self::inRange($bytes, $network, $prefix)) {
                return true;
            }
        }
        // IPv4 carried in an IPv4-mapped (::ffff:a.b.c.d) or IPv4-compatible (::a.b.c.d) IPv6 address.
        if (strlen($bytes) === 16 && (str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff") || str_starts_with($bytes, str_repeat("\0", 12)))) {
            $v4 = inet_ntop(substr($bytes, 12));

            return $v4 === false || self::blocked($v4);
        }
        if (strlen($bytes) === 16) {
            return ! self::inBlock($bytes, self::GLOBAL_UNICAST) || self::inBlock($bytes, self::IETF_ASSIGNMENTS);
        }

        return false;
    }

    private static function inBlock(string $bytes, string $block): bool
    {
        [$network, $prefix] = explode('/', $block);

        return self::inRange($bytes, (string) inet_pton($network), (int) $prefix);
    }

    /**
     * Classifies the host: null for a DNS name, the canonical address text for an IP literal, 'refused' for a host
     * that looks like an IPv4 number in a non-canonical form, false for anything else.
     */
    private static function literal(string $host): string|false|null
    {
        if (str_starts_with($host, '[')) {
            $inner = substr($host, 1, -1);

            return str_contains($inner, ':') && filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $inner : false;
        }
        if (strlen($host) > 253 || preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/D', $host) !== 1) {
            // A trailing dot, an empty label or anything that is not a plain name.
            return preg_match('/^[0-9a-fx.]+$/D', $host) === 1 && str_ends_with($host, '.') ? 'refused' : false;
        }
        $labels = explode('.', $host);
        $last = end($labels);
        if (preg_match('/^(?:[0-9]+|0x[0-9a-f]*)$/D', $last) !== 1) {
            return null;
        }

        // The last label is a number: an IPv4 literal, accepted only as four canonical decimal octets.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $host : 'refused';
    }

    /** @return list<string> */
    private static function allowList(): array
    {
        $configured = config('alerts.webhooks.private_allow', []);

        return is_array($configured) ? array_values(array_map(fn (mixed $p): string => strtolower(trim((string) $p)), $configured)) : [];
    }

    /** @return list<array{0: string, 1: int}> */
    private static function ranges(): array
    {
        static $ranges = null;
        if ($ranges === null) {
            $ranges = [];
            foreach (self::BLOCKS as $block) {
                [$network, $prefix] = explode('/', $block);
                if (! str_contains($network, ':')) {
                    $network = implode('.', array_pad(explode('.', $network), 4, '0'));
                }
                $ranges[] = [(string) inet_pton($network), (int) $prefix];
            }
        }

        return $ranges;
    }

    private static function inRange(string $bytes, string $network, int $prefix): bool
    {
        $whole = intdiv($prefix, 8);
        if (substr($bytes, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($bytes[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }
}
