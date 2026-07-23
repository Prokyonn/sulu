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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Content\Application\ContentManager\WorkflowTransitionRequestAwareContentManager;
use Sulu\Content\Application\ContentNormalizer\Normalizer\WorkflowTransitionRequestNormalizer;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestCancelTransitionSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishGuardSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishTransitionSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestTransitionSubscriber;
use Sulu\Content\Application\MessageHandler\ApproveWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\CancelWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\RejectWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluator;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistry;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolver;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\ExcerptRequiredValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\SeoRequiredValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;
use Sulu\Content\Application\Security\BypassReviewAuthorizer;
use Sulu\Content\Application\Security\BypassReviewAuthorizerInterface;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolver;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolverInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestListEnhancer;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestListEnhancerInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactory;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactoryInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Infrastructure\Doctrine\EventListener\CascadeDeleteWorkflowTransitionRequestListener;
use Sulu\Content\Infrastructure\Doctrine\Repository\WorkflowTransitionRequestRepository;
use Sulu\Content\UserInterface\EventListener\WorkflowTransitionRequestExceptionListener;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_content.workflow_transition_request_repository', WorkflowTransitionRequestRepository::class)
        ->args([new Reference('doctrine.orm.entity_manager')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestRepositoryInterface::class, 'sulu_content.workflow_transition_request_repository');

    $services->set('sulu_content.request_workflow_validator.user_approvals', UserApprovalsValidator::class)
        ->tag('sulu_content.request_workflow_validator', ['key' => 'user_approvals'])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.request_workflow_validator.seo_required', SeoRequiredValidator::class)
        ->tag('sulu_content.request_workflow_validator', ['key' => 'seo_required'])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.request_workflow_validator.excerpt_required', ExcerptRequiredValidator::class)
        ->tag('sulu_content.request_workflow_validator', ['key' => 'excerpt_required'])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.request_workflow_registry', RequestWorkflowRegistry::class)
        ->args([tagged_iterator('sulu_content.request_workflow')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(RequestWorkflowRegistryInterface::class, 'sulu_content.request_workflow_registry');

    $services->set('sulu_content.request_workflow_evaluator', RequestWorkflowEvaluator::class)
        ->args([new Reference('sulu_content.request_workflow_registry')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(RequestWorkflowEvaluatorInterface::class, 'sulu_content.request_workflow_evaluator');

    $services->set('sulu_content.request_workflow_resolver', RequestWorkflowResolver::class)
        ->args([
            new Reference('sulu_content.request_workflow_registry'),
            new Reference('sulu_admin.metadata_provider_registry'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(RequestWorkflowResolverInterface::class, 'sulu_content.request_workflow_resolver');

    $services->set('sulu_content.workflow_transition_request_view_factory', WorkflowTransitionRequestViewFactory::class)
        ->args([
            new Reference('sulu_content.request_workflow_evaluator'),
            new Reference('sulu_content.request_workflow_registry'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestViewFactoryInterface::class, 'sulu_content.workflow_transition_request_view_factory');

    $services->set('sulu_content.workflow_transition_request_transition_subscriber', WorkflowTransitionRequestTransitionSubscriber::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage'),
            new Reference('sulu_content.request_workflow_resolver'),
            new Reference('doctrine.orm.entity_manager'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_cancel_transition_subscriber', WorkflowTransitionRequestCancelTransitionSubscriber::class)
        ->args([new Reference('sulu_content.workflow_transition_request_repository')])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_publish_guard_subscriber', WorkflowTransitionRequestPublishGuardSubscriber::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_content.request_workflow_evaluator'),
            new Reference('sulu_content.bypass_review_authorizer'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_publish_transition_subscriber', WorkflowTransitionRequestPublishTransitionSubscriber::class)
        ->args([new Reference('sulu_content.workflow_transition_request_repository')])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_cascade_delete_listener', CascadeDeleteWorkflowTransitionRequestListener::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_aware_content_manager', WorkflowTransitionRequestAwareContentManager::class)
        ->decorate('sulu_content.content_manager')
        ->args([
            new Reference('.inner'),
            new Reference('sulu_content.workflow_transition_request_repository'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.approve_workflow_transition_request_handler', ApproveWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('sulu_content.request_workflow_evaluator'),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.reject_workflow_transition_request_handler', RejectWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('sulu_content.request_workflow_evaluator'),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.cancel_workflow_transition_request_handler', CancelWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage'),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_normalizer', WorkflowTransitionRequestNormalizer::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_content.request_workflow_resolver'),
            new Reference('sulu_content.workflow_transition_request_view_factory'),
        ])
        ->tag('sulu_content.normalizer', ['priority' => 0])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_list_enhancer', WorkflowTransitionRequestListEnhancer::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('doctrine.orm.entity_manager'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestListEnhancerInterface::class, 'sulu_content.workflow_transition_request_list_enhancer');

    $services->set('sulu_content.workflow_transition_request_security_context_resolver', WorkflowTransitionRequestSecurityContextResolver::class)
        ->args([tagged_iterator('sulu_content.workflow_transition_request_security_context_provider')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestSecurityContextResolverInterface::class, 'sulu_content.workflow_transition_request_security_context_resolver');

    $services->set('sulu_content.bypass_review_authorizer', BypassReviewAuthorizer::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_security_context_resolver'),
            new Reference('sulu_security.security_checker'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(BypassReviewAuthorizerInterface::class, 'sulu_content.bypass_review_authorizer');

    $services->set('sulu_content.workflow_transition_request_exception_listener', WorkflowTransitionRequestExceptionListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 10])
        ->tag('sulu.context', ['context' => 'admin']);
};
