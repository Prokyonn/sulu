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

interface ReferenceInterface
{
    public function getId(): ?int;

    public function getSourceResourceKey(): string;

    public function setSourceResourceKey(string $sourceResourceKey): ReferenceInterface;

    public function getSourceResourceId(): string;

    public function setSourceResourceId(string $sourceResourceId): ReferenceInterface;

    public function getSourceLocale(): string;

    public function setSourceLocale(string $sourceLocale): ReferenceInterface;

    public function getSourceWorkflowStage(): string;

    public function setSourceWorkflowStage(string $sourceWorkflowStage): ReferenceInterface;

    public function getSourceSecurityContext(): string;

    public function setSourceSecurityContext(string $sourceSecurityContext): ReferenceInterface;

    public function getSourceSecurityObjectType(): string;

    public function setSourceSecurityObjectType(string $sourceSecurityObjectType): ReferenceInterface;

    public function getSourceSecurityObjectId(): string;

    public function setSourceSecurityObjectId(string $sourceSecurityObjectId): ReferenceInterface;

    public function getTargetResourceKey(): string;

    public function setTargetResourceKey(string $targetResourceKey): ReferenceInterface;

    public function getTargetResourceId(): string;

    public function setTargetResourceId(string $targetResourceId): ReferenceInterface;

    public function getTargetSecurityContext(): string;

    public function setTargetSecurityContext(string $targetSecurityContext): ReferenceInterface;

    public function getTargetSecurityObjectType(): string;

    public function setTargetSecurityObjectType(string $targetSecurityObjectType): ReferenceInterface;

    public function getTargetSecurityObjectId(): string;

    public function setTargetSecurityObjectId(string $targetSecurityObjectId): ReferenceInterface;

    public function getReferenceProperty(): string;

    public function setReferenceProperty(string $referenceProperty): ReferenceInterface;

    public function getReferenceGroup(): string;

    public function setReferenceGroup(string $referenceGroup): ReferenceInterface;

    public function getReferenceContext(): string;

    public function setReferenceContext(string $referenceContext): ReferenceInterface;
}
