<?php

declare(strict_types=1);

namespace EasyToPaste;

/**
 * Create a one-time link from PHP. Encryption happens here; only ciphertext leaves.
 *
 *     $client = new Client();
 *     $result = $client->share('the database password');
 *     echo $result['link'];  // https://app.easytopaste.com/s/MFRG...#k=...&v=1
 *
 * Who this is for: the PHP application that has just generated a password for
 * a new user, or a credential for a contractor, and is about to put it in an
 * email. The email leaves it in two mailboxes for ever. This leaves a link
 * that dies on first read.
 *
 * No dependencies, for the same reason as every other client in this project:
 * it is installed into applications that hold credentials, and a dependency is
 * something their owner has to trust on our word. cURL when the extension is
 * there, streams when it is not.
 */
final class Client
{
    public const VERSION = '0.1.0';
    private const USER_AGENT_PREFIX = 'easytopaste-php';

    public function __construct(
        private readonly string $apiBase = 'https://api.easytopaste.com',
        private readonly string $appBase = 'https://app.easytopaste.com',
        private readonly string $token = '',
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    /**
     * Which registry installed this copy, if it said so.
     *
     * A link tag measures a click, and a PHP application that creates a secret
     * never loads a page. Sanitised rather than trusted: the value comes from
     * the environment and goes into a header, where a stray newline is a
     * request-splitting bug.
     */
    public static function channelTag(?string $raw = null): string
    {
        if ($raw === null) {
            $env = getenv('EASYTOPASTE_CHANNEL');
            $raw = $env === false ? '' : $env;
        }
        $clean = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($raw)));
        if (!is_string($clean)) {
            return '';
        }

        return substr($clean, 0, 16);
    }

    public static function userAgent(
        string $version = self::VERSION,
        ?string $channel = null
    ): string {
        $tag = self::channelTag($channel);

        return $tag === ''
            ? self::USER_AGENT_PREFIX . '/' . $version
            : self::USER_AGENT_PREFIX . '/' . $version . ' (' . $tag . ')';
    }

    /**
     * Build the request body. Separate from the sending so a test can assert
     * every field without a network: what this library is allowed to send
     * matters as much as what it does send.
     *
     * @return array<string, mixed>
     */
    public static function createBody(
        string $ciphertextB64,
        int $sizeBytes,
        bool $oneTime,
        int $days
    ): array {
        return [
            'type' => 'text',
            'mode' => 'link',
            'envelopeVersion' => 1,
            'sizeBytes' => $sizeBytes,
            'oneTime' => $oneTime,
            'expiresInDays' => $days,
            'requiresRecipientLogin' => false,
            'mimeHint' => 'text/plain',
            'ciphertext' => $ciphertextB64,
        ];
    }

    /**
     * Encrypt and create.
     *
     * @return array{link: string, locator: string, expiresAt: ?string, oneTime: bool}
     */
    public function share(string $secret, int $days = 1, bool $oneTime = true): array
    {
        if ($secret === '') {
            throw new EasyToPasteException('the secret is empty', 'invalid_input');
        }
        $size = strlen($secret);
        if ($size > Crypto::MAX_INLINE_BYTES) {
            throw new EasyToPasteException(
                'the secret is ' . $size . ' bytes; this client takes up to '
                    . Crypto::MAX_INLINE_BYTES,
                'too_large'
            );
        }
        if ($days < 1 || $days > 30) {
            throw new EasyToPasteException(
                'days must be between 1 and 30, got ' . $days,
                'invalid_input'
            );
        }

        $key = Crypto::generateKey();
        $envelope = Crypto::encrypt($secret, $key);
        $created = $this->post('/v1/secrets', self::createBody(
            Crypto::toBase64Url($envelope),
            $size,
            $oneTime,
            $days
        ));

        if (!isset($created['locator']) || !is_string($created['locator'])) {
            throw new EasyToPasteException('the API accepted the secret but returned no locator');
        }

        return [
            'link' => Link::build($created['locator'], $key, $this->appBase),
            'locator' => $created['locator'],
            'expiresAt' => isset($created['expiresAt']) && is_string($created['expiresAt'])
                ? $created['expiresAt']
                : null,
            'oneTime' => $oneTime,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $url = rtrim($this->apiBase, '/') . $path;
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . self::userAgent(),
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if (function_exists('curl_init')) {
            [$status, $raw] = $this->postCurl($url, $payload, $headers);
        } else {
            [$status, $raw] = $this->postStream($url, $payload, $headers);
        }

        $decoded = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if ($status >= 400) {
            $error = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
            $code = isset($error['code']) ? (string) $error['code'] : 'http_' . $status;
            $message = isset($error['message'])
                ? (string) $error['message']
                : 'the API answered ' . $status;
            if ($code === 'quota_exceeded') {
                $message .= ' The daily allowance is counted per outgoing IP address,'
                    . ' which a server shares with everything else on it,'
                    . ' and it resets at midnight UTC.';
            }
            throw new EasyToPasteException($message, $code);
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private function postCurl(string $url, string $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new EasyToPasteException('could not start a request', 'network');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new EasyToPasteException('could not reach the API: ' . $message, 'network');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $raw];
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private function postStream(string $url, string $payload, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new EasyToPasteException('could not reach the API', 'network');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $raw];
    }
}
