// @flow
import React from 'react';
import classNames from 'classnames';
import publishIndicatorStyles from './publishIndicator.scss';

// Mirrors the workflow places defined in
// `Sulu\Content\Application\ContentWorkflow\ContentWorkflow`.
type WorkflowState = 'unpublished' | 'review' | 'published' | 'draft' | 'review_draft';

type Props = {
    className?: string,
    draft: boolean,
    published: boolean,
    state?: WorkflowState,
};

export default class PublishIndicator extends React.Component<Props> {
    static defaultProps = {
        draft: false,
        published: false,
    };

    /**
     * Maps a workflow place to the dots that should render. The legacy `draft` / `published`
     * boolean props remain supported for callers that haven't migrated to `state` yet (lists,
     * column adapters, etc.); they map to the equivalent state for rendering purposes.
     */
    getDots(): {draft: boolean, plain: boolean, published: boolean, review: boolean} {
        const {draft, published, state} = this.props;

        switch (state) {
            case 'published':
                return {draft: false, plain: false, published: true, review: false};
            case 'unpublished':
                return {draft: false, plain: true, published: false, review: false};
            case 'review':
                return {draft: false, plain: false, published: false, review: true};
            case 'draft':
                return {draft: false, plain: true, published: true, review: false};
            case 'review_draft':
                return {draft: true, plain: false, published: true, review: false};
            case undefined:
            default:
                return {draft, plain: false, published, review: false};
        }
    }

    render() {
        const {className} = this.props;
        const dots = this.getDots();

        if (!dots.published && !dots.draft && !dots.review && !dots.plain) {
            return null;
        }

        const containerClass = classNames(
            publishIndicatorStyles.publishIndicator,
            className
        );

        return (
            <div className={containerClass}>
                {dots.published && <span className={publishIndicatorStyles.published} />}
                {dots.review && <span className={publishIndicatorStyles.review} />}
                {dots.draft && <span className={publishIndicatorStyles.draft} />}
                {dots.plain && <span className={publishIndicatorStyles.draftPlain} />}
            </div>
        );
    }
}
