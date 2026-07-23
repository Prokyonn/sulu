// @flow
import React, {Fragment} from 'react';
import {observer} from 'mobx-react';
import {action, observable, runInAction} from 'mobx';
import classNames from 'classnames';
import Overlay from '../../../components/Overlay';
import Icon from '../../../components/Icon';
import Heading from '../../../components/Heading';
import TextArea from '../../../components/TextArea';
import {translate} from '../../../utils/Translator';
import workflowTransitionRequestReviewOverlayStyles from './workflowTransitionRequestReviewOverlay.scss';
import type {ValidatorOutcome, WorkflowTransitionRequestData} from './types';

export type WorkflowTransitionRequestReviewOverlayMode = 'review' | 'bypass' | 'view';

type RowStatus = 'approved' | 'rejected' | 'waiting';

type RowData = {|
    caption: string,
    detail: ?string,
    key: string,
    status: RowStatus,
    title: ?string,
|};

const STATUS_ICON: {[RowStatus]: string} = {
    approved: 'su-check',
    rejected: 'su-times',
    waiting: 'su-circle-full',
};

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

function buildValidatorRow(outcome: ValidatorOutcome): RowData {
    const title = translate('sulu_content.workflow_transition_request.validators.' + outcome.validatorKey);

    if (outcome.passed) {
        return {
            caption: translate('sulu_content.workflow_transition_request.reviewer_caption_approved'),
            detail: null,
            key: 'validator-' + outcome.validatorKey,
            status: 'approved',
            title,
        };
    }

    if (outcome.pending) {
        return {
            caption: translate('sulu_content.workflow_transition_request.reviewer_caption_waiting'),
            detail: null,
            key: 'validator-' + outcome.validatorKey,
            status: 'waiting',
            title,
        };
    }

    const detail = outcome.failures.length > 0
        ? outcome.failures.map((failure) => translate(failure.messageKey, failure.messageParameters)).join('\n')
        : null;

    return {
        caption: translate('sulu_content.workflow_transition_request.reviewer_caption_rejected'),
        detail,
        key: 'validator-' + outcome.validatorKey,
        status: 'rejected',
        title,
    };
}

function buildRows(request: WorkflowTransitionRequestData): Array<RowData> {
    const rows = (request.publishValidation?.outcomes || [])
        .filter((outcome) => outcome.validatorKey !== 'user_approvals')
        .map(buildValidatorRow);

    request.reviewers.forEach((reviewer) => {
        const title = reviewer.reviewer ? reviewer.reviewer.fullName : translate('sulu_admin.unknown_user');

        rows.push(reviewer.status === 'approved'
            ? {
                caption: translate('sulu_content.workflow_transition_request.reviewer_caption_approved'),
                detail: null,
                key: 'reviewer-' + reviewer.id,
                status: 'approved',
                title,
            }
            : {
                caption: translate('sulu_content.workflow_transition_request.reviewer_caption_rejected'),
                detail: reviewer.comment || null,
                key: 'reviewer-' + reviewer.id,
                status: 'rejected',
                title,
            });
    });

    for (let i = 0; i < request.approvalProgress.remainingApprovals; i++) {
        rows.push({
            caption: translate('sulu_content.workflow_transition_request.reviewer_caption_waiting'),
            detail: null,
            key: 'waiting-' + i,
            status: 'waiting',
            title: null,
        });
    }

    return rows;
}

type RowProps = {|
    caption: string,
    detail: ?string,
    status: RowStatus,
    title: ?string,
|};

@observer
class WorkflowTransitionRequestReviewRow extends React.Component<RowProps> {
    @observable expanded: boolean = false;

    @action handleToggle = () => {
        this.expanded = !this.expanded;
    };

    render() {
        const {caption, detail, status, title} = this.props;

        return (
            <li className={workflowTransitionRequestReviewOverlayStyles.row}>
                <div className={workflowTransitionRequestReviewOverlayStyles.rowMain}>
                    <span
                        className={classNames(
                            workflowTransitionRequestReviewOverlayStyles.rowIcon,
                            workflowTransitionRequestReviewOverlayStyles[status]
                        )}
                    >
                        <Icon
                            className={workflowTransitionRequestReviewOverlayStyles.rowGlyph}
                            name={STATUS_ICON[status]}
                        />
                    </span>
                    <span className={workflowTransitionRequestReviewOverlayStyles.rowContent}>
                        {title && (
                            <span className={workflowTransitionRequestReviewOverlayStyles.rowTitle}>{title}</span>
                        )}
                        <span className={workflowTransitionRequestReviewOverlayStyles.rowCaption}>{caption}</span>
                    </span>
                    {detail && (
                        <Icon
                            className={classNames(
                                workflowTransitionRequestReviewOverlayStyles.rowChevron,
                                {[workflowTransitionRequestReviewOverlayStyles.rowChevronOpen]: this.expanded}
                            )}
                            name="su-angle-down"
                            onClick={this.handleToggle}
                        />
                    )}
                </div>
                {detail && this.expanded && (
                    <div className={workflowTransitionRequestReviewOverlayStyles.rowDetail}>{detail}</div>
                )}
            </li>
        );
    }
}

type Props = {|
    canAct: boolean,
    mode?: WorkflowTransitionRequestReviewOverlayMode,
    onApprove?: (comment: ?string) => Promise<mixed>,
    onBypassConfirm?: () => void,
    onClose: () => void,
    onReject?: (comment: ?string) => Promise<mixed>,
    open: boolean,
    request: WorkflowTransitionRequestData,
|};

@observer
class WorkflowTransitionRequestReviewOverlay extends React.Component<Props> {
    @observable rejecting: boolean = false;
    @observable comment: ?string = undefined;
    @observable submitting: boolean = false;
    @observable error: string | typeof undefined = undefined;

    @action handleOpenReject = () => {
        this.rejecting = true;
    };

    @action handleCommentChange = (value: ?string) => {
        this.comment = value;
    };

    @action handleSnackbarCloseClick = () => {
        this.error = undefined;
    };

    @action reset = () => {
        this.rejecting = false;
        this.comment = undefined;
        this.submitting = false;
        this.error = undefined;
    };

    handleClose = () => {
        if (this.submitting) {
            return;
        }
        this.reset();
        this.props.onClose();
    };

    handleBypassConfirm = () => {
        if (this.submitting) {
            return;
        }
        this.reset();
        this.props.onBypassConfirm?.();
    };

    @action handleApprove = async() => {
        if (this.submitting) {
            return;
        }

        this.submitting = true;
        this.error = undefined;
        try {
            await this.props.onApprove?.(undefined);
            this.reset();
            this.props.onClose();
        } catch (e) {
            runInAction(() => {
                this.submitting = false;
                this.error = translate('sulu_content.workflow_transition_request.action_failed');
            });
        }
    };

    @action handleRejectSend = async() => {
        if (this.submitting) {
            return;
        }

        this.submitting = true;
        this.error = undefined;
        try {
            await this.props.onReject?.((this.comment || '').trim() || null);
            this.reset();
            this.props.onClose();
        } catch (e) {
            runInAction(() => {
                this.submitting = false;
                this.error = translate('sulu_content.workflow_transition_request.action_failed');
            });
        }
    };

    renderList(request: WorkflowTransitionRequestData) {
        const rows = buildRows(request);
        const requestedByName = request.createdBy
            ? request.createdBy.fullName
            : translate('sulu_admin.unknown_user');
        const requestedAt = formatTimestamp(request.requestedAt);
        const {approved, required} = request.approvalProgress;

        return (
            <div className={workflowTransitionRequestReviewOverlayStyles.body}>
                <ul className={workflowTransitionRequestReviewOverlayStyles.card}>
                    <li className={workflowTransitionRequestReviewOverlayStyles.header}>
                        <div className={workflowTransitionRequestReviewOverlayStyles.headerText}>
                            <div>
                                <strong>{requestedByName}</strong>
                                {' '}
                                {translate('sulu_content.workflow_transition_request.requested_a_review')}
                            </div>
                            {requestedAt && (
                                <div className={workflowTransitionRequestReviewOverlayStyles.headerDate}>
                                    {requestedAt}
                                </div>
                            )}
                        </div>
                        <div className={workflowTransitionRequestReviewOverlayStyles.headerCount}>
                            {translate(
                                'sulu_content.workflow_transition_request.n_of_m_approved',
                                {approved, required}
                            )}
                        </div>
                    </li>
                    {rows.length === 0
                        ? (
                            <li className={workflowTransitionRequestReviewOverlayStyles.empty}>
                                {translate('sulu_content.workflow_transition_request_no_reviewers')}
                            </li>
                        )
                        : rows.map((row) => <WorkflowTransitionRequestReviewRow key={row.key} {...row} />)
                    }
                </ul>
            </div>
        );
    }

    render() {
        const {canAct, mode = 'review', open, request} = this.props;

        const list = this.renderList(request);

        let title;
        let children;
        let footerActions = [];
        let onConfirm;
        let confirmText;
        let confirmDisabled = this.submitting;

        if (mode === 'bypass') {
            title = translate('sulu_content.workflow_transition_request.bypass_publish');
            children = list;
            onConfirm = this.handleBypassConfirm;
            confirmText = title;
        } else if (mode === 'view') {
            title = translate('sulu_content.workflow_transition_request.review_action');
            children = list;
            onConfirm = this.props.onClose;
            confirmText = translate('sulu_admin.close');
            confirmDisabled = false;
        } else if (this.rejecting) {
            title = translate('sulu_content.reject');
            children = (
                <div className={workflowTransitionRequestReviewOverlayStyles.body}>
                    <Heading label={translate('sulu_content.workflow_transition_request.why_did_you_reject')} />
                    <TextArea
                        onChange={this.handleCommentChange}
                        placeholder={
                            translate('sulu_content.workflow_transition_request.reject_reason_placeholder')
                        }
                        rows={6}
                        value={this.comment}
                    />
                </div>
            );
            onConfirm = () => void this.handleRejectSend();
            confirmText = translate('sulu_admin.send');
        } else {
            title = translate('sulu_content.workflow_transition_request.review_action');
            children = (
                <Fragment>
                    {list}
                    {!canAct && (
                        <div className={workflowTransitionRequestReviewOverlayStyles.body}>
                            <p className={workflowTransitionRequestReviewOverlayStyles.empty}>
                                {translate('sulu_content.cannot_review_own_request')}
                            </p>
                        </div>
                    )}
                </Fragment>
            );

            if (canAct) {
                footerActions = [{
                    onClick: this.handleOpenReject,
                    title: translate('sulu_content.reject'),
                }];
                onConfirm = () => void this.handleApprove();
                confirmText = translate('sulu_content.approve');
            }
        }

        return (
            <Overlay
                actions={footerActions}
                confirmDisabled={confirmDisabled}
                confirmLoading={this.submitting}
                confirmText={confirmText}
                onClose={this.handleClose}
                onConfirm={onConfirm}
                onSnackbarCloseClick={this.error ? this.handleSnackbarCloseClick : undefined}
                open={open}
                size="small"
                snackbarMessage={this.error}
                snackbarType="error"
                title={title}
            >
                {children}
            </Overlay>
        );
    }
}

export default WorkflowTransitionRequestReviewOverlay;
