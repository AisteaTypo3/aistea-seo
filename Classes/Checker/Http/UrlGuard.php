<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker\Http;

/**
 * Validates outgoing URLs against SSRF: only public http(s) targets on common ports.
 * Every request (including each redirect hop) must pass through validate() and the
 * returned IP is pinned for the connection, so DNS rebinding cannot swap the target.
 */
final class UrlGuard
{
    private const ALLOWED_PORTS = [80, 443, 8080, 8443];

    /** Ranges not covered by PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE. */
    private const EXTRA_BLOCKED_RANGES = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
        '2001:db8::/32',
        'ff00::/8',
    ];

    /** @var array<string, array{host: string, port: int, scheme: string, ip: string}> */
    private array $cache = [];

    /**
     * @return array{host: string, port: int, scheme: string, ip: string}
     * @throws BlockedTargetException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new BlockedTargetException('invalidUrl');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedTargetException('invalidScheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedTargetException('credentials');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new BlockedTargetException('port');
        }

        $cacheKey = $scheme . '://' . $host . ':' . $port;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.lan') || str_ends_with($host, '.home.arpa')
        ) {
            throw new BlockedTargetException('privateTarget');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if (!str_contains($host, '.') || !preg_match('/^[a-z0-9.-]+$/', $host)) {
                throw new BlockedTargetException('invalidHost');
            }
            $ips = $this->resolve($host);
            if ($ips === []) {
                throw new BlockedTargetException('dns');
            }
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new BlockedTargetException('privateTarget');
            }
        }

        // Prefer IPv4 for broad hosting compatibility.
        usort($ips, static fn(string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return $this->cache[$cacheKey] = ['host' => $host, 'port' => $port, 'scheme' => $scheme, 'ip' => $ips[0]];
    }

    public function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) must be checked as IPv4.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return $this->isBlockedIp($m[1]);
        }

        foreach (self::EXTRA_BLOCKED_RANGES as $range) {
            if ($this->inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
