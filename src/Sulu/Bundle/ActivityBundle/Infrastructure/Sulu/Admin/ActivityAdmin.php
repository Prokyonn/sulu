<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ActivityBundle\Infrastructure\Sulu\Admin;

use Sulu\Bundle\ActivityBundle\Domain\Model\ActivityInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class ActivityAdmin extends Admin
{
    const SECURITY_CONTEXT = 'sulu.activities.activities';

    const LIST_VIEW = 'sulu_activity.activities.list';

    /**
     * @var ViewBuilderFactoryInterface
     */
    private $viewBuilderFactory;

    /**
     * @var SecurityCheckerInterface
     */
    private $securityChecker;

    public function __construct(
        ViewBuilderFactoryInterface $viewBuilderFactory,
        SecurityCheckerInterface $securityChecker
    ) {
        $this->viewBuilderFactory = $viewBuilderFactory;
        $this->securityChecker = $securityChecker;
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $activitiesNavigationItem = new NavigationItem('sulu_activity.activities');
        $activitiesNavigationItem->setPosition(100);
        $activitiesNavigationItem->setView(static::LIST_VIEW);

        $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($activitiesNavigationItem);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $viewCollection->add(
            $this->viewBuilderFactory->createListViewBuilder(static::LIST_VIEW, '/activities')
                ->setResourceKey(ActivityInterface::RESOURCE_KEY)
                ->setListKey('activities')
                ->setTitle('sulu_activity.activities')
                ->addListAdapters(['table'])
                ->disableSearching()
                ->disableSelection()
                ->disableColumnOptions()
                ->disableFiltering()
                ->addAdapterOptions([
                    'table' => [
                        'skin' => 'flat',
                        'show_header' => false,
                    ],
                ])
                ->addToolbarActions([])
        );
    }

    public function getSecurityContexts()
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Activities' => [
                    static::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                    ],
                ],
            ],
        ];
    }
}
