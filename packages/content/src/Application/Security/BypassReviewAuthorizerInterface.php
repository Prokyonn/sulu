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

namespace Sulu\Content\Application\Security;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

interface BypassReviewAuthorizerInterface
{
    /**
     * @throws AccessDeniedException when the user lacks the LIVE permission for the resolved context
     */
    public function assertCanBypass(string $resourceKey, string $resourceId): void;
}
