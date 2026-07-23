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

namespace Sulu\Snippet\Infrastructure\Sulu\Security;

use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;

final class SnippetSecurityContextProvider implements ResourceSecurityContextProviderInterface
{
    public function getResourceKey(): string
    {
        return SnippetInterface::RESOURCE_KEY;
    }

    public function resolve(string $resourceId): string
    {
        return SnippetAdmin::SECURITY_CONTEXT;
    }
}
