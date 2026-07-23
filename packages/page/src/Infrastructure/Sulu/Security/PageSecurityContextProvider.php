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

namespace Sulu\Page\Infrastructure\Sulu\Security;

use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Page\Infrastructure\Sulu\Admin\PageAdmin;

final class PageSecurityContextProvider implements ResourceSecurityContextProviderInterface
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
    ) {
    }

    public function getResourceKey(): string
    {
        return PageInterface::RESOURCE_KEY;
    }

    public function resolve(string $resourceId): string
    {
        $page = $this->pageRepository->getOneBy(['uuid' => $resourceId]);

        return PageAdmin::getPageSecurityContext($page->getWebspaceKey());
    }
}
