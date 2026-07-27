// @flow
import React from 'react';
import {action, observable} from 'mobx';
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

/**
 * Opens the review overlay in bypass mode (status summary + publishValidation warnings, no review
 * form). The overlay's confirm button submits a publish transition with `bypassReview=true`,
 * bypassing the workflow transition request guard. The per-content-type controllers read
 * `?bypassReview=true` and call the BypassReviewAuthorizer; backend rejects with 403 if the current
 * user lacks the LIVE permission.
 */
export default class BypassReviewAndPublishToolbarAction extends AbstractFormToolbarAction {
    @observable open: boolean = false;

    @action handleOpen = () => {
        this.open = true;
    };

    @action handleClose = () => {
        this.open = false;
    };

    handleBypassConfirm = () => {
        this.handleClose();
        this.form.submit({action: 'publish', bypassReview: true});
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

        const disabled = disabledCondition ? jexl.evalSync(disabledCondition, this.conditionData) : false;

        return {
            label: translate('sulu_content.workflow_transition_request.bypass_publish'),
            icon: 'su-flag',
            disabled,
            onClick: this.handleOpen,
            type: 'button',
        };
    }
}
