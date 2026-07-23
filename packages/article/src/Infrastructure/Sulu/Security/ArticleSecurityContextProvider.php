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

namespace Sulu\Article\Infrastructure\Sulu\Security;

use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Infrastructure\Sulu\Admin\ArticleAdmin;
use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;

final class ArticleSecurityContextProvider implements ResourceSecurityContextProviderInterface
{
    public function getResourceKey(): string
    {
        return ArticleInterface::RESOURCE_KEY;
    }

    public function resolve(string $resourceId): string
    {
        return ArticleAdmin::SECURITY_CONTEXT;
    }
}
