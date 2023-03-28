<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\PersonalizationBundle\Targeting\EventListener;

use Pimcore\Bundle\PersonalizationBundle\Targeting\Service\TargetingEnableService;
use Pimcore\Config;
use Pimcore\Event\Cache\FullPage\IgnoredSessionKeysEvent;
use Pimcore\Event\Cache\FullPage\PrepareResponseEvent;
use Pimcore\Event\FullPageCacheEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TargetingSessionBagListener implements EventSubscriberInterface
{
    const TARGETING_BAG_SESSION = 'pimcore_targeting_session';

    const TARGETING_BAG_VISITOR = 'pimcore_targeting_visitor';

    private TargetingEnableService $targetingEnableService;

    public function __construct(protected Config $config, TargetingEnableService $targetingEnableService)
    {
        $this->targetingEnableService = $targetingEnableService;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            FullPageCacheEvents::IGNORED_SESSION_KEYS => 'configureIgnoredSessionKeys',
            FullPageCacheEvents::PREPARE_RESPONSE => 'prepareFullPageCacheResponse',
            // add session support by registering the session configurator and session storage
            KernelEvents::REQUEST => ['onKernelRequest', 127],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if(!$this->targetingEnableService->isTargetingEnabled()) {
            return;
        }

        if(!$this->isEnabled()) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $session = $event->getRequest()->getSession();

        //do not register bags, if session is already started
        if ($session->isStarted()) {
            return;
        }

        $this->configure($session);
    }

    public function configure(SessionInterface $session): void
    {
        $sessionBag = new AttributeBag('_' . self::TARGETING_BAG_SESSION);
        $sessionBag->setName(self::TARGETING_BAG_SESSION);

        $visitorBag = new AttributeBag('_' . self::TARGETING_BAG_VISITOR);
        $visitorBag->setName(self::TARGETING_BAG_VISITOR);

        $session->registerBag($sessionBag);
        $session->registerBag($visitorBag);
    }

    public function configureIgnoredSessionKeys(IgnoredSessionKeysEvent $event): void
    {
        if(!$this->targetingEnableService->isTargetingEnabled()) {
            return;
        }

        if(!$this->isEnabled()) {
            return;
        }

        // configures full page cache to ignore session data in targeting storage
        $event->setKeys(array_merge($event->getKeys(), [
            '_' . self::TARGETING_BAG_SESSION,
            '_' . self::TARGETING_BAG_VISITOR,
        ]));
    }

    /**
     * Removes session cookie from cached response
     *
     * @param PrepareResponseEvent $event
     */
    public function prepareFullPageCacheResponse(PrepareResponseEvent $event): void
    {
        if(!$this->targetingEnableService->isTargetingEnabled()) {
            return;
        }

        if(!$this->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$request->hasSession()) {
            return;
        }

        $sessionName = $request->getSession()->getName();
        if (empty($sessionName)) {
            return;
        }

        $cookies = $response->headers->getCookies();

        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $response->headers->removeCookie(
                    $cookie->getName(),
                    $cookie->getPath(),
                    $cookie->getDomain()
                );
            }
        }
    }

    protected function isEnabled(): bool
    {
        return \Pimcore::getKernel()->getContainer()->getParameter('pimcore_personalization.targeting.session.enabled');
    }

}
