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

use Sulu\Bundle\PageBundle\Admin\PageAdmin;
use Sulu\Bundle\PageBundle\Document\BasePageDocument;
use Sulu\Bundle\ReferenceBundle\Application\ReferenceCollector\ReferenceCollector;
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

    public function __construct(
        ContentTypeManagerInterface $contentTypeManager,
        StructureManagerInterface $structureManager,
        ReferenceRepositoryInterface $referenceRepository
    ) {
        $this->contentTypeManager = $contentTypeManager;
        $this->structureManager = $structureManager;
        $this->referenceRepository = $referenceRepository;
    }

    public function collectReferences(BasePageDocument $document, string $locale): ReferenceCollector
    {
        $referenceCollection = new ReferenceCollector($this->referenceRepository);

        $referenceCollection
            ->setReferenceResourceKey(BasePageDocument::RESOURCE_KEY)
            ->setReferenceResourceId($document->getUuid())
            ->setReferenceLocale($locale)
            ->setReferenceSecurityContext(PageAdmin::getPageSecurityContext($document->getWebspaceName()))
            ->setReferenceSecurityObjectType(SecurityBehavior::class)
            ->setReferenceSecurityObjectId($document->getUuid())
            ->setReferenceWorkflowStage((int) $document->getWorkflowStage());

        $structure = $document->getStructure();
        $templateStructure = $this->structureManager->getStructure($document->getStructureType(), Structure::TYPE_PAGE);
        foreach ($templateStructure->getProperties(true) as $property) {
            $contentType = $this->contentTypeManager->get($property->getContentTypeName());

            if (!$contentType instanceof ReferenceContentTypeInterface) {
                continue;
            }

            $contentType->getReferences($structure->getProperty($property->getName()), $referenceCollection);
        }

        return $referenceCollection;
    }
}
