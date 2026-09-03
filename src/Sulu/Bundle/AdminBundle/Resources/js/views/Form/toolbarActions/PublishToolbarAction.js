// @flow
import jexl from 'jexl';
import {translate} from '../../../utils/Translator';
import AbstractFormToolbarAction from './AbstractFormToolbarAction';

export default class PublishToolbarAction extends AbstractFormToolbarAction {
    /** Publishing an approved request resolves the lock, so this action survives it. */
    get enabledWhileLocked(): boolean {
        return true;
    }

    getToolbarItemConfig() {
        const {
            disabled_condition: disabledCondition,
            visible_condition: visibleCondition,
        } = this.options;

        const {dirty, data} = this.resourceFormStore;

        const visibleConditionFulfilled = !visibleCondition || jexl.evalSync(visibleCondition, this.conditionData);

        if (!visibleConditionFulfilled) {
            return;
        }

        const extraDisabled = disabledCondition ? jexl.evalSync(disabledCondition, this.conditionData) : false;

        return {
            label: translate('sulu_admin.publish'),
            disabled: dirty || data.publishedState === undefined || !!data.publishedState || extraDisabled,
            onClick: () => {
                // The locked form disables every field, so a draft that violates the schema could never
                // be published. The server discards the payload of a review-resolving action anyway.
                this.form.save({action: 'publish', skipValidation: true});
            },
            type: 'button',
        };
    }
}
