// @flow
import {render, screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import WorkflowTransitionRequestTimeline from '../components/WorkflowTransitionRequestTimeline';

jest.mock('../../../utils/Translator', () => ({
    translate: jest.fn((key, params) => (params ? key + ':' + JSON.stringify(params) : key)),
}));

function createRequest(overrides = {}) {
    return {
        approvalProgress: {approved: 2, rejected: 1, remainingApprovals: 0, required: 2},
        createdBy: {fullName: 'Adam Ministrator', id: 1},
        id: 'request-1',
        publishValidation: null,
        requestedAt: '2026-01-01T08:00:00+00:00',
        reviewers: [
            {
                comment: null,
                decidedAt: '2026-01-01T10:00:00+00:00',
                id: 'reviewer-first',
                reviewer: {fullName: 'First Approver', id: 2},
                status: 'approved',
            },
            {
                comment: null,
                decidedAt: '2026-01-01T11:00:00+00:00',
                id: 'reviewer-second',
                reviewer: {fullName: 'Second Approver', id: 3},
                status: 'approved',
            },
            {
                comment: 'Not good',
                decidedAt: '2026-01-01T12:00:00+00:00',
                id: 'reviewer-third',
                reviewer: {fullName: 'Rejector', id: 4},
                status: 'rejected',
            },
        ],
        status: 'pending',
        workflowName: 'default',
        ...overrides,
    };
}

test('shows the running approval count at each event instead of the final aggregate', async() => {
    const user = userEvent.setup();

    render(
        <WorkflowTransitionRequestTimeline request={createRequest()}>
            Status
        </WorkflowTransitionRequestTimeline>
    );

    await user.hover(screen.getByText('Status'));

    const labels = Array.from(document.querySelectorAll('.label')).map((label) => label.textContent);

    // newest first: rejection, second approval (running 2/2), first approval (running 1/2), request creation
    expect(labels).toEqual([
        'sulu_content.workflow_transition_request.timeline_rejected',
        'sulu_content.workflow_transition_request.timeline_approved:{"current":2,"total":2}',
        'sulu_content.workflow_transition_request.timeline_approved:{"current":1,"total":2}',
        'sulu_content.workflow_transition_request.timeline_requested',
    ]);
});
