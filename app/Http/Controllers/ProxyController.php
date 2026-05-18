<?php
/**
 * MSHW-proxy - Proxy Request Controller
 * Handles URL decoding, request forwarding, and response streaming
 */

declare(strict_types=1);

namespace MSHW\Proxy\Http\Controllers;

use MSHW\Proxy\Core\CookieJar;
use MSHW\Proxy\Core\ProxyEngine;
use MSHW\Proxy\Core\HtmlRewriter;
use GuzzleHttp\Psr7\Uri;

class ProxyController
{
    private CookieJar $cookieJar;
    private ProxyEngine $proxy;
    private HtmlRewriter $rewriter;

    public function __construct()
    {
        // Generate unique session ID per request chain (ephemeral)
        $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? bin2hex(random_bytes(16));
        $this->cookieJar = new CookieJar($sessionId);
        $this->proxy = new ProxyEngine($this->cookieJar);
        $this->rewriter = new HtmlRewriter();
    }

    /**
     * Handle proxy request: GET/POST /?q=<encoded_url>
     */
    public function handle(string $method, string $uriPath): void
    {
        // Get and decode target URL
        $encodedUrl = $_GET['q'] ?? $_POST['q'] ?? '';
        if (!$encodedUrl) {
            $this->sendError(400, 'Missing "q" parameter. Usage: /?q=<base64_url>');
            return;
        }

        $targetUrl = $this->decodeUrl($encodedUrl);
        if (!$this->isValidUrl($targetUrl)) {
            $this->sendError(400, 'Invalid or blocked URL');
            return;
        }

        try {
            // Execute proxied request
            $result = $this->proxy->request($method, $targetUrl, [
                'headers' => $this->sanitizeIncomingHeaders(),
                'body' => $method === 'POST' ? file_get_contents('php://input') : null,
            ]);

            // Handle Cloudflare challenge fallback
            if (!empty($result['challenge'])) {
                $this->renderChallengePage($targetUrl, $result);
                return;
            }

            // Set response headers
            http_response_code($result['status']);
            foreach ($result['headers'] as $name => $values) {
                // Skip hop-by-hop headers
                if (in_array(strtolower($name), ['transfer-encoding', 'connection', 'keep-alive'])) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    header("$name: $value", false);
                }
            }

            // Rewrite HTML/CSS if needed
            $contentType = $result['headers']['content-type'][0] ?? '';
            $bodyStream = $result['bodyStream'];

            if ($this->shouldRewrite($contentType)) {
                $this->streamRewrittenBody($bodyStream, $targetUrl, $contentType);
            } else {
                // Stream raw body for binary/non-HTML content
                $this->streamRawBody($bodyStream);
            }

            fclose($bodyStream);

        } catch (\Throwable $e) {
            error_log("Proxy error: " . $e->getMessage());
            $this->sendError(502, 'Failed to fetch resource: ' . $e->getMessage());
        }
    }

    /**
     * Decode URL (support base64 + rawurlencode combo)
     */
    private function decodeUrl(string $encoded): string
    {
        // Try base64 first (our default encoding)
        $decoded = base64_decode($encoded, true);
        if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_URL)) {
            return $decoded;
        }
        // Fallback to raw urldecode
        return rawurldecode($encoded);
    }

    /**
     * Validate URL against security rules
     */
    private function isValidUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        // Allow only http/https
        if (!in_array($scheme, ['http', 'https'])) {
            return false;
        }

        // Block localhost/private IPs (prevent SSRF)
        $blockedPatterns = [
            '#^127\.#i',
            '#^10\.#i',
            '#^192\.168\.#i',
            '#^172\.(1[6-9]|2[0-9]|3[01])\.#i',
            '#^localhost#i',
        ];
        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize incoming headers (remove hop-by-hop, prevent injection)
     */
    private function sanitizeIncomingHeaders(): array
    {
        $allowed = [
            'accept', 'accept-language', 'accept-encoding',
            'cache-control', 'if-none-match', 'if-modified-since',
        ];
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                if (in_array($name, $allowed) && is_string($value) && !preg_match("/[\r\n]/", $value)) {
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    /**
     * Determine if response body should be rewritten
     */
    private function shouldRewrite(string $contentType): bool
    {
        $rewritable = ['text/html', 'application/xhtml+xml', 'text/css', 'application/javascript'];
        foreach ($rewritable as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stream rewritten HTML/CSS/JS with URL proxification
     */
    private function streamRewrittenBody(resource $stream, string $baseUrl, string $contentType): void
    {
        $buffer = '';
        $chunkSize = 8192;

        while (!feof($stream)) {
            $buffer .= fread($stream, $chunkSize);
            
            // Process complete tags/declarations only
            if (str_contains($contentType, 'html') && str_contains($buffer, '</')) {
                $parts = explode('</', $buffer, 2);
                $process = $parts[0] . '</';
                $buffer = $parts[1] ?? '';
                
                $rewritten = $this->rewriter->rewrite($process, $baseUrl, $contentType);
                echo $rewritten;
                flush();
            } elseif (str_contains($contentType, 'css') && str_contains($buffer, '}')) {
                $parts = explode('}', $buffer, 2);
                $process = $parts[0] . '}';
                $buffer = $parts[1] ?? '';
                
                $rewritten = $this->rewriter->rewrite($process, $baseUrl, $contentType);
                echo $rewritten;
                flush();
            }
            // Keep buffering if incomplete
        }
        
        // Process remaining buffer
        if ($buffer !== '') {
            echo $this->rewriter->rewrite($buffer, $baseUrl, $contentType);
            flush();
        }
    }

    /**
     * Stream raw body (for images, files, etc.)
     */
    private function streamRawBody(resource $stream): void
    {
        $chunkSize = 8192;
        while (!feof($stream)) {
            echo fread($stream, $chunkSize);
            flush();
        }
    }

    /**
     * Render Cloudflare challenge fallback page
     */
    private function renderChallengePage(string $url, array $result): void
    {
        http_response_code($result['status']);
        foreach ($result['headers'] as $name => $values) {
            foreach ((array) $values as $value) {
                header("$name: $value", false);
            }
        }
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Cloudflare Challenge - MSHW-proxy</title>
            <meta charset="utf-8">
            <style>
                body { font-family: system-ui; max-width: 800px; margin: 2rem auto; padding: 1rem; }
                .card { border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem; }
                code { background: #f4f4f4; padding: 0.2rem 0.4rem; border-radius: 3px; }
                .btn { display: inline-block; padding: 0.5rem 1rem; background: #007bff; color: white; 
                       text-decoration: none; border-radius: 4px; margin-top: 1rem; }
            </style>
        </head>
        <body>
            <div class="card">
                <h2>🛡️ Cloudflare Challenge Detected</h2>
                <p>The target site requires interactive verification. Please follow these steps:</p>
                <ol>
                    <li>Open this URL directly in your browser: <br><code><?= htmlspecialchars($url) ?></code></li>
                    <li>Complete the challenge (CAPTCHA/JS check)</li>
                    <li>Copy the <code>cf_clearance</code> cookie value from your browser dev tools</li>
                    <li>Go to <a href="/dashboard">Dashboard → Cookies</a> and paste it</li>
                    <li>Retry your request</li>
                </ol>
                <a href="/dashboard" class="btn">Open Dashboard</a>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Send JSON error response
     */
    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message], JSON_PRETTY_PRINT);
    }
}
