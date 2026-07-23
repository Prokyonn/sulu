// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import ArrowMenu from '../../../components/ArrowMenu';
import {translate} from '../../../utils/Translator';
import workflowTransitionRequestTimelineStyles from './workflowTransitionRequestTimeline.scss';
import type {Node} from 'react';
import type {Reviewer, WorkflowTransitionRequestData} from './types';

type Props = {|
    children: Node,
    request: WorkflowTransitionRequestData,
|};

type TimelineEntry = {|
    key: string,
    label: string,
    name: ?string,
    timestamp: ?string,
|};

const DATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatTimestamp(isoString: ?string): ?string {
    if (!isoString) {
        return null;
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return DATE_FORMATTER.format(date);
}

// Numbers approved reviewers 1..N in the order they decided, so the timeline can show the
// running approval count as of each event instead of the final aggregate total.
function buildApprovalRunningCounts(reviewers: Array<Reviewer>): Map<string, number> {
    const runningCounts = new Map();

    reviewers
        .filter((reviewer) => reviewer.status === 'approved')
        .slice()
        .sort((left, right) => {
            const leftTime = left.decidedAt ? new Date(left.decidedAt).getTime() : 0;
            const rightTime = right.decidedAt ? new Date(right.decidedAt).getTime() : 0;

            return leftTime - rightTime;
        })
        .forEach((reviewer, index) => {
            runningCounts.set(reviewer.id, index + 1);
        });

    return runningCounts;
}

function buildEntries(request: WorkflowTransitionRequestData): Array<TimelineEntry> {
    const {required: requiredCount} = request.approvalProgress;
    const approvalRunningCounts = buildApprovalRunningCounts(request.reviewers);

    const reviewerEntries: Array<TimelineEntry> = request.reviewers
        .slice()
        .sort((left, right) => {
            const leftTime = left.decidedAt ? new Date(left.decidedAt).getTime() : 0;
            const rightTime = right.decidedAt ? new Date(right.decidedAt).getTime() : 0;

            return rightTime - leftTime;
        })
        .map((reviewer) => {
            const approvedLabel = requiredCount > 0
                ? translate(
                    'sulu_content.workflow_transition_request.timeline_approved',
                    {current: approvalRunningCounts.get(reviewer.id) || 0, total: requiredCount}
                )
                : translate('sulu_content.approve');
            const rejectedLabel = translate('sulu_content.workflow_transition_request.timeline_rejected');

            return {
                key: reviewer.id,
                label: reviewer.status === 'approved' ? approvedLabel : rejectedLabel,
                name: reviewer.reviewer ? reviewer.reviewer.fullName : translate('sulu_admin.unknown_user'),
                timestamp: formatTimestamp(reviewer.decidedAt),
            };
        });

    reviewerEntries.push({
        key: 'requested',
        label: translate('sulu_content.workflow_transition_request.timeline_requested'),
        name: request.createdBy ? request.createdBy.fullName : translate('sulu_admin.unknown_user'),
        timestamp: formatTimestamp(request.requestedAt),
    });

    return reviewerEntries;
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
                open={this.open}
                skin="dark"
            >
                <div className={workflowTransitionRequestTimelineStyles.content}>
                    {entries.map((entry) => (
                        <div className={workflowTransitionRequestTimelineStyles.entry} key={entry.key}>
                            <div className={workflowTransitionRequestTimelineStyles.label}>
                                {entry.label}
                            </div>
                            <div className={workflowTransitionRequestTimelineStyles.meta}>
                                {entry.timestamp}
                                {entry.timestamp && entry.name ? ' • ' : ''}
                                {entry.name}
                            </div>
                        </div>
                    ))}
                </div>
            </ArrowMenu>
        );
    }
}

export default WorkflowTransitionRequestTimeline;
