// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {action, observable, runInAction} from 'mobx';
import classNames from 'classnames';
import Overlay from '../../../components/Overlay';
import Button from '../../../components/Button';
import Icon from '../../../components/Icon';
import TextArea from '../../../components/TextArea';
import {translate} from '../../../utils/Translator';
import {reviewerName} from './reviewers';
import workflowTransitionRequestReviewOverlayStyles from './workflowTransitionRequestReviewOverlay.scss';
import type {ReviewerStatus, WorkflowTransitionRequestData} from './types';
import type {Node} from 'react';

type Step = 'list' | 'approve' | 'reject';

type RowData = {|
    caption: string,
    comment: ?string,
    id: string,
    retryValidatorKey: ?string,
    status: ReviewerStatus,
    title: ?string,
|};

const STATUS_ICON: {[ReviewerStatus]: string} = {
    approved: 'su-check',
    pending: 'su-circle-full',
    rejected: 'su-times',
};

const STATUS_CAPTION: {[ReviewerStatus]: string} = {
    approved: 'sulu_content.workflow_transition_request.reviewer_caption_approved',
    pending: 'sulu_content.workflow_transition_request.reviewer_caption_waiting',
    rejected: 'sulu_content.workflow_transition_request.reviewer_caption_rejected',
};

const DATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function resolveErrorDetail(error: Object): Promise<?string> {
    if (typeof error?.json !== 'function') {
        return Promise.resolve(undefined);
    }

    return error.json().then((data) => data?.detail).catch(() => undefined);
}

function isClosed(request: WorkflowTransitionRequestData): boolean {
    return request.status === 'cancelled' || request.status === 'published';
}

function buildRows(request: WorkflowTransitionRequestData, canRetry: boolean): Array<RowData> {
    const validators = request.reviewers.filter((reviewer) => reviewer.type === 'validator');
    const users = request.reviewers.filter((reviewer) => reviewer.type === 'user');

    const rows = validators.map((reviewer) => ({
        caption: translate(STATUS_CAPTION[reviewer.status]),
        comment: reviewer.comment,
        id: reviewer.id,
        retryValidatorKey: canRetry && reviewer.status !== 'approved' ? reviewer.validatorKey : null,
        status: reviewer.status,
        title: reviewerName(reviewer),
    }));

    users.forEach((reviewer) => rows.push({
        caption: translate(STATUS_CAPTION[reviewer.status]),
        comment: reviewer.comment,
        id: reviewer.id,
        retryValidatorKey: null,
        status: reviewer.status,
        title: reviewerName(reviewer),
    }));

    const {approved, required} = request.approvalProgress;
    const pendingValidators = validators.filter((reviewer) => reviewer.status === 'pending').length;

    for (let index = 0; index < Math.max(0, required - approved - pendingValidators); index++) {
        rows.push({
            caption: translate(STATUS_CAPTION.pending),
            comment: null,
            id: 'waiting-' + index,
            retryValidatorKey: null,
            status: 'pending',
            title: null,
        });
    }

    return rows;
}

type RowProps = {|
    caption: string,
    comment: ?string,
    onRetry: (validatorKey: string) => void,
    retryValidatorKey: ?string,
    status: ReviewerStatus,
    title: ?string,
|};

@observer
class WorkflowTransitionRequestReviewRow extends React.Component<RowProps> {
    @observable expanded: boolean = false;

    @action handleToggle = () => {
        this.expanded = !this.expanded;
    };

    render() {
        const {caption, comment, onRetry, retryValidatorKey, status, title} = this.props;

        return (
            <li className={workflowTransitionRequestReviewOverlayStyles.row}>
                <div
                    className={classNames(
                        workflowTransitionRequestReviewOverlayStyles.rowMain,
                        {[workflowTransitionRequestReviewOverlayStyles.rowMainExpanded]: this.expanded}
                    )}
                >
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
                        {comment && this.expanded && (
                            <span className={workflowTransitionRequestReviewOverlayStyles.rowComment}>{comment}</span>
                        )}
                    </span>
                    {retryValidatorKey && (
                        <Button
                            className={workflowTransitionRequestReviewOverlayStyles.rowRetry}
                            onClick={onRetry}
                            skin="link"
                            value={retryValidatorKey}
                        >
                            {translate('sulu_content.workflow_transition_request.retry')}
                        </Button>
                    )}
                    {comment && (
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
            </li>
        );
    }
}

type Props = {|
    canAct: boolean,
    mode?: 'review' | 'bypass',
    onApprove?: (comment: ?string) => Promise<mixed>,
    onBypassConfirm?: () => void,
    onClose: () => void,
    onReject?: (comment: string) => Promise<mixed>,
    onRetry?: (validatorKey: string) => Promise<mixed>,
    open: boolean,
    request: WorkflowTransitionRequestData,
    userDecision?: ?ReviewerStatus,
|};

@observer
class WorkflowTransitionRequestReviewOverlay extends React.Component<Props> {
    @observable step: Step = 'list';
    @observable comment: ?string = undefined;
    @observable submitting: boolean = false;
    @observable error: string | typeof undefined = undefined;

    @action handleCommentChange = (value: ?string) => {
        this.comment = value;
    };

    @action handleSnackbarCloseClick = () => {
        this.error = undefined;
    };

    @action reset = () => {
        this.step = 'list';
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
        this.reset();
        this.props.onBypassConfirm?.();
    };

    @action handleApproveClick = () => {
        this.step = 'approve';
    };

    @action handleRejectClick = () => {
        this.step = 'reject';
    };

    handleSendClick = () => {
        const comment = (this.comment || '').trim();

        void this.send(
            this.step === 'reject'
                ? this.props.onReject?.(comment)
                : this.props.onApprove?.(comment || null)
        );
    };

    handleRetryClick = (validatorKey: string) => {
        void this.send(this.props.onRetry?.(validatorKey));
    };

    /** Stays open on success: the caller reloads the request, and the reviewer reads the new state here. */
    @action async send(decision: ?Promise<mixed>) {
        if (this.submitting) {
            return;
        }

        this.submitting = true;
        this.error = undefined;

        try {
            await decision;
            this.reset();
        } catch (error) {
            const detail = await resolveErrorDetail(error);
            runInAction(() => {
                this.submitting = false;
                this.error = detail || translate('sulu_content.workflow_transition_request.action_failed');
            });
        }
    }

    renderFooterButtons(buttons: Array<Node>) {
        return (
            <div className={workflowTransitionRequestReviewOverlayStyles.footerActions}>
                {buttons}
            </div>
        );
    }

    renderFooter() {
        const {canAct, mode = 'review', request, userDecision} = this.props;

        if (mode === 'bypass') {
            return this.renderFooterButtons([
                <Button
                    className={workflowTransitionRequestReviewOverlayStyles.confirmButton}
                    key="bypass"
                    onClick={this.handleBypassConfirm}
                    skin="primary"
                >
                    {translate('sulu_content.workflow_transition_request.bypass_publish')}
                </Button>,
            ]);
        }

        if (!canAct) {
            return undefined;
        }

        if (this.step !== 'list') {
            return this.renderFooterButtons([
                <Button
                    className={workflowTransitionRequestReviewOverlayStyles.confirmButton}
                    disabled={this.step === 'reject' && !(this.comment || '').trim()}
                    key="send"
                    loading={this.submitting}
                    onClick={this.handleSendClick}
                    skin="primary"
                >
                    {translate('sulu_admin.send')}
                </Button>,
            ]);
        }

        const closed = isClosed(request);

        return this.renderFooterButtons([
            <Button
                className={workflowTransitionRequestReviewOverlayStyles.rejectButton}
                disabled={closed || userDecision === 'rejected'}
                key="reject"
                onClick={this.handleRejectClick}
                skin="secondary"
            >
                {translate('sulu_content.reject')}
            </Button>,
            <Button
                className={workflowTransitionRequestReviewOverlayStyles.confirmButton}
                disabled={closed || userDecision === 'approved'}
                key="approve"
                onClick={this.handleApproveClick}
                skin="primary"
            >
                {userDecision === 'approved'
                    ? translate('sulu_content.workflow_transition_request.you_approved')
                    : translate('sulu_content.approve')}
            </Button>,
        ]);
    }

    renderComment() {
        const label = this.step === 'reject'
            ? translate('sulu_content.workflow_transition_request.why_did_you_reject')
            : translate('sulu_content.workflow_transition_request.approve_comment_label');

        return (
            <div className={workflowTransitionRequestReviewOverlayStyles.commentBody}>
                <div className={workflowTransitionRequestReviewOverlayStyles.commentLabel}>{label}</div>
                <div className={workflowTransitionRequestReviewOverlayStyles.commentField}>
                    <TextArea
                        onChange={this.handleCommentChange}
                        placeholder={translate('sulu_content.workflow_transition_request.comment_placeholder')}
                        value={this.comment}
                    />
                </div>
            </div>
        );
    }

    renderList() {
        const {canAct, onRetry, request} = this.props;
        const {approved, rejected, required} = request.approvalProgress;
        const rows = buildRows(request, canAct && !!onRetry && !isClosed(request));
        const requestedByName = request.createdBy
            ? request.createdBy.fullName
            : translate('sulu_admin.unknown_user');

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
                            <div className={workflowTransitionRequestReviewOverlayStyles.headerDate}>
                                {DATE_FORMATTER.format(new Date(request.requestedAt))}
                            </div>
                        </div>
                        <div className={workflowTransitionRequestReviewOverlayStyles.headerCount}>
                            {translate(
                                'sulu_content.workflow_transition_request.n_of_m_approved',
                                {approved, required}
                            )}
                            {rejected > 0 && (
                                <span className={workflowTransitionRequestReviewOverlayStyles.headerRejected}>
                                    {translate('sulu_content.workflow_transition_request.n_rejected', {rejected})}
                                </span>
                            )}
                        </div>
                    </li>
                    {rows.length === 0
                        ? (
                            <li className={workflowTransitionRequestReviewOverlayStyles.empty}>
                                {translate('sulu_content.workflow_transition_request.no_reviewers')}
                            </li>
                        )
                        : rows.map(({id, ...row}) => (
                            <WorkflowTransitionRequestReviewRow
                                {...row}
                                key={id}
                                onRetry={this.handleRetryClick}
                            />
                        ))
                    }
                </ul>
                {!canAct && (
                    <p className={workflowTransitionRequestReviewOverlayStyles.notice}>
                        {translate('sulu_content.workflow_transition_request.self_review_not_allowed')}
                    </p>
                )}
            </div>
        );
    }

    render() {
        const {mode = 'review', open} = this.props;

        let title = translate('sulu_content.workflow_transition_request.review_action');
        if (mode === 'bypass') {
            title = translate('sulu_content.workflow_transition_request.bypass_publish');
        } else if (this.step === 'reject') {
            title = translate('sulu_content.reject');
        } else if (this.step === 'approve') {
            title = translate('sulu_content.approve');
        }

        return (
            <Overlay
                footer={this.renderFooter()}
                onClose={this.handleClose}
                onSnackbarCloseClick={this.error ? this.handleSnackbarCloseClick : undefined}
                open={open}
                snackbarMessage={this.error}
                snackbarType="error"
                title={title}
            >
                {mode === 'review' && this.step !== 'list' ? this.renderComment() : this.renderList()}
            </Overlay>
        );
    }
}

export default WorkflowTransitionRequestReviewOverlay;
