<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Attribute;

use Sendproof\Core\Stores\Guard;

/**
 * Opts a controller action (or every action of a controller) in to Preview and Observed Send.
 *
 * The action must be safe to run in a rollback-only transaction: every write goes through a
 * Doctrine connection listed in `sendproof.connections`, and every external effect asks
 * `Recorder::tryRecordEffect()` (or the typed variants) before it is delivered.
 *
 * `#[AllowPreview(false, reason: 'Clears the Redis session')]` marks the action `preview: false`
 * instead: an operation the API author knows writes to a guarded store. The workbench then shows
 * "Preview unavailable" with the reason before anyone tries, and a Preview of the action is
 * refused (409, never confirmed) without running it. Send still runs it. Give a reason, the
 * `stores` it writes, or both; the binding never infers the mark.
 *
 * A route may also opt in with the default `_sendproof_preview: true`, or opt out with
 * `_sendproof_preview: false` plus `_sendproof_reason` and `_sendproof_stores`.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class AllowPreview
{
    /** The reason shown in discovery for an unavailable operation. */
    public readonly ?string $reason;

    /**
     * @param list<string> $stores Store ids the operation writes, such as `redis`
     * @throws \InvalidArgumentException when `$enabled` is false and neither a reason nor a store is given
     */
    public function __construct(public readonly bool $enabled = true, ?string $reason = null, public readonly array $stores = [])
    {
        foreach ($stores as $store) {
            if (!is_string($store) || preg_match('/^[a-z][a-z0-9]*$/', $store) !== 1) {
                throw new \InvalidArgumentException('List the stores an unavailable operation writes as store ids such as "redis".');
            }
        }
        if ($enabled) {
            $this->reason = null;

            return;
        }
        $text = $reason ?? ($stores !== [] ? Guard::writeReason($stores[0]) : null);
        if ($text === null || trim($text) === '' || mb_strlen($text) > 512) {
            throw new \InvalidArgumentException('Give an unavailable operation a reason, or the stores it writes.');
        }
        $this->reason = $text;
    }
}
