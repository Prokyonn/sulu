<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\EventListener;

use Sulu\Bundle\PageBundle\Document\BasePageDocument;
use Sulu\Bundle\PageBundle\Reference\PageReferenceProvider;
use Sulu\Component\DocumentManager\Event\PersistEvent;
use Sulu\Component\DocumentManager\Event\PublishEvent;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PageReferenceSubscriber implements EventSubscriberInterface
{
    /**
     * @var PageReferenceProvider
     */
    private $pageReferenceProvider;

    public function __construct(PageReferenceProvider $pageReferenceProvider)
    {
        $this->pageReferenceProvider = $pageReferenceProvider;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            Events::PUBLISH => 'onPublish',
            Events::PERSIST => 'onPersist',
            Events::REMOVE => 'onRemove',
        ];
    }

    public function onPublish(PublishEvent $event): void
    {
        $document = $event->getDocument();
        $locale = $event->getLocale();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->pageReferenceProvider->updateReferences($document, $locale);
    }

    public function onPersist(PersistEvent $event): void
    {
        $document = $event->getDocument();
        $locale = $event->getLocale();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->pageReferenceProvider->updateReferences($document, $locale);
    }

    public function onRemove(RemoveEvent $event): void
    {
        $document = $event->getDocument();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->pageReferenceProvider->removeReferences($document);
    }
}
