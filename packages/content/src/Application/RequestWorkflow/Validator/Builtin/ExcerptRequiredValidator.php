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

namespace Sulu\Content\Application\RequestWorkflow\Validator\Builtin;

use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationFailure;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Requires the configured set of excerpt fields to be filled. Passes silently when the
 * dimension content does not implement {@see ExcerptInterface}.
 */
final class ExcerptRequiredValidator implements RequestWorkflowValidatorInterface
{
    public const KEY = 'excerpt_required';

    private const SUPPORTED_FIELDS = ['title', 'description', 'more'];

    public function getKey(): string
    {
        return self::KEY;
    }

    public function configure(NodeBuilder $builder): void
    {
        $builder
            ->arrayNode(self::KEY)
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('fields')
                        ->scalarPrototype()->end()
                        ->defaultValue(['title', 'description'])
                        ->validate()
                            ->ifTrue(static function($v): bool {
                                if (!\is_array($v)) {
                                    return false;
                                }
                                /** @var array<int, string> $values */
                                $values = $v;

                                return [] !== \array_diff($values, self::SUPPORTED_FIELDS);
                            })
                            ->thenInvalid('Allowed excerpt fields: ' . \implode(', ', self::SUPPORTED_FIELDS))
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function check(ValidationContext $context): ValidationResult
    {
        $dimensionContent = $context->dimensionContent;
        if (!$dimensionContent instanceof ExcerptInterface) {
            return ValidationResult::pass();
        }

        /** @var array{fields: list<string>} $config */
        $config = $context->validatorConfig;

        $missing = [];
        foreach ($config['fields'] as $field) {
            $value = match ($field) {
                'title' => $dimensionContent->getExcerptTitle(),
                'description' => $dimensionContent->getExcerptDescription(),
                'more' => $dimensionContent->getExcerptMore(),
                default => null,
            };
            if (null === $value || '' === \trim($value)) {
                $missing[] = $field;
            }
        }

        if ([] === $missing) {
            return ValidationResult::pass();
        }

        return ValidationResult::fail(new ValidationFailure(
            self::KEY,
            'sulu_content.workflow_transition_request.excerpt_required.missing',
            ['fields' => \implode(', ', $missing)],
            ['missing' => $missing],
        ));
    }
}
