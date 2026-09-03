// @flow
import {render, screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import WorkflowTransitionRequestReviewOverlay from '../components/WorkflowTransitionRequestReviewOverlay';
import type {WorkflowTransitionRequestData} from '../components/types';

jest.mock('../../../utils/Translator', () => ({
    translate: jest.fn((key, params) => (params ? key + ':' + JSON.stringify(params) : key)),
}));

function createRequest(overrides?: $Shape<WorkflowTransitionRequestData> = {}): WorkflowTransitionRequestData {
    return {
        approvalProgress: {approved: 2, rejected: 2, required: 3},
        createdBy: {fullName: 'Adam Ministrator', id: 1},
        id: 'request-1',
        locale: 'en',
        requestedAt: '2026-06-25T14:30:00+00:00',
        resourceId: '5',
        resourceKey: 'pages',
        reviewers: [
            {
                comment: null,
                decidedAt: '2026-06-25T14:31:00+00:00',
                id: 'validator-1',
                reviewer: null,
                status: 'approved',
                type: 'validator',
                validatorKey: 'unpublished_references',
            },
            {
                comment: 'Two links are broken',
                decidedAt: '2026-06-25T14:32:00+00:00',
                id: 'validator-2',
                reviewer: null,
                status: 'rejected',
                type: 'validator',
                validatorKey: 'broken_links',
            },
            {
                comment: null,
                decidedAt: '2026-06-25T15:00:00+00:00',
                id: 'reviewer-1',
                reviewer: {fullName: 'Alpha Bot', id: 2},
                status: 'approved',
                type: 'user',
                validatorKey: null,
            },
            {
                comment: 'Please fix the title',
                decidedAt: '2026-06-25T15:05:00+00:00',
                id: 'reviewer-2',
                reviewer: {fullName: 'Anna Berger', id: 3},
                status: 'rejected',
                type: 'user',
                validatorKey: null,
            },
        ],
        status: 'pending',
        ...overrides,
    };
}

function renderOverlay(props?: Object = {}) {
    return render(
        <WorkflowTransitionRequestReviewOverlay
            canAct={props.canAct ?? true}
            mode={props.mode}
            onApprove={props.onApprove ?? jest.fn()}
            onBypassConfirm={props.onBypassConfirm}
            onClose={props.onClose ?? jest.fn()}
            onReject={props.onReject ?? jest.fn()}
            onRetry={props.onRetry}
            open={true}
            request={props.request ?? createRequest()}
            userDecision={props.userDecision}
        />
    );
}

test('lists the validator rows first, then the users and then the anonymous waiting slots', () => {
    renderOverlay();

    // 2 validators + 2 users + max(0, required 3 - approved 2 - pending validators 0) waiting slots
    expect(document.querySelectorAll('.row')).toHaveLength(5);

    expect(Array.from(document.querySelectorAll('.rowTitle')).map((title) => title.textContent)).toEqual([
        'unpublished_references',
        'broken_links',
        'Alpha Bot',
        'Anna Berger',
    ]);

    expect(document.querySelectorAll('.approved')).toHaveLength(2);
    expect(document.querySelectorAll('.rejected')).toHaveLength(2);
    expect(document.querySelectorAll('.pending')).toHaveLength(1);
});

test('does not count a pending validator row as a waiting slot of its own', () => {
    const request = createRequest({
        approvalProgress: {approved: 0, rejected: 0, required: 2},
        reviewers: [
            {
                comment: null,
                decidedAt: null,
                id: 'validator-1',
                reviewer: null,
                status: 'pending',
                type: 'validator',
                validatorKey: 'broken_links',
            },
        ],
    });

    renderOverlay({request});

    // the pending validator row plus a single anonymous slot for the second required approval
    expect(document.querySelectorAll('.row')).toHaveLength(2);
});

test('counts the approvals and the rejections', () => {
    renderOverlay();

    expect(screen.getByText(
        'sulu_content.workflow_transition_request.n_of_m_approved:{"approved":2,"required":3}'
    )).toBeInTheDocument();
    expect(screen.getByText(
        'sulu_content.workflow_transition_request.n_rejected:{"rejected":2}'
    )).toBeInTheDocument();
});

test('hides the rejection count while nothing was rejected', () => {
    renderOverlay({request: createRequest({approvalProgress: {approved: 2, rejected: 0, required: 3}})});

    expect(screen.queryByText(/request\.n_rejected/)).not.toBeInTheDocument();
});

test('expands the comment of a validator and of a reviewer row via the chevron', async() => {
    const user = userEvent.setup();

    renderOverlay();

    expect(screen.queryByText('Two links are broken')).not.toBeInTheDocument();
    expect(screen.queryByText('Please fix the title')).not.toBeInTheDocument();

    const chevrons = screen.getAllByLabelText('su-angle-down');
    expect(chevrons).toHaveLength(2);

    await user.click(chevrons[0]);
    expect(screen.getByText('Two links are broken')).toBeInTheDocument();

    await user.click(chevrons[1]);
    expect(screen.getByText('Please fix the title')).toBeInTheDocument();
});

test('enables both decisions for a reviewer who has not decided yet', () => {
    renderOverlay();

    expect(screen.getByRole('button', {name: 'sulu_content.reject'})).toBeEnabled();
    expect(screen.getByRole('button', {name: 'sulu_content.approve'})).toBeEnabled();
});

test('disables the approval and renames it after the reviewer approved, but keeps the rejection open', () => {
    renderOverlay({userDecision: 'approved'});

    expect(screen.getByRole('button', {
        name: 'sulu_content.workflow_transition_request.you_approved',
    })).toBeDisabled();
    expect(screen.getByRole('button', {name: 'sulu_content.reject'})).toBeEnabled();
});

test('disables the rejection after the reviewer rejected, but keeps the approval open', () => {
    renderOverlay({userDecision: 'rejected'});

    expect(screen.getByRole('button', {name: 'sulu_content.reject'})).toBeDisabled();
    expect(screen.getByRole('button', {name: 'sulu_content.approve'})).toBeEnabled();
});

test('disables both decisions once the request is closed', () => {
    renderOverlay({request: createRequest({status: 'published'})});

    expect(screen.getByRole('button', {name: 'sulu_content.reject'})).toBeDisabled();
    expect(screen.getByRole('button', {name: 'sulu_content.approve'})).toBeDisabled();
});

test('approves with a comment', async() => {
    const user = userEvent.setup();
    const onApprove = jest.fn().mockResolvedValue();
    const onClose = jest.fn();

    renderOverlay({onApprove, onClose});

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));

    expect(screen.getByText(
        'sulu_content.workflow_transition_request.approve_comment_label'
    )).toBeInTheDocument();

    await user.type(
        screen.getByPlaceholderText('sulu_content.workflow_transition_request.comment_placeholder'),
        'Looks good'
    );
    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(onApprove).toHaveBeenCalledWith('Looks good');
    // The reviewer stays in the overlay and reads the refreshed request instead of reopening it.
    expect(onClose).not.toHaveBeenCalled();
    expect(document.querySelectorAll('.row')).toHaveLength(5);
});

test('approves without a comment, because the comment is optional', async() => {
    const user = userEvent.setup();
    const onApprove = jest.fn().mockResolvedValue();

    renderOverlay({onApprove});

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));
    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(onApprove).toHaveBeenCalledWith(null);
});

test('only allows sending a rejection once a comment was written', async() => {
    const user = userEvent.setup();
    const onReject = jest.fn().mockResolvedValue();
    const onClose = jest.fn();

    renderOverlay({onClose, onReject});

    await user.click(screen.getByRole('button', {name: 'sulu_content.reject'}));

    expect(screen.getByText(
        'sulu_content.workflow_transition_request.why_did_you_reject'
    )).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'sulu_admin.send'})).toBeDisabled();

    await user.type(
        screen.getByPlaceholderText('sulu_content.workflow_transition_request.comment_placeholder'),
        'Please fix this'
    );
    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(onReject).toHaveBeenCalledWith('Please fix this');
    expect(onClose).not.toHaveBeenCalled();
    expect(document.querySelectorAll('.row')).toHaveLength(5);
});

test('offers a retry on every unapproved validator row and keeps the overlay open afterwards', async() => {
    const user = userEvent.setup();
    const onClose = jest.fn();
    const onRetry = jest.fn().mockResolvedValue();

    renderOverlay({onClose, onRetry});

    const retryButtons = screen.getAllByRole('button', {
        name: 'sulu_content.workflow_transition_request.retry',
    });
    expect(retryButtons).toHaveLength(1);

    await user.click(retryButtons[0]);

    expect(onRetry).toHaveBeenCalledWith('broken_links');
    expect(onClose).not.toHaveBeenCalled();
});

test('offers no retry once the request is closed, because the verdict cannot be redone', () => {
    renderOverlay({onRetry: jest.fn(), request: createRequest({status: 'published'})});

    expect(screen.queryByRole('button', {
        name: 'sulu_content.workflow_transition_request.retry',
    })).not.toBeInTheDocument();
});

test('offers no retry to a reviewer who may not act on the request', () => {
    renderOverlay({canAct: false, onRetry: jest.fn()});

    expect(screen.queryByRole('button', {
        name: 'sulu_content.workflow_transition_request.retry',
    })).not.toBeInTheDocument();
});

test('renders no decision buttons and a notice when the viewer cannot act on their own request', () => {
    renderOverlay({canAct: false});

    expect(screen.queryByRole('button', {name: 'sulu_content.reject'})).not.toBeInTheDocument();
    expect(screen.queryByRole('button', {name: 'sulu_content.approve'})).not.toBeInTheDocument();
    expect(screen.getByText('sulu_content.workflow_transition_request.self_review_not_allowed')).toBeInTheDocument();
});

test('bypass mode renders a single bypass button and calls onBypassConfirm', async() => {
    const user = userEvent.setup();
    const onBypassConfirm = jest.fn();

    renderOverlay({mode: 'bypass', onBypassConfirm});

    expect(screen.queryByRole('button', {name: 'sulu_content.reject'})).not.toBeInTheDocument();

    await user.click(
        screen.getByRole('button', {name: 'sulu_content.workflow_transition_request.bypass_publish'})
    );

    expect(onBypassConfirm).toHaveBeenCalled();
});

test('shows an error snackbar when approving fails and clears it on a successful retry', async() => {
    const user = userEvent.setup();
    let approveCallCount = 0;
    const onApprove = jest.fn(() => {
        approveCallCount += 1;
        return approveCallCount === 1 ? Promise.reject(new Error('network error')) : Promise.resolve();
    });
    const onClose = jest.fn();

    renderOverlay({onApprove, onClose});

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));
    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(document.querySelector('.snackbar')?.textContent).toContain(
        'sulu_content.workflow_transition_request.action_failed'
    );
    expect(onClose).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(onApprove).toHaveBeenCalledTimes(2);
    // the Snackbar keeps rendering its last message during its own fade-out transition, so the
    // visible flag - not the text - is what proves the error was cleared on the successful retry
    expect(document.querySelector('.snackbar.visible')).toBeNull();
});

test('prefers the message the server sent over the generic failure message', async() => {
    const user = userEvent.setup();
    const onApprove = jest.fn(() => Promise.reject({json: () => Promise.resolve({detail: 'You already decided'})}));

    renderOverlay({onApprove});

    await user.click(screen.getByRole('button', {name: 'sulu_content.approve'}));
    await user.click(screen.getByRole('button', {name: 'sulu_admin.send'}));

    expect(document.querySelector('.snackbar')?.textContent).toContain('You already decided');
});

test('renders a no-reviewers message when nothing is expected and nobody decided', () => {
    const request = createRequest({
        approvalProgress: {approved: 0, rejected: 0, required: 0},
        reviewers: [],
    });

    renderOverlay({request});

    expect(screen.getByText('sulu_content.workflow_transition_request.no_reviewers')).toBeInTheDocument();
});
