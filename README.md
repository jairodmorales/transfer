<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="img/app.svg">
    <source media="(prefers-color-scheme: light)" srcset="img/app-dark.svg">
    <img src="img/app-dark.svg" width="64" height="64" alt="Transfer">
  </picture>
</p>

<h1 align="center">Nextcloud Transfer</h1>

<p align="center">
  <strong>Upload by link</strong> — transfer files into Nextcloud from any URL,<br>
  using the full bandwidth available to your server.
</p>

<p align="center">
  <a href="https://apps.nextcloud.com/apps/transfer"><img src="https://img.shields.io/badge/Nextcloud-29–33-0082c9?logo=nextcloud&logoColor=white" alt="Nextcloud 29–33"></a>
  <a href="https://github.com/jairodmorales/transfer/releases/latest"><img src="https://img.shields.io/github/v/release/jairodmorales/transfer?color=2ea44f" alt="Latest release"></a>
  <a href="COPYING"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue" alt="AGPL-3.0"></a>
</p>

---

## Features

- **Upload by link** — paste any `http`/`https` URL and Transfer downloads the file directly to your Nextcloud storage
- **Multi-URL batch** — submit up to 10 URLs in a single dialog, each queued as an independent background job
- **Live status panel** — a floating panel tracks every active transfer in real time, with progress restored automatically after a page refresh or across browser tabs
- **Minimize badge** — collapse the status panel to a compact badge when you want it out of the way; it shows the active transfer count at a glance
- **Integrity check** — optionally provide a checksum (MD5, SHA-1, SHA-256, SHA-512) to verify the download
- **Native notifications** — Nextcloud's notification bell rings on completion or failure
- **Admin controls** — configure maximum URLs per dialog, file size limit, domain blocklist, and job history retention from the administration panel

---

## How it works

Select **Upload by link** from the **+** menu in your Files view.

![Menu at the top of the files page.](img/menu.png)

Paste a URL — the filename and extension are detected automatically from the server's `Content-Type` header. Add more URLs with the **+ Add URL** button (up to the admin-configured limit). You can optionally provide a checksum to verify the download.

![The prompt appears in the middle of the screen.](img/prompt.png)

Click **Upload** and the transfer is queued as a background job. The status panel appears at the bottom-right of the screen and updates live every few seconds. Use the **−** button to minimize it to a badge, or **×** to dismiss it entirely.

> [!TIP]
> Background jobs run on the server's cron schedule — typically within five minutes.
> Configure your server to trigger `cron.php` more often for faster transfers.

---

## Admin settings

Go to **Administration → Transfer** to configure:

| Setting | Default | Description |
|---------|---------|-------------|
| Maximum URLs per dialog | 3 | How many URLs a user can submit in one batch (1–10) |
| Maximum file size | 0 (unlimited) | Reject downloads larger than this many MB |
| Blocked domains | _(empty)_ | One domain per line; supports `*.wildcard.com` |
| Job history retention | 30 days | How long completed transfer records are kept |

---

## Security

This app lets users trigger server-side HTTP requests. The following controls are in place:

- **SSRF protection** — Nextcloud's built-in middleware blocks requests to local and internal network addresses. Redirects are followed only within `http`/`https`; other schemes are rejected at the Guzzle level.
- **Domain blocklist** — Admins can block specific domains or wildcard subdomains.
- **URL validation** — Only `http` and `https` URLs are accepted. `file://`, `gopher://`, and other schemes are rejected before the URL reaches the job queue.
- **Rate limiting** — All endpoints are rate-limited per user (30 req/min single transfer, 20 req/min batch, 60 req/min probe, 120 req/min status).
- **Path traversal** — Destination paths containing `..` or null bytes are rejected immediately.
- **Credential sanitisation** — Credentials embedded in exception messages or URLs are stripped before being written to logs or the database.
- **XSS** — All server-supplied values (filenames, error messages, status strings) are HTML-escaped before being injected into the DOM.

---

## Installation

### From source

```bash
# Clone into your Nextcloud custom apps directory
cd /path/to/nextcloud/custom_apps
git clone https://github.com/jairodmorales/transfer.git transfer

# Enable the app and run the database migration
sudo -u www-data php /path/to/nextcloud/occ app:enable transfer
sudo -u www-data php /path/to/nextcloud/occ migrations:migrate transfer
```

### Updating

```bash
cd /path/to/nextcloud/custom_apps/transfer
git pull origin master

# Re-run migrations in case new ones were added
sudo -u www-data php /path/to/nextcloud/occ migrations:migrate transfer
```

---

## Building

**With a local toolchain** (requires Node.js 20+ and npm):

```bash
npm ci && npm run build
```

**With podman** (no local Node.js needed):

```bash
make build
```

Output lands in `js/` and `css/`. To create a release archive:

```bash
make dist
```

---

## Unit tests

Tests cover all pure-function utilities and require only PHP and Composer — no running Nextcloud instance needed.

```bash
composer install
./vendor/bin/phpunit
# → 35 tests, 37 assertions, 0 failures
```

---

## Requirements

- Nextcloud 29–33
- PHP 8.1+

---

## Contributing

Contributions are welcome. Please open an issue or pull request on
[GitHub](https://github.com/jairodmorales/transfer). All contributions are
licensed under the [AGPL-3.0](COPYING).

This fork is based on the original work by
[Daniel Thwaites](https://danth.me) and [Leon Becker](https://github.com/beleon/transfer).
