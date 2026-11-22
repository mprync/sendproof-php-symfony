<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Sendproof\Core\Preview\PreviewState;
use Sendproof\Symfony\SendproofManager;

/**
 * Marks the moments that matter inside a previewed or observed request.
 *
 * `kernel.controller` runs after the firewall and access control, so when it fires the caller
 * was authenticated and authorized: that is when the request counts as admitted. The response
 * event means the controller returned. Exceptions are remembered so an unhandled error is never
 * mistaken for an application result.
 */
final class ExecutionListener
{
    private const FAILURE = '_sendproof_failure';
    /** Exception types that are ordinary application outcomes rather than failures. */
    private const HANDLED = [HttpExceptionInterface::class, AccessDeniedException::class, AuthenticationException::class, 'Symfony\Component\Validator\Exception\ValidationFailedException'];

    public function __construct(private readonly SendproofManager $manager)
    {
    }

    public function onController(ControllerEvent $event): void
    {
        $state = $this->state($event->getRequest());
        if ($state === null) {
            return;
        }

        $preview = $event->getRequest()->attributes->get(PreviewListener::MODE) === 'preview';
        if ($state->reached || !$this->manager->authorized()) {
            // A denied or repeated entry must not run the handler; a preview then yields no envelope.
            // An observed send is an ordinary request: it runs untouched and simply records no trace.
            if ($preview) {
                $event->setController(static fn (): Response => new Response('', 403));
            }
            return;
        }

        $state->reached = true;
        $state->owner = $preview ? null : $this->manager->ownerKey($event->getRequest());
        $state->clock->startHandler();
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($this->state($event->getRequest()) !== null) {
            $event->getRequest()->attributes->set(self::FAILURE, $event->getThrowable());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $state = $this->state($event->getRequest());
        if ($state !== null && $state->reached && !$state->completed) {
            $state->clock->endHandler();
            $state->completed = true;
        }
    }

    /** The unhandled error that ended this request, if any. */
    public static function failure(Request $request): ?\Throwable
    {
        $error = $request->attributes->get(self::FAILURE);
        if (!$error instanceof \Throwable) {
            return null;
        }
        foreach (self::HANDLED as $type) {
            if ($error instanceof $type) {
                return null;
            }
        }

        return $error;
    }

    private function state(Request $request): ?PreviewState
    {
        $state = $request->attributes->get(PreviewListener::STATE);

        return $state instanceof PreviewState ? $state : null;
    }
}
