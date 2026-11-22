<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Doctrine;

use Sendproof\Core\Contracts\PreviewObservation;

/** Watches ORM changes during an ordinary send without touching its transaction. */
final class DoctrineObservation implements PreviewObservation
{
    public function __construct(private readonly OrmChangeCollector $collector)
    {
    }

    public function changes(): array
    {
        return $this->collector->changes();
    }

    public function changesTruncated(): bool
    {
        return $this->collector->truncated();
    }

    public function dispose(): void
    {
        $this->collector->stop();
    }
}
