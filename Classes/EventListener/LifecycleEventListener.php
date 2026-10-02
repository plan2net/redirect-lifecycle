<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\EventListener;

use Plan2net\RedirectLifecycle\Service\RedirectLifecycle;
use TYPO3\CMS\Redirects\Event\ModifyAutoCreateRedirectRecordBeforePersistingEvent;
use TYPO3\CMS\Redirects\Event\RedirectWasHitEvent;

final class LifecycleEventListener
{
    public function __construct(private readonly RedirectLifecycle $lifecycle) {}

    public function onAutomaticCreation(ModifyAutoCreateRedirectRecordBeforePersistingEvent $event): void
    {
        // Core-generated expiry is managed; TYPO3 13+ also supplies the unmanaged database default.
        $event->setRedirectRecord($this->lifecycle->prepareCreation(
            $event->getRedirectRecord(), 1, $event->getSlugRedirectChangeItem()->getSite(),
        ));
    }

    public function onHit(RedirectWasHitEvent $event): void
    {
        $record = $event->getMatchedRedirect();
        $dates = $this->lifecycle->extendOnHit((int)($record['uid'] ?? 0));
        if ($dates !== null) {
            $event->setMatchedRedirect(array_replace($record, $dates));
        }
    }
}
