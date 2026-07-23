// @flow

export type WorkflowTransitionRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'published';

export type Reviewer = {|
    comment: ?string,
    decidedAt: string,
    id: string,
    reviewer: ?{fullName: string, id: number | string},
    status: 'approved' | 'rejected',
|};

export type ValidationFailure = {|
    details?: {[string]: mixed},
    messageKey: string,
    messageParameters?: {[string]: string | number},
    validatorKey: string,
|};

export type ValidatorOutcome = {|
    failures: Array<ValidationFailure>,
    passed: boolean,
    pending: boolean,
    validatorKey: string,
|};

export type ApprovalProgress = {|
    approved: number,
    rejected: number,
    remainingApprovals: number,
    required: number,
|};

export type PublishValidation = {|
    failures: Array<ValidationFailure>,
    outcomes: Array<ValidatorOutcome>,
    passed: boolean,
    pending: boolean,
|};

export type WorkflowTransitionRequestData = {|
    approvalProgress: ApprovalProgress,
    createdBy: ?{fullName: string, id: number | string},
    id: string,
    publishValidation: ?PublishValidation,
    requestedAt: string,
    reviewers: Array<Reviewer>,
    status: WorkflowTransitionRequestStatus,
    workflowName?: string,
|};
