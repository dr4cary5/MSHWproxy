<?php
/**
 * MSHW-proxy - Streaming Proxy Engine with Cloudflare Prep
 * Memory-efficient, async-ready, ephemeral-optimized
 */

declare(strict_types=1);

namespace MSHW\Proxy\Core;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Psr7\Uri;

class ProxyEngine
{
    private CookieJar $cookieJar;
    private array $config;
    private \Symfony\Contracts\HttpClient\HttpClientInterface $client;

    public function __construct(CookieJar $cookieJar, array $config = [])
    {
        $this->cookieJar = $cookieJar;
        $this->config = array_merge(\config('proxy', []), $config);
        
        // Initialize Symfony HTTP Client (HTTP/2, TLS 1.3, Async-native)
        $this->client = HttpClient::create([
            'timeout' => $this->config['timeout'],
            'max_redirects' => 5,
            'http_version' => '2.0', // Prefer HTTP/2
            'verify_peer' => true,
            'verify_host' => true,
        ]);
    }

    /**
     * Execute proxied request with streaming response
     *
     * @return array {
     *   @type int $status
     *   @type array $headers
     *   @type resource $bodyStream (for chunked output)
     * }
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $uri = new Uri($url);
        
        // Prepare headers with spoofing + cookie injection
        $headers = $this->prepareHeaders($method, $uri, $options['headers'] ?? []);
        
        // Prepare request body
        $body = $options['body'] ?? null;
        if (is_array($body)) {
            $body = http_build_query($body);
            $headers['content-type'] ??= 'application/x-www-form-urlencoded';
        }

        // Send request (non-blocking)
        $response = $this->client->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
            'on_progress' => $options['on_progress'] ?? null,
        ]);

        // Process response headers
        $responseHeaders = $this->processResponseHeaders($response, $uri);
        
        // Check for Cloudflare challenge
        if ($this->isCloudflareChallenge($response)) {
            return $this->handleCloudflareChallenge($response, $uri, $method, $options);
        }

        // Return streaming body
        return [
            'status' => $response->getStatusCode(),
            'headers' => $responseHeaders,
            'bodyStream' => $this->streamBody($response),
            'cookies_updated' => true,
        ];
    }

    /**
     * Prepare request headers with spoofing and cookie injection
     */
    private function prepareHeaders(string $method, UriInterface $uri, array $incoming): array
    {
        $cfConfig = \config('cloudflare', []);
        $headers = [
            'User-Agent' => $cfConfig['user_agents'][array_rand($cfConfig['user_agents'])],
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
        ];

        // Add Cloudflare spoofing headers
        if (!empty($cfConfig['headers'])) {
            $headers = array_merge($headers, $cfConfig['headers']);
        }

        // Merge with incoming (client) headers, sanitizing dangerous ones
        foreach ($incoming as $name => $value) {
            $nameLower = strtolower($name);
            // Skip hop-by-hop headers that shouldn't be forwarded
            if (in_array($nameLower, ['host', 'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'])) {
                continue;
            }
            // Sanitize to prevent header injection
            if (is_string($value) && !preg_match("/[\r\n]/", $value)) {
                $headers[$name] = $value;
            }
        }

        // Inject cookies from jar
        $cookieHeader = $this->cookieJar->getCookieHeader($uri);
        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        // Add Basic Auth if present in URL
        if ($uri->getUserInfo()) {
            $headers['Authorization'] = 'Basic ' . base64_encode($uri->getUserInfo());
        }

        return $headers;
    }

    /**
     * Process response headers and update cookie jar
     */
    private function processResponseHeaders(ResponseInterface $response, UriInterface $uri): array
    {
        $headers = $response->getHeaders(false); // indexed array
        $normalized = [];

        foreach ($headers as $name => $values) {
            $nameLower = strtolower($name);
            $normalized[$nameLower] = $values;
            
            // Auto-update cookie jar on Set-Cookie
            if ($nameLower === 'set-cookie') {
                foreach ($values as $cookieHeader) {
                    $this->cookieJar->setCookie($cookieHeader, $uri);
                }
            }
        }

        return $normalized;
    }

    /**
     * Detect Cloudflare challenge responses
     */
    private function isCloudflareChallenge(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        
        // Classic CF challenge indicators
        if ($status === 403 || $status === 503) {
            $server = $headers['server'][0] ?? '';
            $cfRay = $headers['cf-ray'][0] ?? '';
            if (stripos($server, 'cloudflare') !== false || $cfRay !== '') {
                return true;
            }
        }
        
        // Check body for challenge keywords (if needed, but avoid full body read)
        return false;
    }

    /**
     * Handle Cloudflare challenge with retry/fallback strategy
     */
    private function handleCloudflareChallenge(
        ResponseInterface $response,
        UriInterface $uri,
        string $method,
        array $options
    ): array {
        $strategy = \config('cloudflare.strategy', 'auto');
        
        // Strategy: cookie_inject + retry
        if (in_array($strategy, ['auto', 'cookie_inject'])) {
            // Retry with exponential backoff (cookies may have updated)
            $attempts = 0;
            $backoff = (int) \config('cloudflare.retry.backoff_base', 3);
            $maxBackoff = (int) \config('cloudflare.retry.backoff_max', 12);
            
            while ($attempts < (int) \config('cloudflare.retry.max_attempts', 3)) {
                sleep(min($backoff * (2 ** $attempts), $maxBackoff));
                $result = $this->request($method, (string) $uri, $options);
                if ($result['status'] !== 403 && $result['status'] !== 503) {
                    return $result; // Success
                }
                $attempts++;
            }
        }
        
        // Fallback: return challenge for manual solving via dashboard
        return [
            'status' => $response->getStatusCode(),
            'headers' => $this->processResponseHeaders($response, $uri),
            'bodyStream' => $this->streamBody($response),
            'challenge' => true,
            'manual_solve_url' => '/dashboard/challenge?' . http_build_query([
                'url' => (string) $uri,
                'method' => $method,
            ]),
        ];
    }

    /**
     * Stream response body in chunks (memory-efficient)
     *
     * @return resource Stream handle for chunked output
     */
    private function streamBody(ResponseInterface $response)
    {
        $stream = fopen('php://temp', 'r+');
        $chunkSize = (int) \config('proxy.stream_chunk_size', 8192);
        
        foreach ($this->client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) continue;
            if ($chunk->isFirst()) {
                // First chunk: check content length limit
                $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
                $maxLen = (int) \config('proxy.max_response_size', 52428800);
                if ($length > 0 && $length > $maxLen) {
                    throw new \RuntimeException("Response too large: {$length} bytes");
                }
            }
            $data = $chunk->getContent();
            if ($data !== null && $data !== '') {
                fwrite($stream, $data);
            }
        }
        
        rewind($stream);
        return $stream;
    }

    /**
     * Get current cookie jar (for dashboard integration)
     */
    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
