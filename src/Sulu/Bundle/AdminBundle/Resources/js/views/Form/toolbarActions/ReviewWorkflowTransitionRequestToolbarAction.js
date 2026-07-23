// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import ResourceRequester from '../../../services/ResourceRequester';
import userStore from '../../../stores/userStore';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

/**
 * Renders a "Review" button + an overlay that lists the current reviewer state and lets the
 * user submit an approve/reject decision with an optional comment. The toolbar is gated by
 * the visible_condition declared in the PHP-side Admin classes (typically
 * `(_permissions.review) && !!activeWorkflowTransitionRequest`).
 *
 * After the overlay is submitted the form store reloads so the UI reflects the new reviewer
 * row immediately.
 */
export default class ReviewWorkflowTransitionRequestToolbarAction extends AbstractFormToolbarAction {
    @observable open: boolean = false;

    @computed get canAct(): boolean {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        const creatorId = request?.createdBy?.id;
        const currentUserId = userStore.user?.id;

        if (creatorId == null || currentUserId == null) {
            return true;
        }

        return String(creatorId) !== String(currentUserId);
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
        // Re-fetch the resource so the reviewer list and computed status reflect the new
        // decision. The form pipeline uses `id` + `locale` from the store to issue the GET.
        this.resourceFormStore.resourceStore.reload();
    };

    handleReject = async(comment: ?string) => {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return;
        }

        await ResourceRequester.post(
            'workflow_transition_requests',
            {comment: comment || null},
            {id: request.id, action: 'reject'}
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
                open={this.open}
                request={request}
            />
        );
    }

    getToolbarItemConfig() {
        const {visible_condition: visibleCondition} = this.options;

        const visibleConditionFulfilled = !visibleCondition || jexl.evalSync(visibleCondition, this.conditionData);
        if (!visibleConditionFulfilled) {
            return;
        }

        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return;
        }

        return {
            label: translate('sulu_content.workflow_transition_request.review_action'),
            icon: 'su-eye',
            onClick: this.handleOpen,
            type: 'button',
        };
    }
}
