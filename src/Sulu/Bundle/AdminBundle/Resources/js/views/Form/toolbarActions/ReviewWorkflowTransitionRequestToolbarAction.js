// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import ResourceRequester from '../../../services/ResourceRequester';
import userStore from '../../../stores/userStore';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';
import type {ReviewerStatus} from '../components/types';

export default class ReviewWorkflowTransitionRequestToolbarAction extends AbstractFormToolbarAction {
    @observable open: boolean = false;

    @computed get canAct(): boolean {
        const creatorId = this.resourceFormStore.data.activeWorkflowTransitionRequest?.createdBy?.id;

        return String(creatorId) !== String(userStore.user?.id);
    }

    @computed get userDecision(): ?ReviewerStatus {
        const reviewers = this.resourceFormStore.data.activeWorkflowTransitionRequest?.reviewers || [];
        const userId = String(userStore.user?.id);
        const ownRow = reviewers.find(
            (reviewer) => reviewer.type === 'user' && String(reviewer.reviewer?.id) === userId
        );

        return ownRow ? ownRow.status : undefined;
    }

    /** Resolving the request is the whole point of the lock, so this action survives it. */
    get enabledWhileLocked(): boolean {
        return true;
    }

    @action handleOpen = () => {
        this.open = true;
    };

    @action handleClose = () => {
        this.open = false;
    };

    handleApprove = async(comment: ?string) => {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return;
        }

        await ResourceRequester.post(
            'workflow_transition_requests',
            {comment: comment || null},
            {id: request.id, action: 'approve'}
        );
        // The decision is stored on the request, which the form only sees through the resource.
        this.resourceFormStore.resourceStore.reload();
    };

    handleReject = async(comment: string) => {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return;
        }

        await ResourceRequester.post(
            'workflow_transition_requests',
            {comment},
            {id: request.id, action: 'reject'}
        );
        this.resourceFormStore.resourceStore.reload();
    };

    handleRetry = async(validatorKey: string) => {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return;
        }

        await ResourceRequester.post(
            'workflow_transition_requests',
            {},
            {id: request.id, action: 'retry', validator: validatorKey}
        );
        this.resourceFormStore.resourceStore.reload();
    };

    getNode(index: ?number) {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return null;
        }

        return (
            <WorkflowTransitionRequestReviewOverlay
                canAct={this.canAct}
                key={`workflow-transition-request-review-${index ?? 0}`}
                onApprove={this.handleApprove}
                onClose={this.handleClose}
                onReject={this.handleReject}
                onRetry={this.handleRetry}
                open={this.open}
                request={request}
                userDecision={this.userDecision}
            />
        );
    }

    getToolbarItemConfig() {
        const {
            disabled_condition: disabledCondition,
            visible_condition: visibleCondition,
        } = this.options;

        const visibleConditionFulfilled = !visibleCondition || jexl.evalSync(visibleCondition, this.conditionData);

        if (!visibleConditionFulfilled) {
            return;
        }

        if (!this.resourceFormStore.data.activeWorkflowTransitionRequest) {
            return;
        }

        const disabled = disabledCondition ? jexl.evalSync(disabledCondition, this.conditionData) : false;

        return {
            label: translate('sulu_content.workflow_transition_request.review_action'),
            disabled,
            onClick: this.handleOpen,
            type: 'button',
        };
    }
}
