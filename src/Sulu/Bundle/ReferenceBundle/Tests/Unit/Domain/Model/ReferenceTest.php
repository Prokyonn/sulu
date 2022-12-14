<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ReferenceBundle\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\ReferenceBundle\Domain\Model\Reference;

class ReferenceTest extends TestCase
{
    public function testGetId(): void
    {
        $reference = new Reference();
        static::assertNull($reference->getId());
    }

    public function testGetSetSourceResourceKey(): void
    {
        $reference = new Reference();
        $reference->setSourceResourceKey('pages');
        static::assertSame('pages', $reference->getSourceResourceKey());
    }

    public function testGetSetSourceResourceId(): void
    {
        $reference = new Reference();
        $reference->setSourceResourceId('pages');
        static::assertSame('pages', $reference->getSourceResourceId());
    }

    public function testGetSetSourceLocale(): void
    {
        $reference = new Reference();
        $reference->setSourceLocale('pages');
        static::assertSame('pages', $reference->getSourceLocale());
    }

    public function testGetSetSourceWorkflowStage(): void
    {
        $reference = new Reference();
        $reference->setSourceWorkflowStage('pages');
        static::assertSame('pages', $reference->getSourceWorkflowStage());
    }

    public function testGetSetSourceSecurityContext(): void
    {
        $reference = new Reference();
        $reference->setSourceSecurityContext('pages');
        static::assertSame('pages', $reference->getSourceSecurityContext());
    }

    public function testGetSetSourceSecurityObjectType(): void
    {
        $reference = new Reference();
        $reference->setSourceSecurityObjectType('pages');
        static::assertSame('pages', $reference->getSourceSecurityObjectType());
    }

    public function testGetSetSourceSecurityObjectId(): void
    {
        $reference = new Reference();
        $reference->setSourceSecurityObjectId('pages');
        static::assertSame('pages', $reference->getSourceSecurityObjectId());
    }

    public function testGetSetTargetResourceKey(): void
    {
        $reference = new Reference();
        $reference->setTargetResourceKey('pages');
        static::assertSame('pages', $reference->getTargetResourceKey());
    }

    public function testGetSetTargetResourceId(): void
    {
        $reference = new Reference();
        $reference->setTargetResourceId('pages');
        static::assertSame('pages', $reference->getTargetResourceId());
    }

    public function testGetSetTargetSecurityContext(): void
    {
        $reference = new Reference();
        $reference->setTargetSecurityContext('pages');
        static::assertSame('pages', $reference->getTargetSecurityContext());
    }

    public function testGetSetTargetSecurityObjectType(): void
    {
        $reference = new Reference();
        $reference->setTargetSecurityObjectType('pages');
        static::assertSame('pages', $reference->getTargetSecurityObjectType());
    }

    public function testGetSetTargetSecurityObjectId(): void
    {
        $reference = new Reference();
        $reference->setTargetSecurityObjectId('pages');
        static::assertSame('pages', $reference->getTargetSecurityObjectId());
    }

    public function testGetSetReferenceProperty(): void
    {
        $reference = new Reference();
        $reference->setReferenceProperty('pages');
        static::assertSame('pages', $reference->getReferenceProperty());
    }

    public function testGetSetReferenceGroup(): void
    {
        $reference = new Reference();
        $reference->setReferenceGroup('pages');
        static::assertSame('pages', $reference->getReferenceGroup());
    }

    public function testGetSetReferenceContext(): void
    {
        $reference = new Reference();
        $reference->setReferenceContext('pages');
        static::assertSame('pages', $reference->getReferenceContext());
    }
}
