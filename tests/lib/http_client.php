<?php
declare(strict_types=1);

/**
 * Klien HTTP native untuk smoke test loopback.
 *
 * Hanya mengizinkan host 127.0.0.1, tidak mengikuti redirect otomatis, dan
 * memakai stream wrapper PHP (allow_url_fopen) tanpa dependensi eksternal.
 */
final class TestHttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headerValues($name);

        return $values === [] ? null : $values[0];
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}

final class TestHttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || ($parts['host'] ?? '') !== '127.0.0.1'
            || !isset($parts['port'])
        ) {
            throw new InvalidArgumentException('Base URL HTTP test harus http://127.0.0.1:<port>.');
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * @param array<string, string>|list<string> $headers
     */
    public function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null
    ): TestHttpResponse {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $url = $this->baseUrl . $path;
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || ($parts['host'] ?? '') !== '127.0.0.1'
        ) {
            throw new InvalidArgumentException('Request HTTP test harus menuju 127.0.0.1.');
        }

        $headerLines = ['Connection: close'];

        if ($this->cookies !== []) {
            $headerLines[] = 'Cookie: ' . $this->cookieHeader();
        }

        foreach ($headers as $name => $value) {
            $headerLines[] = is_int($name) ? $value : $name . ': ' . $value;
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 5.0,
            'protocol_version' => '1.1',
        ];

        if ($body !== null) {
            $http['content'] = $body;
        }

        error_clear_last();
        $responseBody = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        $rawHeaders = $http_response_header ?? [];

        if ($responseBody === false) {
            $error = error_get_last();
            throw new RuntimeException(
                'Request ' . $method . ' ' . $path . ' gagal: '
                . ($error['message'] ?? 'tidak ada detail error')
            );
        }

        $status = 0;
        $parsedHeaders = [];
        $statusLine = array_shift($rawHeaders);

        if (is_string($statusLine) && preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $matches) === 1) {
            $status = (int) $matches[1];
        }

        foreach ($rawHeaders as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $parsedHeaders[$name][] = $value;
        }

        if ($status === 0) {
            throw new RuntimeException('Respons HTTP tidak memiliki status line yang valid untuk ' . $path . '.');
        }

        $response = new TestHttpResponse($status, $parsedHeaders, $responseBody);

        foreach ($response->headerValues('set-cookie') as $cookie) {
            $this->storeCookie($cookie);
        }

        return $response;
    }

    public function cookieHeader(): string
    {
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    public function hasCookie(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    private function storeCookie(string $setCookie): void
    {
        $pair = trim(explode(';', $setCookie, 2)[0]);
        if (!str_contains($pair, '=')) {
            return;
        }

        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            return;
        }

        if ($value === '') {
            unset($this->cookies[$name]);
            return;
        }

        $this->cookies[$name] = $value;
    }
}
