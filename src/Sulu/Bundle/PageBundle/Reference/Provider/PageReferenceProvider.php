<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\Reference\Provider;

use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Reference\Provider\AbstractDocumentReferenceProvider;
use Sulu\Bundle\PageBundle\Document\BasePageDocument;
use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;
use Sulu\Component\Content\Compat\Structure;
use Sulu\Component\Content\Compat\StructureManagerInterface;
use Sulu\Component\Content\ContentTypeManagerInterface;
use Sulu\Component\Content\Document\Behavior\WebspaceBehavior;
use Sulu\Component\Content\Extension\ExtensionManagerInterface;

/**
 * @final
 *
 * @internal
 */
class PageReferenceProvider extends AbstractDocumentReferenceProvider
{
    public function __construct(
        ContentTypeManagerInterface $contentTypeManager,
        StructureManagerInterface $structureManager,
        ExtensionManagerInterface $extensionManager,
        ReferenceRepositoryInterface $referenceRepository,
    ) {
        parent::__construct(
            $contentTypeManager,
            $structureManager,
            $extensionManager,
            $referenceRepository,
            Structure::TYPE_PAGE,
            '' // TODO check what we need here
        );
    }

    public static function getResourceKey(): string
    {
        return BasePageDocument::RESOURCE_KEY;
    }

    protected function getReferenceViewAttributes($document, string $locale): array
    {
        $referenceViewAttributes = parent::getReferenceViewAttributes($document, $locale);

        if (!$document instanceof WebspaceBehavior) {
            return $referenceViewAttributes;
        }

        return \array_merge($referenceViewAttributes, [
            'webspace' => $document->getWebspaceName(),
        ]);
    }
}
