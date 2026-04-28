<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Locale\SwLocale;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/** Align Symfony translator locale with SwLocale (Twig vs __()). */
final class SwLocaleTranslatorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', -100]]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        SwLocale::init();
        $locale = SwLocale::current();
        $event->getRequest()->setLocale($locale);
        $this->translator->setLocale($locale);
    }
}
