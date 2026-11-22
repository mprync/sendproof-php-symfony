<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Capture;

use Monolog\LogRecord;
use Sendproof\Core\Capture\Recorder;

/**
 * Monolog processor that copies each record into the request's recorder and leaves it unchanged.
 *
 * Monolog runs processors only for records that some handler will process, so a channel with no
 * handler at all is not captured.
 */
final class LogProcessor
{
    private const LEVELS = [
        'Debug' => 'Debug', 'Info' => 'Information', 'Notice' => 'Information', 'Warning' => 'Warning',
        'Error' => 'Error', 'Critical' => 'Critical', 'Alert' => 'Critical', 'Emergency' => 'Critical',
    ];

    public function __construct(private readonly Recorder $recorder)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (!$this->recorder->isCapturing()) {
            return $record;
        }

        $context = $record->context;
        $exception = null;
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $exception = ['type' => $context['exception']::class, 'message' => $context['exception']->getMessage()];
            unset($context['exception']);
        }
        $this->recorder->log(self::LEVELS[$record->level->name] ?? $record->level->name, $record->message, $record->channel, $context, null, $exception);

        return $record;
    }
}
