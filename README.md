# Sendproof for Symfony

Sendproof adds an API workbench to a Symfony application: browse your OpenAPI document, edit requests, **Send** them, **Preview** a write with a real Doctrine rollback, and read the SQL, logs, effects and row changes that an **Observed Send** produced.

The bundle implements [Sendproof protocol v1](https://github.com/mprync/sendproof/blob/a95579be596e1038c78584d274904a645f72e35a/spec/README.md). Preview hands the rewritten request to the real HTTP kernel in the same process, as a main request, inside a transaction that is always rolled back. The firewall, access control, listeners, argument resolvers and your controller all run exactly as for a normal request.

> **Status:** `0.1.0-alpha.3`. Tested with Symfony 8.1, Doctrine DBAL 4.6, ORM 3.8 and DoctrineBundle 3.4 on PHP 8.4 and SQLite. The package allows Symfony 7–8 and PHP 8.2+, but only the tested combination is verified.

## Install

```sh
composer require sendproof/symfony
```

Register the bundle (Flex does this for you) and import the workbench routes:

```php
// config/bundles.php
Sendproof\Symfony\SendproofBundle::class => ['all' => true],
```

```yaml
# config/routes/sendproof.yaml
sendproof:
    resource: '@SendproofBundle/config/routes.php'
```

## Turn it on

The workbench is **off by default** and refuses to run in production. It answers only when both conditions hold:

```yaml
# config/packages/sendproof.yaml
sendproof:
    enabled: true
    environments: ['dev']      # kernel environments, default ['dev']
    openapi_url: /api/doc.json
```

With the switch off, or in an unlisted environment, `/sendproof/...` and `/sendproof/_preview/...` return 404. Do not list `prod` unless you have also restricted access with `sendproof.authorize` and understand that previews run real application code.

## Opt operations in

Preview and Observed Send are explicit per action. Add `#[AllowPreview]` to the controller method (or class), or set the route default `_sendproof_preview: true`:

```php
use Sendproof\Symfony\Attribute\AllowPreview;

#[Route('/items', methods: ['POST'])]
#[AllowPreview]
public function create(Request $request): Response { /* ... */ }
```

Unmarked routes still work normally with Send. A preview of an unmarked route returns 409 and never runs the controller. Authentication and authorization stay in your firewall and `access_control`; the request counts as admitted only after they pass.

## What Preview guarantees

- `POST /sendproof/_preview/items` is rewritten to `POST /items` and handled in-process. Method, query, headers and body are preserved. Nothing leaves the application.
- A transaction opens on every connection in `sendproof.connections` before the request is handled, and is rolled back afterwards. The result is confirmed only if rollback is verified. If the controller commits or closes the preview transaction, or rollback fails, you get an error response and no envelope.
- Responses of 401 or 403, redirects before the controller, unhandled exceptions, responses over 1 MiB, unavailable providers and missing preferences never produce a confirmed envelope.
- Cookies and `Location` from the inner response are dropped.
- Outgoing Symfony Mailer messages are captured and **not sent**. Deliver anything else through the recorder (below).

### Record external effects

Inject `Sendproof\Core\Capture\Recorder` and ask before delivering anything outside the database. It returns `true` during a preview, so skip the effect then:

```php
if (!$this->recorder->tryRecordHttp('Notify billing', 'POST', $url, body: $payload)) {
    $this->http->request('POST', $url, ['json' => $payload]);
}
```

`tryRecordEmail`, `tryRecordHttp`, `tryRecordJob`, `tryRecordMessage` and `tryRecordEffect` follow the conventional shapes in the protocol. In an Observed Send they record the effect as `delivered: true` and return `false`.

## What is captured

| Source | How |
| --- | --- |
| Row changes | ORM `onFlush`/`postFlush` with before and after snapshots keyed by column name. Secret-named columns are `[REDACTED]`. |
| SQL | A DBAL driver middleware: text, bound values, duration and **rows affected** for writes. Values are shown only in `dev` and `test`; anything near a secret-named binding or statement is `[REDACTED]`. |
| Logs | Monolog records through a global processor. |
| Mail | Symfony Mailer `MessageEvent`: captured, rejected in a preview. |

Limits match the .NET adapter: 500 logs, 100 effects, 200 changes, 32 log properties and 64 SQL parameters. Truncation is reported with `logsTruncated`, `effectsTruncated` and `changesTruncated`.

## Known limits

- **Bulk and raw writes are not row changes.** DQL `UPDATE`/`DELETE`, `executeStatement()`, raw SQL, triggers and association join columns produce no ORM change. They still run inside the transaction, are rolled back, and appear in the SQL log.
- **Named parameters passed to `executeQuery($sql, ['name' => $v])` reach the driver as positional `?`.** DBAL rewrites them before any middleware sees them, so they are reported as `p0`, `p1`, … and redaction falls back to the statement text (which still catches secret column names). Use `$connection->prepare()` and `bindValue('name', …)` when the parameter name matters.
- **Only listed connections are rolled back.** Add every connection a previewed operation writes to in `sendproof.connections`. Other connections, caches, messenger transports, files and external services are not undone.
- **Transaction escapes are refused before they are sent.** `COMMIT`, `ROLLBACK`, `BEGIN`, `SET autocommit` anywhere in a `SET`, a driver-level commit, and the internal `sendproof_preview` savepoint; on MySQL and MariaDB also every implicitly committing statement (DDL other than `TEMPORARY` tables, `LOCK TABLES`, `GRANT`, `CHECK TABLE`, replication control and the rest of MySQL's list), SQL-level `PREPARE` and `EXECUTE` (and MariaDB's `EXECUTE IMMEDIATE`), executable comments containing any of these, and `CALL`. The Preview answers 500 and nothing is written. Statements sent on `getNativeConnection()` itself are not checked.
- **Stored procedures (MySQL and MariaDB).** A procedure can `COMMIT`, so `CALL` is refused unless you list it under `sendproof.safe_procedures` by exact name (`refresh_totals` or `reports.rebuild`; case-insensitive like MySQL; no wildcards, which fail the container build). Sendproof trusts the list: a listed procedure that commits makes the Preview's writes durable, and the backstop below then answers 500.
- **Backstop (every database).** The Preview opens its transaction with `SAVEPOINT sendproof_preview` (`SAVE TRANSACTION` on SQL Server) and, before rolling back, rolls back to it (on SQL Server, `ROLLBACK TRANSACTION sendproof_preview`, which fails once the transaction has ended; `@@TRANCOUNT` can't tell, because `pdo_sqlsrv` runs with implicit transactions on and the next statement after a commit opens a new one). On SQL Server a Preview also refuses `SET IMPLICIT_TRANSACTIONS ON` (`OFF` stays allowed) and, once the server has rolled the Preview transaction back itself (a deadlock victim, error 1205, or any error under `XACT_ABORT ON`), every later statement, like the MySQL deadlock rule below. This is exercised against SQL Server 2022 with `pdo_sqlsrv`. Any commit erases the savepoint, so a Preview whose transaction was committed anyway answers 500 without confirmation, even with autocommit off, where MySQL opens a new transaction by itself after the commit. The writes made before that commit are durable; the check only stops the Preview from claiming otherwise.
- **Deadlocks (MySQL and MariaDB).** A deadlock (1213), or a lock wait timeout (1205) with `innodb_rollback_on_timeout` on, rolls back the whole Preview transaction, and PDO runs MySQL with autocommit on outside `BEGIN`, so a retry would commit for real. The handler still gets the original `DeadlockException`, but every later statement on that connection in the Preview is refused before it is sent, and the Preview answers 500 without confirmation. The lock wait timeout case shares the deadlock code path and is covered by unit tests only, because `innodb_rollback_on_timeout` can be set only at server startup.
- **Swoole or OpenSwoole runtimes: no Preview.** The binding counts every write in the process as the open Preview's, because PHP normally runs one request per process at a time (php-fpm, `php -S`, and RoadRunner or FrankenPHP workers, all supported). A Swoole or OpenSwoole runtime runs requests concurrently in coroutines in one worker, so another request's write would be guarded or rolled back as the Preview's. There, every Preview answers 409 before the controller runs, capabilities list each opted-in operation under `previewUnavailable` with that reason, and Send and observed Send work as usual. Detection uses `APP_RUNTIME` (`runtime/swoole`) or a running coroutine; `sendproof.concurrent_workers` overrides it (`false` only for a server you run with one request per worker at a time).
- **Sessions** stored outside the listed connections are not rolled back.
- **Messenger, HTTP client and notifier calls** are not captured automatically. Record them with the recorder.
- **Logging needs a Monolog handler.** Monolog runs processors only for records that some handler will process.
- **Streamed and file responses** cannot be previewed.
- Observed-send ownership uses the user the firewall authenticated when the controller was entered, and is not re-checked afterwards.

## Configuration

| Key | Purpose |
| --- | --- |
| `enabled`, `environments` | Opt-in switch and allowed kernel environments. |
| `openapi_url`, `documents` | Where your OpenAPI document lives. |
| `auth_providers` | Sign-in methods shown by the workbench: `[{label: Staff, kind: oidc, url: /login}]`. |
| `authorize` | Security attribute (such as `ROLE_ADMIN`) required to open the workbench, and to preview or observe. |
| `identity` | Service implementing `Sendproof\Symfony\Identity\IdentityResolver` for the name and role shown. Defaults to the user identifier. |
| `provider` | `doctrine` (default), `null` to disable previews, or the id of your own `PreviewProvider` service. |
| `connections` | Doctrine connections enrolled in a preview. Default `['default']`. |
| `trace.ttl` | Seconds an observed-send trace stays readable. Default 300. |

## Observed Send

Send an operation with `Prefer: trace`. The response is untouched, plus an `X-Sendproof-Trace` header when an authenticated user with a stable identifier made the call. The trace is readable at `/sendproof/_api/traces/{id}` only by that user, for `sendproof.trace.ttl` seconds, with at most 8 traces per user and 32 in total. Traces are files in `var/sendproof-traces`, so they work across PHP-FPM workers.

## Tests

```sh
composer install && vendor/bin/phpunit
```

The Symfony conformance fixture is in [`../examples/symfony-fixture`](https://github.com/mprync/sendproof/blob/a95579be596e1038c78584d274904a645f72e35a/adapters/php/examples/symfony-fixture/README.md).

## Data stores

Preview can also cover MongoDB, Redis (Predis), DynamoDB, Elasticsearch, OpenSearch and Firestore. List the client services in `sendproof.data_stores`, and mark an operation you know writes to a guarded store with `#[AllowPreview(false, reason: 'Clears the Redis session')]`. Only the clients you list are covered. See [data stores in the PHP binding](https://github.com/mprync/sendproof/blob/a95579be596e1038c78584d274904a645f72e35a/adapters/php/data-stores.md).
