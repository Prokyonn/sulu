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

namespace Sulu\Page\Infrastructure\Doctrine\Hydrator;

use Gedmo\Tree\Hydrator\ORM\TreeObjectHydrator as GedmoTreeObjectHydrator;

/**
 * Workaround for https://github.com/doctrine-extensions/DoctrineExtensions/issues/2921.
 *
 * Gedmo's TreeObjectHydrator accesses `mappedBy` on all associations without
 * checking whether the association is an inverse side first. In Doctrine ORM 3.x,
 * only inverse-side mappings have `mappedBy`; owning-side mappings throw an
 * OutOfRangeException.
 *
 * This override can be removed once the upstream fix is merged and released.
 *
 * @internal
 */
class TreeObjectHydrator extends GedmoTreeObjectHydrator
{
    protected function getChildrenField($entityClass)
    {
        $meta = $this->getClassMetadata($entityClass);

        // Access private $parentField from parent class via reflection
        $parentFieldReflection = new \ReflectionProperty(GedmoTreeObjectHydrator::class, 'parentField');
        $parentField = $parentFieldReflection->getValue($this);

        foreach ($meta->getReflectionProperties() as $property) {
            if (!$meta->hasAssociation($property->getName())) {
                continue;
            }

            $associationMapping = $meta->getAssociationMapping($property->getName());

            if ($associationMapping->isOwningSide()) {
                continue;
            }

            if ($associationMapping->mappedBy !== $parentField) {
                continue;
            }

            return $associationMapping->fieldName;
        }

        throw new \Gedmo\Exception\InvalidMappingException(
            'The children property could not found. It is identified through the `mappedBy` annotation to your parent property.'
        );
    }
}
