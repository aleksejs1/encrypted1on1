<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Enables WAL mode + a busy timeout on every SQLite connection. DBAL 4 removed the old
 * Events::postConnect event this used to be done with — Driver Middleware is the current
 * replacement, and doctrine-bundle already autoconfigures anything implementing this
 * interface (registerForAutoconfiguration(Middleware::class)), so this needs no manual
 * service tagging, same as every other autoconfigured listener in this app.
 *
 * WAL removes reader-blocks-writer/writer-blocks-reader contention, which is SQLite's
 * default rollback-journal behavior; it does not remove writer-vs-writer contention
 * (SQLite only ever allows one writer at a time regardless of journal mode) — the busy
 * timeout is what makes a concurrent writer wait briefly instead of immediately failing
 * with "database is locked".
 *
 * A no-op for MySQL (see docs/deployment.md's "Using MySQL instead of SQLite") — gated on
 * the connection's actual PDO driver name, not on which DBAL platform class is in use, so
 * it can never accidentally run PRAGMA (SQLite-only syntax) against a MySQL connection.
 */
final class SqliteConnectionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                $connection = parent::connect($params);

                $native = $connection->getNativeConnection();
                if ($native instanceof \PDO && 'sqlite' === $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                    $connection->exec('PRAGMA journal_mode=WAL');
                    $connection->exec('PRAGMA busy_timeout=5000');
                }

                return $connection;
            }
        };
    }
}
