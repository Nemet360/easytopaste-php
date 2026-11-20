# EasyToPaste for PHP

```php
use EasyToPaste\Client;

$result = (new Client())->share('the database password you were about to email');
echo $result['link'];
// https://app.easytopaste.com/s/MFRG...#k=...&v=1
```

> **Not on Packagist yet.** Publishing is the owner's call and is pending.
> Until then: `composer config repositories.easytopaste vcs https://github.com/Nemet360/easytopaste-php`
> and `composer require nemet360/easytopaste:dev-main`.

Share a password, an API key or a note through a **one-time link**. Your
application encrypts it (AES-256-GCM, fresh 32-byte key) before anything
leaves; the key travels in the link's URL fragment — the part after the `#`,
which no HTTP client transmits — so the service stores ciphertext it cannot
read. The link dies on first read.

## Who it is for

The application that has just generated a password for a new user, or a
credential for a contractor, and is about to put it in an email. The email
leaves it in two mailboxes for ever. This leaves a link that expires.

## What leaves your server

The ciphertext, its size, the expiry and the one-time flag. Nothing else: no
plaintext, no key, no hostname, no user, no telemetry. `tests/run.php` asserts
the request body field by field, including that neither the plaintext nor the
key appears in it.

## The same envelope as every other client

`src/Crypto.php` builds `[iv 12][tag 16][ciphertext]`, AES-256-GCM, base64url —
byte-identical to what the browser, the CLI, the GitHub Action, the MCP server
and the editor extension build. A link made in PHP opens in all of them. The
test suite proves it against a fixed vector produced by the JavaScript
implementation, on PHP 8.1 through 8.4, because a second implementation of a
crypto format is only safe while something compares the two.

## No dependencies

`ext-openssl` and `ext-json`, both of which your PHP already has. Nothing from
Packagist. This is installed into applications that hold credentials, and every
dependency is one more thing their owner has to trust on our word.

## The limit you will meet first

Anonymous callers share a daily allowance of **10 creates per outgoing IP
address**, and a server shares its address with everything else on it. There is
no long-lived API key yet; pass a bearer token to the constructor if an earlier
step obtained one (they last 15 minutes).

## Options

```php
new Client(
    apiBase: 'https://api.easytopaste.com',
    appBase: 'https://app.easytopaste.com',
    token: '',              // optional bearer access token
    timeoutSeconds: 15,
);

$client->share($secret, days: 7, oneTime: false);
```

What it is: <https://easytopaste.com/?utm_source=packagist&utm_medium=php&utm_campaign=launch-2026-11>
· how the encryption works: <https://easytopaste.com/how-it-works/?utm_source=packagist&utm_medium=php&utm_campaign=launch-2026-11>
