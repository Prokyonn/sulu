<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Content\Application\ContentWorkflow;

use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

interface ContentWorkflowInterface
{
    public const CONTENT_RICH_ENTITY_CONTEXT_KEY = 'contentRichEntity';
    public const DIMENSION_CONTENT_COLLECTION_CONTEXT_KEY = 'dimensionContentCollection';
    public const DIMENSION_ATTRIBUTES_CONTEXT_KEY = 'dimensionAttributes';

    /**
     * Workflow context key that bypasses guard subscribers (e.g. the workflow-transition-request publish guard)
     * for system-driven transitions like CLI commands, fixtures, or migrations.
     */
    public const FORCE_CONTEXT_KEY = 'force';

    /**
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $contentRichEntity
     * @param mixed[] $dimensionAttributes
     * @param array<string, mixed> $context additional context forwarded to workflow guard subscribers
     *
     * @return T
     */
    public function apply(
        ContentRichEntityInterface $contentRichEntity,
        array $dimensionAttributes,
        string $transitionName,
        array $context = []
    ): DimensionContentInterface;
}
