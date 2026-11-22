<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Capture;

use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Sendproof\Core\Capture\Recorder;

/** Records outgoing mail and rejects it while a preview is running. */
final class MailListener
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$this->recorder->isCapturing() || !$message instanceof Email) {
            return;
        }

        $addresses = static fn (array $list): array => array_map(static fn ($address): string => $address->getAddress(), $list);
        $suppress = $this->recorder->tryRecordEmail(
            'Mail: ' . $message->getSubject(),
            $addresses($message->getFrom())[0] ?? '',
            $addresses($message->getTo()),
            $addresses($message->getCc()),
            (string) $message->getSubject(),
            (string) $message->getTextBody(),
            $message->getHtmlBody() === null ? null : (string) $message->getHtmlBody(),
        );
        if ($suppress) {
            $event->reject();
        }
    }
}
