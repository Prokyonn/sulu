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

namespace Sulu\Content\Infrastructure\Sulu\Admin;

use Sulu\Bundle\AdminBundle\Admin\View\DropdownToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

interface ContentViewBuilderFactoryInterface
{
    /**
     * @template T of DimensionContentInterface
     *
     * @param class-string<ContentRichEntityInterface<T>> $contentRichEntityClass
     *
     * @return array<string, ToolbarAction>
     */
    public function getDefaultToolbarActions(
        string $contentRichEntityClass
    ): array;

    /**
     * Workflow-transition-request `save` and `approval` dropdowns, to be merged over the default
     * toolbar actions. The resource key decides whether the `default` workflow covers new entities.
     * Pass content-type specific permission conditions when the defaults (plain `_permissions`) do
     * not apply, as they do not for webspace-scoped pages.
     *
     * @return array{save: DropdownToolbarAction, approval: DropdownToolbarAction}
     */
    public function getWorkflowTransitionRequestToolbarActions(
        string $resourceKey,
        string $saveVisibleCondition = '(!_permissions || _permissions.edit)',
        string $publishVisibleCondition = '(!_permissions || _permissions.live)',
        string $reviewVisibleCondition = '(!_permissions || _permissions.review)',
    ): array;

    /**
     * @template T of DimensionContentInterface
     *
     * @param class-string<ContentRichEntityInterface<T>> $contentRichEntityClass
     * @param array<string, ToolbarAction> $toolbarActions
     *
     * @return ViewBuilderInterface[]
     */
    public function createViews(
        string $contentRichEntityClass,
        string $editParentView,
        ?string $addParentView = null,
        ?string $securityContext = null,
        ?array $toolbarActions = null
    ): array;
}
