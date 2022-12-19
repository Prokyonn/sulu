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

use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(
        PageReferenceProvider $pageReferenceProvider,
        EntityManagerInterface $entityManager
    ) {
        $this->pageReferenceProvider = $pageReferenceProvider;
        $this->entityManager = $entityManager;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            Events::PUBLISH => 'publish',
            Events::PERSIST => 'persist',
            Events::REMOVE => 'remove',
        ];
    }

    public function updateReferences(BasePageDocument $document, string $locale): void
    {
        $this->pageReferenceProvider->collectReferences($document, $locale);

        $this->entityManager->flush();
    }

    public function onPublish(PublishEvent $event): void
    {
        $document = $event->getDocument();
        $locale = $event->getLocale();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->updateReferences($document, $locale);
    }

    public function persist(PersistEvent $event): void
    {
        $document = $event->getDocument();
        $locale = $event->getLocale();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->updateReferences($document, $locale);
    }

    public function remove(RemoveEvent $event): void
    {
        $document = $event->getDocument();

        if (!$document instanceof BasePageDocument) {
            return;
        }

        $this->pageReferenceProvider->removeReferences($document);

        $this->entityManager->flush();
    }
}
