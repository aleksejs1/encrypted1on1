<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the active request's locale from the frontend's `X-Locale` header
 * (the currently-displayed UI language, Phase 6h — can differ from the
 * browser's Accept-Language) so every controller's `TranslatorInterface::trans()`
 * call picks it up implicitly. Must run after routing but before Symfony's
 * built-in LocaleAwareListener (priority 15) syncs the request locale onto the
 * Translator — verified against vendor/symfony/http-kernel/EventListener/
 * {LocaleListener,LocaleAwareListener}.php, not assumed. An invalid/missing
 * header is silently ignored, same as ActivationController's locale-at-signup
 * field — falls back to the configured default_locale ('en').
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
class RequestLocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $locale = $event->getRequest()->headers->get('X-Locale');
        if (\is_string($locale) && \in_array($locale, User::SUPPORTED_LOCALES, true)) {
            $event->getRequest()->setLocale($locale);
        }
    }
}
