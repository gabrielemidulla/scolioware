<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Fakes SCRIPT_NAME as the current *.php script for legacy includes. */
final class LegacyScriptNameSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1024]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        $script = basename($path);
        if ($script === '' || !str_ends_with($script, '.php')) {
            $script = 'index.php';
        }
        $_SERVER['SCRIPT_NAME'] = '/' . ltrim($script, '/');
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }
}
