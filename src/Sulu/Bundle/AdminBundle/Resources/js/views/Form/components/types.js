// @flow

export type WorkflowTransitionRequestStatus = 'pending' | 'approved' | 'cancelled' | 'published';

export type ReviewerStatus = 'pending' | 'approved' | 'rejected';

export type User = {|
    fullName: string,
    id: number | string,
|};

export type Reviewer = {|
    comment: ?string,
    decidedAt: ?string,
    id: string,
    reviewer: ?User,
    status: ReviewerStatus,
    type: 'user' | 'validator',
    validatorKey: ?string,
|};

export type ApprovalProgress = {|
    approved: number,
    rejected: number,
    required: number,
|};

export type WorkflowTransitionRequestData = {|
    approvalProgress: ApprovalProgress,
    createdBy: ?User,
    id: string,
    locale: string,
    requestedAt: string,
    resourceId: string,
    resourceKey: string,
    reviewers: Array<Reviewer>,
    status: WorkflowTransitionRequestStatus,
|};
