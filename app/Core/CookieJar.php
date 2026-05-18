<?php
/**
 * MSHW-proxy - RFC 6265 Compliant Server-Side CookieJar
 * Dashboard-editable, thread-safe, ephemeral-ready
 */

declare(strict_types=1);

namespace MSHW\Proxy\Core;

use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\Cookie;

class CookieJar
{
    /**
     * In-memory storage: [sessionId => [domainHash => [path => [name => CookieData]]]]
     */
    private static array $store = [];

    /**
     * Current session identifier (isolated per user/request chain)
     */
    private string $sessionId;

    public function __construct(string $sessionId = null)
    {
        $this->sessionId = $sessionId ?? bin2hex(random_bytes(16));
        if (!isset(self::$store[$this->sessionId])) {
            self::$store[$this->sessionId] = [];
        }
    }

    /**
     * Parse and store a Set-Cookie header (RFC 6265 compliant)
     */
    public function setCookie(string $header, UriInterface $requestUri): void
    {
        $cookie = $this->parseSetCookie($header, $requestUri);
        if (!$cookie) return;

        $domain = $this->normalizeDomain($cookie['domain'], $requestUri->getHost());
        $path = $cookie['path'] ?? '/';
        $name = $cookie['name'];

        // Strict domain matching (configurable)
        if (config('cookies.strict_domain_match', true)) {
            if (!$this->domainMatches($domain, $requestUri->getHost())) {
                return; // reject cookie for mismatched domain
            }
        }

        // Path matching
        if (!$this->pathMatches($path, $requestUri->getPath())) {
            return;
        }

        // Store with structured key
        $domainHash = hash('xxh64', $domain);
        self::$store[$this->sessionId][$domainHash][$path][$name] = [
            'name' => $name,
            'value' => $cookie['value'],
            'domain' => $domain,
            'path' => $path,
            'expires' => $cookie['expires'] ?? null,
            'secure' => $cookie['secure'] ?? false,
            'httpOnly' => $cookie['httpOnly'] ?? false,
            'sameSite' => $cookie['sameSite'] ?? 'Lax',
            'raw' => $header,
            'created_at' => time(),
        ];

        // Enforce max cookies per domain (prevent memory bloat)
        $this->enforceLimit($domainHash, (int) config('cookies.max_per_domain', 50));
    }

    /**
     * Get applicable cookies for a request URI (for Cookie header injection)
     *
     * @return string e.g., "name1=value1; name2=value2"
     */
    public function getCookieHeader(UriInterface $uri): string
    {
        $host = $uri->getHost();
        $path = $uri->getPath() ?: '/';
        $isSecure = $uri->getScheme() === 'https';
        $cookies = [];

        foreach (self::$store[$this->sessionId] ?? [] as $domainHash => $paths) {
            foreach ($paths as $cookiePath => $cookieList) {
                foreach ($cookieList as $name => $data) {
                    // Check expiration
                    if ($data['expires'] && $data['expires'] < time()) {
                        unset(self::$store[$this->sessionId][$domainHash][$cookiePath][$name]);
                        continue;
                    }
                    // Check secure flag
                    if ($data['secure'] && !$isSecure) continue;
                    // Domain & path matching
                    if ($this->domainMatches($data['domain'], $host) && $this->pathMatches($cookiePath, $path)) {
                        $cookies[] = "{$name}={$data['value']}";
                    }
                }
            }
        }

        return implode('; ', $cookies);
    }

    /**
     * Dashboard API: List all cookies (grouped by domain)
     */
    public function listAll(): array
    {
        $result = [];
        foreach (self::$store[$this->sessionId] ?? [] as $domainHash => $paths) {
            foreach ($paths as $cookiePath => $cookieList) {
                foreach ($cookieList as $name => $data) {
                    $key = "{$data['domain']}{$cookiePath}";
                    $result[$key] ??= [
                        'domain' => $data['domain'],
                        'path' => $cookiePath,
                        'cookies' => []
                    ];
                    $result[$key]['cookies'][$name] = [
                        'value' => $data['value'],
                        'expires' => $data['expires'],
                        'secure' => $data['secure'],
                        'httpOnly' => $data['httpOnly'],
                        'sameSite' => $data['sameSite'],
                        'created_at' => $data['created_at'],
                    ];
                }
            }
        }
        return array_values($result);
    }

    /**
     * Dashboard API: Update a cookie by identifier
     */
    public function update(string $domain, string $path, string $name, array $updates): bool
    {
        $domainHash = hash('xxh64', $domain);
        if (!isset(self::$store[$this->sessionId][$domainHash][$path][$name])) {
            return false;
        }

        // Validation layer (prevent invalid data)
        $allowed = ['value', 'expires', 'secure', 'httpOnly', 'sameSite'];
        foreach ($updates as $key => $val) {
            if (!in_array($key, $allowed)) continue;
            if ($key === 'expires' && $val !== null && !is_numeric($val)) continue;
            if ($key === 'sameSite' && !in_array($val, ['Strict', 'Lax', 'None', null])) continue;
            if (in_array($key, ['secure', 'httpOnly']) && !is_bool($val)) continue;
            
            self::$store[$this->sessionId][$domainHash][$path][$name][$key] = $val;
        }
        return true;
    }

    /**
     * Dashboard API: Delete a cookie
     */
    public function delete(string $domain, string $path, string $name): bool
    {
        $domainHash = hash('xxh64', $domain);
        if (!isset(self::$store[$this->sessionId][$domainHash][$path][$name])) {
            return false;
        }
        unset(self::$store[$this->sessionId][$domainHash][$path][$name]);
        // Cleanup empty levels
        if (empty(self::$store[$this->sessionId][$domainHash][$path])) {
            unset(self::$store[$this->sessionId][$domainHash][$path]);
        }
        if (empty(self::$store[$this->sessionId][$domainHash])) {
            unset(self::$store[$this->sessionId][$domainHash]);
        }
        return true;
    }

    /**
     * Dashboard API: Import raw Set-Cookie header
     */
    public function importRaw(string $rawHeader, string $fallbackDomain, string $fallbackPath = '/'): bool
    {
        // Minimal URI for parsing context
        $fakeUri = new class($fallbackDomain, $fallbackPath) implements UriInterface {
            private string $host, $path;
            public function __construct(string $host, string $path) {
                $this->host = $host; $this->path = $path;
            }
            public function getHost(): string { return $this->host; }
            public function getPath(): string { return $this->path; }
            public function getScheme(): string { return 'https'; }
            // Other methods not needed for cookie parsing
            public function getAuthority() { return ''; }
            public function getUserInfo() { return ''; }
            public function getPort() { return null; }
            public function getQuery() { return ''; }
            public function getFragment() { return ''; }
            public function withScheme($scheme) { return $this; }
            public function withUserInfo($user, $password = null) { return $this; }
            public function withHost($host) { return $this; }
            public function withPort($port) { return $this; }
            public function withPath($path) { return $this; }
            public function withQuery($query) { return $this; }
            public function withFragment($fragment) { return $this; }
            public function __toString() { return ''; }
        };
        $this->setCookie($rawHeader, $fakeUri);
        return true;
    }

    /**
     * Clear all cookies for a domain
     */
    public function clearDomain(string $domain): void
    {
        $domainHash = hash('xxh64', $domain);
        unset(self::$store[$this->sessionId][$domainHash]);
    }

    /**
     * Clear all cookies (session end)
     */
    public function clearAll(): void
    {
        unset(self::$store[$this->sessionId]);
    }

    // ==================== Internal Helpers ====================

    private function parseSetCookie(string $header, UriInterface $uri): ?array
    {
        // Simple RFC 6265 parser (production: use symfony/http-foundation Cookie::fromString)
        $parts = array_map('trim', explode(';', $header));
        if (empty($parts[0]) || !str_contains($parts[0], '=')) return null;

        [$name, $value] = explode('=', array_shift($parts), 2) + [1 => ''];
        $cookie = [
            'name' => trim($name),
            'value' => trim($value, " \t\n\r\0\x0B\""),
            'domain' => $uri->getHost(),
            'path' => '/',
            'secure' => false,
            'httpOnly' => false,
            'sameSite' => 'Lax',
        ];

        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                $key = strtolower(trim($part));
                $val = true;
            } else {
                [$key, $val] = explode('=', $part, 2);
                $key = strtolower(trim($key));
                $val = trim($val, " \t\n\r\0\x0B\"");
            }

            match ($key) {
                'expires' => $cookie['expires'] = strtotime($val) ?: null,
                'max-age' => $cookie['expires'] = time() + (int) $val,
                'domain' => $cookie['domain'] = ltrim($val, '.'),
                'path' => $cookie['path'] = $val,
                'secure' => $cookie['secure'] = true,
                'httponly' => $cookie['httpOnly'] = true,
                'samesite' => $cookie['sameSite'] = ucfirst(strtolower($val)),
                default => null,
            };
        }

        return $cookie;
    }

    private function normalizeDomain(string $cookieDomain, string $requestHost): string
    {
        $cookieDomain = ltrim($cookieDomain, '.');
        // If cookie domain is not a suffix of request host, fallback to request host
        if (!str_ends_with($requestHost, $cookieDomain) && $cookieDomain !== $requestHost) {
            return $requestHost;
        }
        return $cookieDomain;
    }

    private function domainMatches(string $cookieDomain, string $requestHost): bool
    {
        if ($cookieDomain === $requestHost) return true;
        // Subdomain match: .example.com matches api.example.com
        if (str_starts_with($cookieDomain, '.')) {
            return str_ends_with($requestHost, $cookieDomain);
        }
        return false;
    }

    private function pathMatches(string $cookiePath, string $requestPath): bool
    {
        if ($cookiePath === '/') return true;
        if ($cookiePath === $requestPath) return true;
        if (str_starts_with($requestPath, $cookiePath . '/')) return true;
        return false;
    }

    private function enforceLimit(string $domainHash, int $max): void
    {
        $paths = self::$store[$this->sessionId][$domainHash] ?? [];
        $total = array_sum(array_map('count', $paths));
        if ($total <= $max) return;

        // Remove oldest cookies first (by created_at)
        $all = [];
        foreach ($paths as $p => $list) {
            foreach ($list as $n => $data) {
                $all[] = ['path' => $p, 'name' => $n, 'time' => $data['created_at']];
            }
        }
        usort($all, fn($a, $b) => $b['time'] <=> $a['time']); // newest first
        $toRemove = array_slice($all, $max);
        foreach ($toRemove as $item) {
            unset(self::$store[$this->sessionId][$domainHash][$item['path']][$item['name']]);
        }
    }

    // Static cleanup for ephemeral environment
    public static function cleanupSession(string $sessionId): void
    {
        unset(self::$store[$sessionId]);
    }
}
