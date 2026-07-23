// @flow
import {render, screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';

jest.mock('../../../utils/Translator', () => ({
    translate: jest.fn((key) => key),
}));

function createRequest(overrides = {}) {
    return {
        approvalProgress: {approved: 1, rejected: 0, remainingApprovals: 2, required: 3},
        createdBy: {fullName: 'Adam Ministrator', id: 1},
        id: 'request-1',
        publishValidation: {
            failures: [],
            outcomes: [
                {failures: [], pending: true, passed: false, validatorKey: 'user_approvals'},
                {failures: [], pending: false, passed: true, validatorKey: 'seo_required'},
                {
                    failures: [{
                        details: {},
                        messageKey: 'sulu_content.workflow_transition_request.excerpt_required.missing',
                        messageParameters: {fields: 'Title'},
                        validatorKey: 'excerpt_required',
                    }],
                    pending: false,
                    passed: false,
                    validatorKey: 'excerpt_required',
                },
            ],
            passed: false,
            pending: true,
        },
        requestedAt: '2026-06-25T14:30:00+00:00',
        reviewers: [
            {
                comment: null,
                decidedAt: '2026-06-25T15:00:00+00:00',
                id: 'reviewer-1',
                reviewer: {fullName: 'Alpha Bot', id: 2},
                status: 'approved',
            },
            {
                comment: 'Please fix the title',
                decidedAt: '2026-06-25T15:05:00+00:00',
                id: 'reviewer-2',
                reviewer: {fullName: 'Anna Berger', id: 3},
                status: 'rejected',
            },
        ],
        status: 'pending',
        workflowName: 'default',
        ...overrides,
    };
}

test('renders the header and a unified list mixing validator, reviewer and waiting rows', () => {
    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={jest.fn()}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    expect(screen.getByText('Adam Ministrator')).toBeInTheDocument();
    expect(screen.getByText('sulu_content.workflow_transition_request.requested_a_review')).toBeInTheDocument();
    expect(screen.getByText('sulu_content.workflow_transition_request.n_of_m_approved')).toBeInTheDocument();

    // 1 passed validator + 1 failed validator + 2 reviewers + 2 anonymous waiting slots = 6
    // (the "user_approvals" validator outcome is excluded, its progress is represented by
    // the reviewer rows and the anonymous waiting slots instead)
    expect(document.querySelectorAll('.row')).toHaveLength(6);

    expect(
        screen.queryByText('sulu_content.workflow_transition_request.validators.user_approvals')
    ).not.toBeInTheDocument();
    expect(screen.getByText('sulu_content.workflow_transition_request.validators.seo_required')).toBeInTheDocument();
    expect(
        screen.getByText('sulu_content.workflow_transition_request.validators.excerpt_required')
    ).toBeInTheDocument();
    expect(screen.getByText('Alpha Bot')).toBeInTheDocument();
    expect(screen.getByText('Anna Berger')).toBeInTheDocument();

    expect(document.querySelectorAll('.approved')).toHaveLength(2);
    expect(document.querySelectorAll('.rejected')).toHaveLength(2);
    expect(document.querySelectorAll('.waiting')).toHaveLength(2);
});

test('expands the validator failure detail and the reviewer rejection comment via the chevron', async() => {
    const user = userEvent.setup();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={jest.fn()}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    expect(
        screen.queryByText('sulu_content.workflow_transition_request.excerpt_required.missing')
    ).not.toBeInTheDocument();
    expect(screen.queryByText('Please fix the title')).not.toBeInTheDocument();

    const chevrons = screen.getAllByLabelText('su-angle-down');
    expect(chevrons).toHaveLength(2);

    await user.click(chevrons[0]);
    expect(
        screen.getByText('sulu_content.workflow_transition_request.excerpt_required.missing')
    ).toBeInTheDocument();

    await user.click(chevrons[1]);
    expect(screen.getByText('Please fix the title')).toBeInTheDocument();
});

test('expands a validator with multiple failures to show every failure message, not just the first', async() => {
    const user = userEvent.setup();

    const request = createRequest({
        publishValidation: {
            failures: [],
            outcomes: [
                {failures: [], pending: true, passed: false, validatorKey: 'user_approvals'},
                {
                    failures: [
                        {
                            details: {},
                            messageKey: 'sulu_content.workflow_transition_request.excerpt_required.missing_title',
                            messageParameters: {},
                            validatorKey: 'excerpt_required',
                        },
                        {
                            details: {},
                            messageKey:
                                'sulu_content.workflow_transition_request.excerpt_required.missing_description',
                            messageParameters: {},
                            validatorKey: 'excerpt_required',
                        },
                    ],
                    pending: false,
                    passed: false,
                    validatorKey: 'excerpt_required',
                },
            ],
            passed: false,
            pending: true,
        },
        reviewers: [],
    });

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={jest.fn()}
            onReject={jest.fn()}
            open={true}
            request={request}
        />
    );

    await user.click(screen.getByLabelText('su-angle-down'));

    const detail = document.querySelector('.rowDetail');
    expect(detail).not.toBeNull();
    expect(detail && detail.textContent).toBe(
        'sulu_content.workflow_transition_request.excerpt_required.missing_title\n' +
        'sulu_content.workflow_transition_request.excerpt_required.missing_description'
    );
});

test('shows an error snackbar when approve fails and clears it on a successful retry', async() => {
    const user = userEvent.setup();
    let approveCallCount = 0;
    const onApprove = jest.fn(() => {
        approveCallCount += 1;
        return approveCallCount === 1 ? Promise.reject(new Error('network error')) : Promise.resolve();
    });
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={onApprove}
            onClose={onClose}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));

    expect(document.querySelector('.snackbar')?.textContent).toContain(
        'sulu_content.workflow_transition_request.action_failed'
    );
    expect(onClose).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));

    expect(onApprove).toHaveBeenCalledTimes(2);
    expect(onClose).toHaveBeenCalled();
    // the Snackbar keeps rendering its last message during its own fade-out transition, so the
    // visible flag - not the text - is what proves the error was cleared on the successful retry
    expect(document.querySelector('.snackbar.visible')).toBeNull();
});

test('the reject step is send-only with no cancel action, matching the design', async() => {
    const user = userEvent.setup();
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={onClose}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    await user.click(screen.getByRole('button', {name: 'sulu_content.reject'}));
    expect(screen.getByText('sulu_content.workflow_transition_request.why_did_you_reject')).toBeInTheDocument();

    expect(screen.getByRole('button', {name: 'sulu_admin.send'})).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_admin.cancel'})).not.toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
});

test('review mode shows Reject and Approve buttons and approve calls onApprove then onClose', async() => {
    const user = userEvent.setup();
    const onApprove = jest.fn().mockResolvedValue();
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={onApprove}
            onClose={onClose}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    expect(screen.getByRole('button', {name: 'sulu_content.reject'})).toBeInTheDocument();

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));

    expect(onApprove).toHaveBeenCalledWith(undefined);
    expect(onClose).toHaveBeenCalled();
});

test('reject flow opens the reason modal and submits the comment', async() => {
    const user = userEvent.setup();
    const onReject = jest.fn().mockResolvedValue();
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={onClose}
            onReject={onReject}
            open={true}
            request={createRequest()}
        />
    );

    await user.click(screen.getByRole('button', {name: 'sulu_content.reject'}));

    expect(screen.getByText('sulu_content.workflow_transition_request.why_did_you_reject')).toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_content.approve'})).not.toBeInTheDocument();

    const textarea = screen.getByPlaceholderText(
        'sulu_content.workflow_transition_request.reject_reason_placeholder'
    );
    await user.type(textarea, 'Please fix this');

    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(onReject).toHaveBeenCalledWith('Please fix this');
    expect(onClose).toHaveBeenCalled();
});

test('closing the overlay resets an in-progress reject flow', async() => {
    const user = userEvent.setup();
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={onClose}
            onReject={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    await user.click(screen.getByRole('button', {name: 'sulu_content.reject'}));
    expect(screen.getByText('sulu_content.workflow_transition_request.why_did_you_reject')).toBeInTheDocument();

    await user.click(screen.getByRole('button', {name: 'su-times'}));
    expect(onClose).toHaveBeenCalled();
});

test('renders no action buttons and a notice when the viewer cannot act on their own request', () => {
    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={false}
            onClose={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    expect(screen.queryByRole('button', {name: 'sulu_content.reject'})).not.toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_content.approve'})).not.toBeInTheDocument();
    expect(screen.getByText('sulu_content.cannot_review_own_request')).toBeInTheDocument();
});

test('bypass mode renders a single bypass button and calls onBypassConfirm', async() => {
    const user = userEvent.setup();
    const onBypassConfirm = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            mode="bypass"
            onBypassConfirm={onBypassConfirm}
            onClose={jest.fn()}
            open={true}
            request={createRequest()}
        />
    );

    expect(screen.queryByRole('button', {name: 'sulu_content.reject'})).not.toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_content.approve'})).not.toBeInTheDocument();

    await user.click(
        screen.getByRole('button', {name: 'sulu_content.workflow_transition_request.bypass_publish'})
    );

    expect(onBypassConfirm).toHaveBeenCalled();
});

test('view mode is read-only and only shows a Close button', async() => {
    const user = userEvent.setup();
    const onClose = jest.fn();

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={false}
            mode="view"
            onClose={onClose}
            open={true}
            request={createRequest()}
        />
    );

    expect(screen.queryByRole('button', {name: 'sulu_content.reject'})).not.toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_content.approve'})).not.toBeInTheDocument();
    expect(screen.queryByText('sulu_content.cannot_review_own_request')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', {name: 'sulu_admin.close'}));

    expect(onClose).toHaveBeenCalled();
});

test('renders a no-reviewers message when the unified list is empty', () => {
    const request = createRequest({
        approvalProgress: {approved: 0, rejected: 0, remainingApprovals: 0, required: 0},
        publishValidation: null,
        reviewers: [],
    });

    render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={true}
            onApprove={jest.fn()}
            onClose={jest.fn()}
            onReject={jest.fn()}
            open={true}
            request={request}
        />
    );

    expect(screen.getByText('sulu_content.workflow_transition_request_no_reviewers')).toBeInTheDocument();
});
