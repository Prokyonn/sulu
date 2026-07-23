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

namespace Sulu\Content\UserInterface\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\View\ViewHandlerInterface;
use Sulu\Component\Rest\AbstractRestController;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Content\Application\Message\ApproveWorkflowTransitionRequestMessage;
use Sulu\Content\Application\Message\CancelWorkflowTransitionRequestMessage;
use Sulu\Content\Application\Message\RejectWorkflowTransitionRequestMessage;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolverInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactoryInterface;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotFoundException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal there should be no need to extend this class
 */
final class WorkflowTransitionRequestController extends AbstractRestController
{
    use HandleTrait;

    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly WorkflowTransitionRequestSecurityContextResolverInterface $securityContextResolver,
        private readonly WorkflowTransitionRequestViewFactoryInterface $viewFactory,
        MessageBusInterface $messageBus,
        ViewHandlerInterface $viewHandler,
        ?TokenStorageInterface $tokenStorage = null,
    ) {
        $this->messageBus = $messageBus;
        parent::__construct($viewHandler, $tokenStorage);
    }

    public function getAction(string $id): Response
    {
        try {
            $workflowTransitionRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $id]);
        } catch (WorkflowTransitionRequestNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        $this->securityChecker->checkPermission(
            $this->securityContextResolver->resolve($workflowTransitionRequest->getResourceKey(), $workflowTransitionRequest->getResourceId()),
            PermissionTypes::VIEW,
        );

        return $this->handleView($this->view($this->viewFactory->build($workflowTransitionRequest)));
    }

    public function postTriggerAction(string $id, Request $request): Response
    {
        $action = $request->query->get('action');
        if (null === $action || '' === $action) {
            throw new BadRequestHttpException('The "action" query parameter is required.');
        }

        try {
            $workflowTransitionRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $id]);
        } catch (WorkflowTransitionRequestNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        $this->checkActionPermission($workflowTransitionRequest, $action);

        $comment = $this->getComment($request);

        $message = match ($action) {
            'approve' => new ApproveWorkflowTransitionRequestMessage($id, $comment),
            'reject' => new RejectWorkflowTransitionRequestMessage($id, $comment),
            'cancel' => new CancelWorkflowTransitionRequestMessage($id),
            default => throw new BadRequestHttpException(\sprintf('Unrecognized action "%s".', (string) $action)),
        };

        /** @var WorkflowTransitionRequest $workflowTransitionRequest */
        $workflowTransitionRequest = $this->handle($message);
        $this->entityManager->flush();

        return $this->handleView($this->view($this->viewFactory->build($workflowTransitionRequest)));
    }

    private function checkActionPermission(WorkflowTransitionRequest $workflowTransitionRequest, string $action): void
    {
        $securityContext = $this->securityContextResolver->resolve(
            $workflowTransitionRequest->getResourceKey(),
            $workflowTransitionRequest->getResourceId(),
        );

        $permission = match ($action) {
            'approve', 'reject' => PermissionTypes::REVIEW,
            'cancel' => PermissionTypes::EDIT,
            default => PermissionTypes::VIEW,
        };

        $this->securityChecker->checkPermission($securityContext, $permission);
    }

    private function getComment(Request $request): ?string
    {
        /** @var string|null $comment */
        $comment = $request->request->get('comment');

        return null === $comment || '' === $comment ? null : $comment;
    }
}
