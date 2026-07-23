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
     * Verify the current user is allowed to bypass the workflow transition request review and
     * publish directly. Reuses the per-resource security context already wired for workflow
     * transition requests; the required permission is `LIVE`.
     *
     * @throws AccessDeniedException when the user lacks the LIVE permission for the resolved context
     */
    public function assertCanBypass(string $resourceKey, string $resourceId): void;
}
