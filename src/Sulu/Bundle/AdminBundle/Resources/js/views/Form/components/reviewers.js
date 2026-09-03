// @flow
import {translate} from '../../../utils/Translator';
import type {Reviewer} from './types';

function translateValidatorKey(validatorKey: string): string {
    const translationKey = 'sulu_content.workflow_transition_request.validators.' + validatorKey;
    const label = translate(translationKey);

    return label === translationKey ? validatorKey : label;
}

export function reviewerName(reviewer: Reviewer): string {
    if (reviewer.reviewer) {
        return reviewer.reviewer.fullName;
    }

    if (reviewer.validatorKey) {
        return translateValidatorKey(reviewer.validatorKey);
    }

    return translate('sulu_admin.unknown_user');
}
