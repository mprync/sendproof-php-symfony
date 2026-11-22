<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Sendproof\Core\Contracts\DescribesDatabaseSystem;
use Sendproof\Core\Contracts\PreviewObservation;
use Sendproof\Core\Contracts\PreviewProvider;
use Sendproof\Core\Contracts\PreviewSession;
use Sendproof\Core\Preview\PreviewTurns;

/**
 * Preview provider for Doctrine DBAL and ORM.
 *
 * A preview opens a transaction on every configured connection before the request is dispatched,
 * so security, listeners, the controller and Doctrine lifecycle callbacks all run inside it. The
 * ORM event listener supplies row changes; see {@see OrmChangeCollector} for what is captured.
 */
class DoctrinePreviewProvider implements PreviewProvider, DescribesDatabaseSystem
{
    /**
     * The savepoint a MySQL or MariaDB Preview opens its transaction with, for the backstop in
     * {@see DoctrineSession::rollback()}. DBAL names its own savepoints `DOCTRINE_<n>`, so it never
     * makes this one, and {@see CaptureConnection} refuses a handler's `RELEASE` or `ROLLBACK TO` of it.
     */
    public const SAVEPOINT = 'sendproof_preview';

    /** @param list<string> $connections Doctrine connection names */
    public function __construct(
        protected ManagerRegistry $registry,
        protected OrmChangeCollector $collector,
        protected array $connections = ['default'],
        protected ?string $label = null,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            foreach ($this->connections as $name) {
                $this->connection($name);
            }

            return $this->connections !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function database(): ?string
    {
        if ($this->label !== null && $this->label !== '') {
            return $this->label;
        }
        $params = $this->connection($this->connections[0])->getParams();
        $name = (string) ($params['path'] ?? $params['dbname'] ?? '');

        return $name === ':memory:' || $name === '' ? ($name === '' ? null : $name) : basename(str_replace('\\', '/', $name));
    }

    public function databaseSystem(): ?string
    {
        try {
            return match ($this->connection($this->connections[0])->getParams()['driver'] ?? '') {
                'pdo_sqlite', 'sqlite3' => 'sqlite',
                'pdo_mysql', 'mysqli' => 'mysql',
                'pdo_pgsql', 'pgsql' => 'postgresql',
                'pdo_sqlsrv', 'sqlsrv' => 'sqlserver',
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether Previews on this connection take turns. MySQL and MariaDB deadlock when three or
     * more Previews insert one new key at once and the first rolls back, at any isolation level.
     */
    protected function takesTurns(Connection $connection): bool
    {
        return in_array($connection->getParams()['driver'] ?? '', ['pdo_mysql', 'mysqli'], true);
    }

    public function begin(mixed $context): PreviewSession
    {
        $enrolled = [];
        $turns = [];
        try {
            foreach ($this->connections as $name) {
                $connection = $this->connection($name);
                if ($this->takesTurns($connection)) {
                    // One Preview at a time per database, taken before the connection is touched.
                    $params = $connection->getParams();
                    $turns[] = PreviewTurns::take(implode('|', [(string) ($params['host'] ?? ''), (string) ($params['port'] ?? ''), (string) ($params['dbname'] ?? '')]));
                }
                $before = $connection->getTransactionNestingLevel();
                $connection->beginTransaction();
                $enrolled[] = [$connection, $before, true];
                // The backstop's mark, on every database, made first thing in the Preview transaction on the
                // driver's own connection, so it neither passes the handler's guard nor shows in the SQL log.
                self::native($connection, (self::sqlServer($connection) ? 'SAVE TRANSACTION ' : 'SAVEPOINT ') . self::SAVEPOINT);
            }
        } catch (\Throwable $error) {
            foreach ($turns as $turn) {
                $turn->release();
            }
            foreach ($enrolled as [$connection, $before, $_]) {
                try {
                    while ($connection->getTransactionNestingLevel() > $before) {
                        $connection->rollBack();
                    }
                } catch (\Throwable) {
                    // Nothing more can be done; the original failure is reported.
                }
            }
            throw $error;
        }

        $this->collector->start();

        return new DoctrineSession($enrolled, $this->collector, $turns);
    }

    public function beginObservation(mixed $context): ?PreviewObservation
    {
        $this->collector->start();

        return new DoctrineObservation($this->collector);
    }

    /** Whether the connection is SQL Server, which marks a savepoint with `SAVE TRANSACTION`. */
    public static function sqlServer(Connection $connection): bool
    {
        return in_array($connection->getParams()['driver'] ?? '', ['pdo_sqlsrv', 'sqlsrv'], true);
    }

    /**
     * Runs `$sql` on the driver's own connection, past the DBAL middleware, and answers its first row.
     *
     * @return list<mixed>|null
     * @throws \Throwable when the statement fails
     */
    public static function native(Connection $connection, string $sql): ?array
    {
        $native = $connection->getNativeConnection();
        if ($native instanceof \PDO) {
            $statement = $native->query($sql);
            // pdo_sqlsrv throws when asked for a row of a statement that returns none (`SAVE TRANSACTION`).
            $row = $statement === false || $statement->columnCount() === 0 ? false : $statement->fetch(\PDO::FETCH_NUM);

            return $row === false ? null : $row;
        }
        if ($native instanceof \mysqli) {
            $result = $native->query($sql);
            if ($result === false) {
                throw new \RuntimeException($native->error, $native->errno);
            }

            return $result instanceof \mysqli_result ? ($result->fetch_row() ?? null) : null;
        }
        if (is_resource($native) && function_exists('sqlsrv_query')) {
            $statement = sqlsrv_query($native, $sql);
            if ($statement === false) {
                throw new \RuntimeException('The SQL Server statement failed.');
            }
            $row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_NUMERIC);

            return is_array($row) ? $row : null;
        }

        throw new \LogicException('The Preview needs the driver connection for its savepoint.');
    }

    private function connection(string $name): Connection
    {
        $connection = $this->registry->getConnection($name);
        if (!$connection instanceof Connection) {
            throw new \LogicException("Doctrine connection \"{$name}\" is not a DBAL connection.");
        }

        return $connection;
    }
}
