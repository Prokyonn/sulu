// @flow
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

// The request is applied after the save, so a published page is a draft again by the time it runs.
const PLACE_TO_TRANSITION = {
    draft: 'request_for_review_draft',
    published: 'request_for_review_draft',
    unpublished: 'request_for_review',
};

export default class RequestForPublishToolbarAction extends AbstractFormToolbarAction {
    getToolbarItemConfig() {
        const {
            disabled_condition: disabledCondition,
            visible_condition: visibleCondition,
        } = this.options;

        const visibleConditionFulfilled = !visibleCondition || jexl.evalSync(visibleCondition, this.conditionData);

        if (!visibleConditionFulfilled) {
            return;
        }

        const {data, dirty} = this.resourceFormStore;
        const place = data.workflowPlace;
        const transition = PLACE_TO_TRANSITION[place];

        if (!transition) {
            return;
        }

        const extraDisabled = disabledCondition ? jexl.evalSync(disabledCondition, this.conditionData) : false;

        return {
            label: translate('sulu_content.workflow_transition_request.request_for_publish'),
            // A published page without changes has nothing to send to review.
            disabled: (place === 'published' && !dirty) || extraDisabled,
            onClick: () => {
                this.form.submit({action: transition});
            },
            type: 'button',
        };
    }
}
