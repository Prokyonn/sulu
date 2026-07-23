// @flow
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

const PLACE_TO_TRANSITION = {
    unpublished: 'request_for_review',
    draft: 'request_for_review_draft',
};

/**
 * Submits the resource with the workflow transition that creates a `WorkflowTransitionRequest`.
 * The transition name depends on the current workflow place: `unpublished` triggers
 * `request_for_review`, an existing `draft` triggers `request_for_review_draft`. Other places
 * (review, review_draft, published) hide the action.
 */
export default class RequestForPublishToolbarAction extends AbstractFormToolbarAction {
    getToolbarItemConfig() {
        const {visible_condition: visibleCondition} = this.options;
        const {data, dirty} = this.resourceFormStore;

        const visibleConditionFulfilled = !visibleCondition || jexl.evalSync(visibleCondition, this.conditionData);

        if (!visibleConditionFulfilled) {
            return;
        }

        const transition = PLACE_TO_TRANSITION[data.workflowPlace];
        if (!transition) {
            return;
        }

        return {
            label: translate('sulu_content.workflow_transition_request.request_for_publish'),
            disabled: dirty,
            onClick: () => {
                this.form.submit({action: transition});
            },
            type: 'button',
        };
    }
}
