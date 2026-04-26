<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/** Empty router base URL so generated paths match nginx + PHP front. */
final class LegacyPhpRouteBaseUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32); earlier subscriber runs first.
        return [KernelEvents::REQUEST => ['onKernelRequest', 31]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->router->getContext()->setBaseUrl('');
    }
}
