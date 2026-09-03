// @flow
import React from 'react';
import {action, observable} from 'mobx';
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

/**
 * The `bypass_publish` transition is not guarded by the workflow transition request, so the server
 * authorizes it separately via the BypassReviewAuthorizer and rejects a missing LIVE permission.
 */
export default class BypassReviewAndPublishToolbarAction extends AbstractFormToolbarAction {
    @observable open: boolean = false;

    /** Bypassing the review resolves the lock, so this action survives it. */
    get enabledWhileLocked(): boolean {
        return true;
    }

    @action handleOpen = () => {
        this.open = true;
    };

    @action handleClose = () => {
        this.open = false;
    };

    handleBypassConfirm = () => {
        this.handleClose();
        // The locked form disables every field, so a draft that violates the schema could never be
        // published. The server discards the payload of a review-resolving action anyway.
        this.form.save({action: 'bypass_publish', skipValidation: true});
    };

    getNode(index: ?number) {
        const request = this.resourceFormStore.data.activeWorkflowTransitionRequest;
        if (!request) {
            return null;
        }

        return (
            <WorkflowTransitionRequestReviewOverlay
                canAct={true}
                key={`workflow-transition-request-bypass-${index ?? 0}`}
                mode="bypass"
                onBypassConfirm={this.handleBypassConfirm}
                onClose={this.handleClose}
                open={this.open}
                request={request}
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
            label: translate('sulu_content.workflow_transition_request.bypass_publish'),
            disabled,
            onClick: this.handleOpen,
            type: 'button',
        };
    }
}
