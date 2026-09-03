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

namespace Sulu\Content\Application\RequestWorkflow;

use Sulu\Bundle\AdminBundle\Exception\MetadataNotFoundException;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;

/**
 * Resolves the request workflow of a content in three rules:
 *
 *   1. The template tag `<tag name="sulu_content.request_workflow" workflow="blog"/>` selects that
 *      workflow. It always wins, its `resources` list is not consulted. `workflow="none"` opts the
 *      template out of review.
 *   2. Without a tag the `default` workflow applies, if it is registered and its `resources` list is
 *      empty or contains the content's resource key.
 *   3. Otherwise no workflow applies and the content is published without a review.
 */
final class RequestWorkflowResolver implements RequestWorkflowResolverInterface
{
    public const TEMPLATE_TAG = 'sulu_content.request_workflow';
    public const TEMPLATE_TAG_ATTRIBUTE = 'workflow';

    // The workflow tag is a structural form attribute, not a translated string, so the locale passed to
    // the metadata provider is irrelevant. We pass 'en' as a deterministic sentinel.
    private const METADATA_LOCALE_FALLBACK = 'en';

    public function __construct(
        private readonly RequestWorkflowRegistryInterface $registry,
        private readonly MetadataProviderRegistry $metadataProviderRegistry,
    ) {
    }

    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     */
    public function resolveForContent(DimensionContentInterface $dimensionContent): ?RequestWorkflow
    {
        $resourceKey = $dimensionContent::getResourceKey();

        if (!$dimensionContent instanceof TemplateInterface) {
            return $this->fallback($resourceKey);
        }

        $templateKey = $dimensionContent->getTemplateKey();
        if (null === $templateKey || '' === $templateKey) {
            return $this->fallback($resourceKey);
        }

        $name = $this->readWorkflowNameFromTemplate($dimensionContent::getTemplateType(), $templateKey);
        if (null === $name) {
            return $this->fallback($resourceKey);
        }

        if (RequestWorkflow::NONE_NAME === $name) {
            return null;
        }

        // Explicit assignment must resolve. A typo in a template tag should fail loudly
        // rather than silently fall back to the default workflow.
        return $this->registry->get($name);
    }

    private function fallback(string $resourceKey): ?RequestWorkflow
    {
        if (!$this->registry->has(RequestWorkflow::DEFAULT_NAME)) {
            return null;
        }

        $workflow = $this->registry->get(RequestWorkflow::DEFAULT_NAME);

        return $workflow->appliesToResource($resourceKey) ? $workflow : null;
    }

    private function readWorkflowNameFromTemplate(string $templateType, string $templateKey): ?string
    {
        try {
            $typed = $this->metadataProviderRegistry
                ->getMetadataProvider('form')
                ->getMetadata($templateType, self::METADATA_LOCALE_FALLBACK, []);
        } catch (MetadataNotFoundException) {
            // No form metadata for this template type, so there is no tag to read. A wrong assignment
            // is still loud, because the registry throws on an unknown workflow name.
            return null;
        }

        if (!$typed instanceof TypedFormMetadata) {
            return null;
        }

        $form = $typed->getForms()[$templateKey] ?? null;
        if (!$form instanceof FormMetadata) {
            return null;
        }

        $tag = $form->getTagsByName(self::TEMPLATE_TAG)[0] ?? null;
        if (null === $tag) {
            return null;
        }

        $value = $tag->getAttribute(self::TEMPLATE_TAG_ATTRIBUTE);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
