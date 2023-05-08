<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\Reference;

use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\PageBundle\Admin\PageAdmin;
use Sulu\Bundle\PageBundle\Document\BasePageDocument;
use Sulu\Bundle\ReferenceBundle\Application\Collector\ReferenceCollector;
use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;
use Sulu\Bundle\ReferenceBundle\Infrastructure\Sulu\ContentType\ReferenceContentTypeInterface;
use Sulu\Component\Content\Compat\Structure;
use Sulu\Component\Content\Compat\StructureManagerInterface;
use Sulu\Component\Content\ContentTypeManagerInterface;
use Sulu\Component\Content\Document\Behavior\SecurityBehavior;

class PageReferenceProvider
{
    /**
     * @var ContentTypeManagerInterface
     */
    private $contentTypeManager;

    /**
     * @var StructureManagerInterface
     */
    private $structureManager;

    /**
     * @var ReferenceRepositoryInterface
     */
    private $referenceRepository;

    /**
     * @var DocumentInspector
     */
    private $documentInspector;

    public function __construct(
        ContentTypeManagerInterface $contentTypeManager,
        StructureManagerInterface $structureManager,
        ReferenceRepositoryInterface $referenceRepository,
        DocumentInspector $documentInspector
    ) {
        $this->contentTypeManager = $contentTypeManager;
        $this->structureManager = $structureManager;
        $this->referenceRepository = $referenceRepository;
        $this->documentInspector = $documentInspector;
    }

    public function updateReferences(BasePageDocument $document, string $locale): ReferenceCollector
    {
        $referenceCollector = new ReferenceCollector(
            $this->referenceRepository,
            BasePageDocument::RESOURCE_KEY,
            $document->getUuid(),
            $locale,
            $document->getTitle(),
            PageAdmin::getPageSecurityContext($document->getWebspaceName()),
            $document->getUuid(),
            SecurityBehavior::class,
            (int) $document->getWorkflowStage()
        );

        $structure = $document->getStructure();
        $templateStructure = $this->structureManager->getStructure($document->getStructureType(), Structure::TYPE_PAGE);
        foreach ($templateStructure->getProperties(true) as $property) {
            $contentType = $this->contentTypeManager->get($property->getContentTypeName());

            if (!$contentType instanceof ReferenceContentTypeInterface) {
                continue;
            }

            $contentType->getReferences($structure->getProperty($property->getName()), $referenceCollector);
        }

        $referenceCollector->persistReferences();

        return $referenceCollector;
    }

    public function removeReferences(BasePageDocument $document, ?string $locale = null): void
    {
        $locales = $locale ? [$locale] : $this->documentInspector->getLocales($document);

        foreach ($locales as $locale) {
            $this->referenceRepository->removeByReferenceResourceKeyAndId(
                BasePageDocument::RESOURCE_KEY,
                $document->getUuid(),
                $locale
            );
        }
    }
}
