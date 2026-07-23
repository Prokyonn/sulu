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

use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BypassReviewAuthorizer implements BypassReviewAuthorizerInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestSecurityContextResolverInterface $securityContextResolver,
        private readonly SecurityCheckerInterface $securityChecker,
    ) {
    }

    public function assertCanBypass(string $resourceKey, string $resourceId): void
    {
        $context = $this->securityContextResolver->resolve($resourceKey, $resourceId);

        // LIVE (not REVIEW): bypassing the review workflow publishes directly, so the caller must
        // already hold publish-level rights — REVIEW alone would let reviewers bypass each other.
        if (!$this->securityChecker->hasPermission($context, PermissionTypes::LIVE)) {
            throw new AccessDeniedException(\sprintf(
                'Bypassing the workflow transition request review requires the "%s" permission on "%s".',
                PermissionTypes::LIVE,
                $context,
            ));
        }
    }
}
