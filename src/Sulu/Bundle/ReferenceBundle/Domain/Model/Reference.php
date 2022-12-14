<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ReferenceBundle\Domain\Model;

class Reference implements ReferenceInterface
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $sourceResourceKey;

    /**
     * @var string
     */
    private $sourceResourceId;

    /**
     * @var string
     */
    private $sourceLocale;

    /**
     * @var string
     */
    private $sourceWorkflowStage;

    /**
     * @var string
     */
    private $sourceSecurityContext;

    /**
     * @var string
     */
    private $sourceSecurityObjectType;

    /**
     * @var string
     */
    private $sourceSecurityObjectId;

    /**
     * @var string
     */
    private $targetResourceKey;

    /**
     * @var string
     */
    private $targetResourceId;

    /**
     * @var string
     */
    private $targetSecurityContext;

    /**
     * @var string
     */
    private $targetSecurityObjectType;

    /**
     * @var string
     */
    private $targetSecurityObjectId;

    /**
     * @var string
     */
    private $referenceProperty;

    /**
     * @var string
     */
    private $referenceGroup;

    /**
     * @var string
     */
    private $referenceContext;

    public function getId(): int
    {
        return $this->id;
    }

    public function getSourceResourceKey(): string
    {
        return $this->sourceResourceKey;
    }

    public function setSourceResourceKey(string $sourceResourceKey): ReferenceInterface
    {
        $this->sourceResourceKey = $sourceResourceKey;

        return $this;
    }

    public function getSourceResourceId(): string
    {
        return $this->sourceResourceId;
    }

    public function setSourceResourceId(string $sourceResourceId): ReferenceInterface
    {
        $this->sourceResourceId = $sourceResourceId;

        return $this;
    }

    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    public function setSourceLocale(string $sourceLocale): ReferenceInterface
    {
        $this->sourceLocale = $sourceLocale;

        return $this;
    }

    public function getSourceWorkflowStage(): string
    {
        return $this->sourceWorkflowStage;
    }

    public function setSourceWorkflowStage(string $sourceWorkflowStage): ReferenceInterface
    {
        $this->sourceWorkflowStage = $sourceWorkflowStage;

        return $this;
    }

    public function getSourceSecurityContext(): string
    {
        return $this->sourceSecurityContext;
    }

    public function setSourceSecurityContext(string $sourceSecurityContext): ReferenceInterface
    {
        $this->sourceSecurityContext = $sourceSecurityContext;

        return $this;
    }

    public function getSourceSecurityObjectType(): string
    {
        return $this->sourceSecurityObjectType;
    }

    public function setSourceSecurityObjectType(string $sourceSecurityObjectType): ReferenceInterface
    {
        $this->sourceSecurityObjectType = $sourceSecurityObjectType;

        return $this;
    }

    public function getSourceSecurityObjectId(): string
    {
        return $this->sourceSecurityObjectId;
    }

    public function setSourceSecurityObjectId(string $sourceSecurityObjectId): ReferenceInterface
    {
        $this->sourceSecurityObjectId = $sourceSecurityObjectId;

        return $this;
    }

    public function getTargetResourceKey(): string
    {
        return $this->targetResourceKey;
    }

    public function setTargetResourceKey(string $targetResourceKey): ReferenceInterface
    {
        $this->targetResourceKey = $targetResourceKey;

        return $this;
    }

    public function getTargetResourceId(): string
    {
        return $this->targetResourceId;
    }

    public function setTargetResourceId(string $targetResourceId): ReferenceInterface
    {
        $this->targetResourceId = $targetResourceId;

        return $this;
    }

    public function getTargetSecurityContext(): string
    {
        return $this->targetSecurityContext;
    }

    public function setTargetSecurityContext(string $targetSecurityContext): ReferenceInterface
    {
        $this->targetSecurityContext = $targetSecurityContext;

        return $this;
    }

    public function getTargetSecurityObjectType(): string
    {
        return $this->targetSecurityObjectType;
    }

    public function setTargetSecurityObjectType(string $targetSecurityObjectType): ReferenceInterface
    {
        $this->targetSecurityObjectType = $targetSecurityObjectType;

        return $this;
    }

    public function getTargetSecurityObjectId(): string
    {
        return $this->targetSecurityObjectId;
    }

    public function setTargetSecurityObjectId(string $targetSecurityObjectId): ReferenceInterface
    {
        $this->targetSecurityObjectId = $targetSecurityObjectId;

        return $this;
    }

    public function getReferenceProperty(): string
    {
        return $this->referenceProperty;
    }

    public function setReferenceProperty(string $referenceProperty): ReferenceInterface
    {
        $this->referenceProperty = $referenceProperty;

        return $this;
    }

    public function getReferenceGroup(): string
    {
        return $this->referenceGroup;
    }

    public function setReferenceGroup(string $referenceGroup): ReferenceInterface
    {
        $this->referenceGroup = $referenceGroup;

        return $this;
    }

    public function getReferenceContext(): string
    {
        return $this->referenceContext;
    }

    public function setReferenceContext(string $referenceContext): ReferenceInterface
    {
        $this->referenceContext = $referenceContext;

        return $this;
    }
}
