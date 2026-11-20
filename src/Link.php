<?php

declare(strict_types=1);

namespace EasyToPaste;

/**
 * The link. The key lives after the `#`, which no HTTP client transmits.
 *
 * That is the whole mechanism: a fragment is not sent to the server, so the
 * service receives the locator and never the key. Everything else in this
 * library exists to keep that true.
 */
final class Link
{
    public const DEFAULT_APP_BASE = 'https://app.easytopaste.com';
    private const LOCATOR_PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';

    public static function build(
        string $locator,
        string $key,
        string $appBase = self::DEFAULT_APP_BASE
    ): string {
        if (preg_match(self::LOCATOR_PATTERN, $locator) !== 1) {
            throw new EasyToPasteException('"' . $locator . '" is not a valid secret locator');
        }
        if (strlen($key) !== Crypto::KEY_BYTES) {
            throw new EasyToPasteException('key must be exactly 32 bytes');
        }

        return rtrim($appBase, '/') . '/s/' . $locator . '#k=' . Crypto::toBase64Url($key) . '&v=1';
    }

    /**
     * @return array{locator: string, key: string}
     */
    public static function parse(string $link): array
    {
        $hash = strpos($link, '#');
        if ($hash === false) {
            throw new EasyToPasteException('the link has no "#", so it carries no key');
        }
        $before = substr($link, 0, $hash);
        $after = substr($link, $hash + 1);

        parse_str($after, $params);
        $k = $params['k'] ?? '';
        if (!is_string($k) || $k === '') {
            throw new EasyToPasteException('the link fragment has no k= key');
        }
        $key = Crypto::fromBase64Url($k);
        if (strlen($key) !== Crypto::KEY_BYTES) {
            throw new EasyToPasteException('the key in the link is not 32 bytes');
        }

        $path = parse_url($before, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $before;
        }
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => $s !== ''
        ));
        $locator = $segments === [] ? '' : $segments[count($segments) - 1];
        if (preg_match(self::LOCATOR_PATTERN, $locator) !== 1) {
            throw new EasyToPasteException('"' . $locator . '" is not a valid secret locator');
        }

        return ['locator' => $locator, 'key' => $key];
    }
}
