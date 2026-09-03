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

use Sulu\Content\Application\ContentNormalizer\Normalizer\WorkflowTransitionRequestNormalizer;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestCancelTransitionSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishGuardSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishTransitionSubscriber;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestTransitionSubscriber;
use Sulu\Content\Application\MessageHandler\ApproveWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\RejectWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\RetryWorkflowTransitionRequestValidationMessageHandler;
use Sulu\Content\Application\MessageHandler\ValidateWorkflowTransitionRequestFailureListener;
use Sulu\Content\Application\MessageHandler\ValidateWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\WorkerState;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\Builtin\ExcerptRequiredPrevalidator;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\Builtin\SeoRequiredPrevalidator;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistry;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolver;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\Security\BypassReviewAuthorizer;
use Sulu\Content\Application\Security\BypassReviewAuthorizerInterface;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolver;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolverInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\ContentReviewLock;
use Sulu\Content\Application\WorkflowTransitionRequest\ContentReviewLockInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactory;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactoryInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Infrastructure\Doctrine\EventListener\CascadeDeleteWorkflowTransitionRequestListener;
use Sulu\Content\Infrastructure\Doctrine\Repository\WorkflowTransitionRequestRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('sulu_content.workflow_transition_request_repository', WorkflowTransitionRequestRepository::class)
        ->args([new Reference('doctrine.orm.entity_manager')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestRepositoryInterface::class, 'sulu_content.workflow_transition_request_repository');

    $services->set('sulu_content.request_workflow_prevalidator.seo_required', SeoRequiredPrevalidator::class)
        ->tag('sulu_content.request_workflow_prevalidator')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.request_workflow_prevalidator.excerpt_required', ExcerptRequiredPrevalidator::class)
        ->tag('sulu_content.request_workflow_prevalidator')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.request_workflow_registry', RequestWorkflowRegistry::class)
        ->args([
            '%sulu_content.request_workflows%',
            tagged_locator('sulu_content.request_workflow_validator', indexAttribute: 'key', defaultIndexMethod: 'getKey'),
            tagged_locator('sulu_content.request_workflow_prevalidator', indexAttribute: 'key', defaultIndexMethod: 'getKey'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(RequestWorkflowRegistryInterface::class, 'sulu_content.request_workflow_registry');

    $services->set('sulu_content.request_workflow_resolver', RequestWorkflowResolver::class)
        ->args([
            new Reference('sulu_content.request_workflow_registry'),
            new Reference('sulu_admin.metadata_provider_registry'),
        ])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(RequestWorkflowResolverInterface::class, 'sulu_content.request_workflow_resolver');

    $services->set('sulu_content.workflow_transition_request_view_factory', WorkflowTransitionRequestViewFactory::class)
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(WorkflowTransitionRequestViewFactoryInterface::class, 'sulu_content.workflow_transition_request_view_factory');

    $services->set('sulu_content.workflow_transition_request_transition_subscriber', WorkflowTransitionRequestTransitionSubscriber::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage'),
            new Reference('sulu_content.request_workflow_resolver'),
            new Reference('sulu_message_bus'),
            new Reference('translator'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_cancel_transition_subscriber', WorkflowTransitionRequestCancelTransitionSubscriber::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage'),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_publish_guard_subscriber', WorkflowTransitionRequestPublishGuardSubscriber::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_content.request_workflow_resolver'),
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

    $services->set('sulu_content.approve_workflow_transition_request_handler', ApproveWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.reject_workflow_transition_request_handler', RejectWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.retry_workflow_transition_request_validation_handler', RetryWorkflowTransitionRequestValidationMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_message_bus'),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.validate_workflow_transition_request_handler', ValidateWorkflowTransitionRequestMessageHandler::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_content.request_workflow_registry'),
            new Reference('logger'),
            new Reference('sulu_content.worker_state'),
        ])
        ->tag('messenger.message_handler')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.worker_state', WorkerState::class)
        ->tag('kernel.event_listener', ['event' => WorkerStartedEvent::class, 'method' => 'onWorkerStarted'])
        ->tag('kernel.event_listener', ['event' => WorkerStoppedEvent::class, 'method' => 'onWorkerStopped'])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.validate_workflow_transition_request_failure_listener', ValidateWorkflowTransitionRequestFailureListener::class)
        ->args([new Reference('sulu_content.workflow_transition_request_repository')])
        ->tag('kernel.event_listener', ['event' => WorkerMessageFailedEvent::class])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.workflow_transition_request_normalizer', WorkflowTransitionRequestNormalizer::class)
        ->args([
            new Reference('sulu_content.workflow_transition_request_repository'),
            new Reference('sulu_content.request_workflow_resolver'),
            new Reference('sulu_content.workflow_transition_request_view_factory'),
        ])
        ->tag('sulu_content.normalizer', ['priority' => 0])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set('sulu_content.content_review_lock', ContentReviewLock::class)
        ->args([new Reference('sulu_content.workflow_transition_request_repository')])
        ->tag('sulu.context', ['context' => 'admin']);

    $services->alias(ContentReviewLockInterface::class, 'sulu_content.content_review_lock');

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
};
