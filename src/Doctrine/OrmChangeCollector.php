<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Sendproof\Core\Capture\SqlRedactor;
use Sendproof\Core\Limits;

/**
 * Records row changes made through the Doctrine ORM while a preview or observed send runs.
 *
 * Coverage: entities flushed through an EntityManager. Row values are keyed by column name, so
 * they match the table. DQL `UPDATE`/`DELETE`, DBAL `executeStatement()`, raw SQL, database
 * triggers and association join columns produce no row changes; they still run inside the
 * transaction, are rolled back, and appear in the SQL log. A flush that fails records nothing.
 */
final class OrmChangeCollector
{
    private bool $active = false;
    private bool $truncated = false;
    /** @var list<array<string,mixed>> */
    private array $changes = [];
    /** @var list<array{operation:string,meta:ClassMetadata,entity:object,before:?array,key:?array}> */
    private array $pending = [];

    public function start(): void
    {
        $this->active = true;
        $this->truncated = false;
        $this->changes = [];
        $this->pending = [];
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<array<string,mixed>> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** Doctrine event: the unit of work knows what it is about to write. */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending = [];
        if (!$this->active) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        $meta = static fn (object $entity): ClassMetadata => $args->getObjectManager()->getClassMetadata($entity::class);
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->pending[] = ['operation' => 'insert', 'meta' => $meta($entity), 'entity' => $entity, 'before' => null, 'key' => null];
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $metadata = $meta($entity);
            $before = $this->row($metadata, $entity, $uow->getEntityChangeSet($entity));
            $this->pending[] = ['operation' => 'update', 'meta' => $metadata, 'entity' => $entity, 'before' => $before, 'key' => null];
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $metadata = $meta($entity);
            $this->pending[] = [
                'operation' => 'delete', 'meta' => $metadata, 'entity' => $entity,
                'before' => $this->row($metadata, $entity, []), 'key' => $this->key($metadata, $entity),
            ];
        }
    }

    /** Doctrine event: the flush succeeded, so the pending changes happened. */
    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->pending as $entry) {
            if (count($this->changes) >= Limits::CHANGES) {
                $this->truncated = true;
                break;
            }
            $deleted = $entry['operation'] === 'delete';
            $this->changes[] = [
                'operation' => $entry['operation'],
                'table' => $entry['meta']->getTableName(),
                'key' => $entry['key'] ?? $this->key($entry['meta'], $entry['entity']),
                'before' => $entry['before'],
                'after' => $deleted ? null : $this->row($entry['meta'], $entry['entity'], []),
            ];
        }
        $this->pending = [];
    }

    /**
     * Column values of an entity. When a change set is given, the old values replace the current ones.
     *
     * @param array<string,array{0:mixed,1:mixed}> $changeSet
     * @return array<string,mixed>
     */
    private function row(ClassMetadata $meta, object $entity, array $changeSet): array
    {
        $row = [];
        foreach ($meta->getFieldNames() as $field) {
            $column = $meta->getColumnName($field);
            $value = array_key_exists($field, $changeSet) ? $changeSet[$field][0] : $meta->getFieldValue($entity, $field);
            $row[$column] = SqlRedactor::looksSecret($column) ? Limits::REDACTED : $value;
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function key(ClassMetadata $meta, object $entity): array
    {
        $key = [];
        foreach ($meta->getIdentifierValues($entity) as $field => $value) {
            $column = $meta->hasField($field) ? $meta->getColumnName($field) : $field;
            $key[$column] = SqlRedactor::looksSecret($column) ? Limits::REDACTED : $value;
        }

        return $key;
    }
}
