// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import ArrowMenu from '../../../components/ArrowMenu';
import {translate} from '../../../utils/Translator';
import {reviewerName} from './reviewers';
import workflowTransitionRequestTimelineStyles from './workflowTransitionRequestTimeline.scss';
import type {Node} from 'react';
import type {Reviewer, WorkflowTransitionRequestData} from './types';

type Props = {|
    children: Node,
    request: WorkflowTransitionRequestData,
|};

type TimelineEntry = {|
    id: string,
    label: string,
    name: string,
    timestamp: string,
|};

const DATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function decisionTime(reviewer: Reviewer): number {
    return new Date(reviewer.decidedAt ?? 0).getTime();
}

// Numbers the approvals 1..N in decision order, so each entry shows the count as of that moment.
function buildApprovalRunningCounts(reviewers: Array<Reviewer>): Map<string, number> {
    const runningCounts = new Map();

    reviewers
        .filter((reviewer) => reviewer.status === 'approved')
        .slice()
        .sort((left, right) => decisionTime(left) - decisionTime(right))
        .forEach((reviewer, index) => {
            runningCounts.set(reviewer.id, index + 1);
        });

    return runningCounts;
}

function buildEntries(request: WorkflowTransitionRequestData): Array<TimelineEntry> {
    const {required: requiredCount} = request.approvalProgress;
    const settledReviewers = request.reviewers.filter((reviewer) => reviewer.status !== 'pending');
    const approvalRunningCounts = buildApprovalRunningCounts(settledReviewers);

    const entries: Array<TimelineEntry> = settledReviewers
        .slice()
        .sort((left, right) => decisionTime(right) - decisionTime(left))
        .map((reviewer) => ({
            id: reviewer.id,
            label: reviewer.status === 'approved'
                ? translate(
                    'sulu_content.workflow_transition_request.timeline_approved',
                    {current: approvalRunningCounts.get(reviewer.id) || 0, total: requiredCount}
                )
                : translate('sulu_content.workflow_transition_request.timeline_rejected'),
            name: reviewerName(reviewer),
            timestamp: DATE_FORMATTER.format(decisionTime(reviewer)),
        }));

    entries.push({
        id: 'requested',
        label: translate('sulu_content.workflow_transition_request.timeline_requested'),
        name: request.createdBy ? request.createdBy.fullName : translate('sulu_admin.unknown_user'),
        timestamp: DATE_FORMATTER.format(new Date(request.requestedAt)),
    });

    return entries;
}

@observer
class WorkflowTransitionRequestTimeline extends React.Component<Props> {
    @observable open: boolean = false;

    @action handleEnter = () => {
        this.open = true;
    };

    @action handleLeave = () => {
        this.open = false;
    };

    render() {
        const {children, request} = this.props;
        const entries = buildEntries(request);

        const anchor = (
            // eslint-disable-next-line jsx-a11y/no-static-element-interactions
            <span
                className={workflowTransitionRequestTimelineStyles.anchor}
                onBlur={this.handleLeave}
                onFocus={this.handleEnter}
                onMouseEnter={this.handleEnter}
                onMouseLeave={this.handleLeave}
            >
                {children}
            </span>
        );

        return (
            <ArrowMenu
                anchorElement={anchor}
                backdrop={false}
                horizontalAnchorMode="center"
                open={this.open}
                skin="dark"
            >
                <div className={workflowTransitionRequestTimelineStyles.content}>
                    {entries.map((entry) => (
                        <div key={entry.id}>
                            <div className={workflowTransitionRequestTimelineStyles.label}>
                                {entry.label}
                            </div>
                            <div className={workflowTransitionRequestTimelineStyles.meta}>
                                {entry.timestamp}
                                {' ・ '}
                                <span className={workflowTransitionRequestTimelineStyles.name}>{entry.name}</span>
                            </div>
                        </div>
                    ))}
                </div>
            </ArrowMenu>
        );
    }
}

export default WorkflowTransitionRequestTimeline;
