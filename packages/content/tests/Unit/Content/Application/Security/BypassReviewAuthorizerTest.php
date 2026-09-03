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

namespace Sulu\Content\Tests\Unit\Content\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Content\Application\Security\BypassReviewAuthorizer;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolverInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(BypassReviewAuthorizer::class)]
class BypassReviewAuthorizerTest extends TestCase
{
    use ProphecyTrait;

    private const RESOURCE_KEY = 'pages';
    private const RESOURCE_ID = 'page-uuid';
    private const SECURITY_CONTEXT = 'sulu.webspaces.example';

    /**
     * @var ObjectProphecy<SecurityCheckerInterface>
     */
    private ObjectProphecy $securityChecker;

    private function makeAuthorizer(
        bool $hasLivePermission,
    ): BypassReviewAuthorizer {
        $contextResolver = $this->prophesize(WorkflowTransitionRequestSecurityContextResolverInterface::class);
        $contextResolver
            ->resolve(self::RESOURCE_KEY, self::RESOURCE_ID)
            ->willReturn(self::SECURITY_CONTEXT);

        $this->securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $this->securityChecker
            ->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::LIVE)
            ->willReturn($hasLivePermission);

        return new BypassReviewAuthorizer($contextResolver->reveal(), $this->securityChecker->reveal());
    }

    public function testPassesSilentlyWhenUserHasLivePermission(): void
    {
        $authorizer = $this->makeAuthorizer(hasLivePermission: true);

        $this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::LIVE)->shouldBeCalled();

        $authorizer->assertCanBypass(self::RESOURCE_KEY, self::RESOURCE_ID);
    }

    public function testThrowsAccessDeniedExceptionWhenUserLacksLivePermission(): void
    {
        $authorizer = $this->makeAuthorizer(hasLivePermission: false);

        $this->expectException(AccessDeniedException::class);
        $authorizer->assertCanBypass(self::RESOURCE_KEY, self::RESOURCE_ID);
    }
}
