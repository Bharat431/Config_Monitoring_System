<<<<<<< HEAD
# CodeIgniter 4 Application Starter

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library

---

# Config Monitor

Watches `.env`, `.json`, `.yaml`, `.yml` and `.properties` files, parses them,
computes a structured diff and delivers signed webhooks when configuration
changes. Parsing is tolerant of partial/corrupt writes: the last good snapshot
is retained and the original error (with line/snippet where available) is
recorded, so recovery produces a correct diff instead of a false "everything
was deleted" event.

## Local quick start

```bash
cp .env.example .env          # optional; SQLite works out of the box
composer install
php spark migrate --all
php spark monitor:run --once  # scan all enabled files once
php spark monitor:work --once # deliver one batch of queued notifications
php spark serve --port 8080   # dashboard + API
```

Register a file with `POST /watch` or by hand:

```bash
curl -X POST http://localhost:8080/api/watch \
  -H 'Content-Type: application/json' \
  -d '{"path":"/absolute/path/to/app.yaml","debounce_ms":750}'
```

## HTTP API

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health`, `/api/health` | Liveness/readiness probe |
| `GET` | `/files`, `/api/files` | List monitored files |
| `POST` | `/watch`, `/api/watch` | Start watching a file |
| `DELETE` | `/watch/:file`, `/api/watch/:file` | Stop watching a file |
| `GET` | `/events`, `/api/events` | List change events |
| `GET` | `/api/metrics` | Prometheus-style metric counters |
| `GET` | `/api/stats` | Dashboard aggregate statistics |
| `GET` | `/api/deliveries`, `/api/dlq` | Delivery attempts and dead letters |
| `POST` | `/api/events/:id/replay`, `/api/dlq/:id/retry` | Replay/retry |

Set `CONFIGMON_API_KEY` to require `X-API-Key` (or `Bearer`) on management
routes. Health endpoints stay public for load balancers.

## Queue and delivery architecture

The watcher is decoupled from the notifier by a durable, database-backed
`event_queue` (no Redis/RabbitMQ dependency):

1. `monitor:run` parses and diffs files, then writes the event and one queue row
   per enabled target in a single transaction.
2. `monitor:work` atomically claims rows with a conditional `UPDATE`, renews the
   lease on a heartbeat, and marks delivery successful only after a 2xx reply.
3. Failures use exponential backoff with ±20% jitter (epoch-ms columns give
   sub-second precision). Exhausted retries and non-retryable 4xx responses go
   to `dead_letters`, where `POST /api/dlq/:id/retry` reopens the entry.

Consumers receive a stable `X-Event-Id` for de-duplication; a failing DLQ retry
reopens the same unique queue row rather than creating a duplicate.

## Containers

`docker-compose.yml` builds the image once and starts `db`, `migrate`, `web`,
`watcher` and two `worker` replicas. The `migrate` service runs first; all other
services wait for it to complete successfully.

```bash
docker compose up -d --build
docker compose up -d --scale worker=3   # scale delivery horizontally
docker compose logs -f watcher worker
docker compose down -v                 # also drops the MySQL volume
```

Configuration is via environment variables in `.env.example`; the compose file
overrides the database group to MySQL and mounts config files from
`writable/sample-configs`. Both dashboard themes intentionally use the same
pearl/ice palette (`#edf2fb`, `#e3f2fd`, pearl white) with dark text.

## Tests and contract

```bash
composer test -- --no-coverage
```

`docs/change-event.schema.json` is the published JSON Schema for canonical
webhook events and stored `change_events.payload` rows.
`tests/contract/WebhookPayloadContractTest.php` validates both
`ChangeEvent::toArray()` and actual outgoing webhook bodies against it;
`tests/integration/ChangePipelineTest.php` covers the full pipeline including
debounce precision, backoff bounds, queue ownership and DLQ retry.
=======
# Config_Monitoring_System
>>>>>>> 834dc087577c2af68704fb482cd62001b873730f
