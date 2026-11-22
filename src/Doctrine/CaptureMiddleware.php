<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;
use Sendproof\Core\Capture\Recorder;

/**
 * DBAL middleware that reports every executed statement to the request's recorder.
 *
 * It wraps the driver, so it sees statements from the ORM, the query builder and raw SQL alike,
 * including affected row counts for writes. It does nothing unless a preview or observed send is
 * capturing. Savepoint statements issued for nested transactions are left out.
 */
final class CaptureMiddleware implements Middleware
{
    /**
     * @param bool|null $showValues Null shows values in `dev` and `test` only
     * @param list<string> $safeProcedures MySQL procedures the host declared never commit, already
     *        checked and lower-cased by {@see \Sendproof\Core\Capture\Statements::normalizeProcedures()}
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?bool $showValues,
        private readonly string $environment,
        private readonly array $safeProcedures = [],
    ) {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new CaptureDriver($driver, $this->recorder, $this->showValues ?? in_array($this->environment, ['dev', 'test'], true), $this->safeProcedures);
    }
}
