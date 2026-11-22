<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Capture\SqlRedactor;

/** Remembers bound values and reports the statement, with timing and rows affected, when it executes. */
final class CaptureStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string,mixed> */
    private array $values = [];

    /** @param (\Closure(\Throwable): void)|null $onError told about a failed execution before it is rethrown */
    public function __construct(StatementInterface $statement, private readonly string $sql, private readonly Recorder $recorder, private readonly bool $showValues, private readonly ?\Closure $onError = null)
    {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->values[$param] = $value;
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $started = hrtime(true);
        try {
            $result = parent::execute();
        } catch (\Throwable $error) {
            if ($this->onError !== null) {
                ($this->onError)($error);
            }

            throw $error;
        }
        if ($this->recorder->isCapturing() && !CaptureConnection::isSavepoint($this->sql)) {
            $bindings = [];
            foreach ($this->values as $param => $value) {
                $bindings[] = ['name' => is_int($param) ? 'p' . ($param - 1) : ltrim($param, ':'), 'value' => $value];
            }
            $rows = self::returnsRows($this->sql) ? null : $result->rowCount();
            [$entry, $truncated] = SqlRedactor::entry($this->sql, $bindings, $this->showValues, $rows);
            $this->recorder->recordSql((hrtime(true) - $started) / 1e6, $entry, $truncated, 'Doctrine.DBAL.Connection');
        }

        return $result;
    }

    private static function returnsRows(string $sql): bool
    {
        return (bool) preg_match('/^\s*(?:select|with|pragma|show|explain|describe)\b/i', $sql);
    }
}
