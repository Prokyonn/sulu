// @flow
import React from 'react';
import classNames from 'classnames';
import publishIndicatorStyles from './publishIndicator.scss';

// Mirrors the workflow places of `Sulu\Content\Application\ContentWorkflow\ContentWorkflow`.
type WorkflowState = 'unpublished' | 'review' | 'published' | 'draft' | 'review_draft';

type Props = {
    className?: string,
    draft: boolean,
    published: boolean,
    state?: ?WorkflowState,
};

export default class PublishIndicator extends React.Component<Props> {
    static defaultProps = {
        draft: false,
        published: false,
    };

    /** The legacy `draft` / `published` props stay supported for callers that pass no `state`. */
    getDots(): {draft: boolean, published: boolean, review: boolean} {
        const {draft, published, state} = this.props;

        switch (state) {
            case 'published':
                return {draft: false, published: true, review: false};
            case 'unpublished':
                return {draft: true, published: false, review: false};
            case 'review':
                return {draft: false, published: false, review: true};
            case 'draft':
                return {draft: true, published: true, review: false};
            case 'review_draft':
                return {draft: false, published: true, review: true};
            default:
                return {draft, published, review: false};
        }
    }

    render() {
        const {className} = this.props;
        const dots = this.getDots();

        if (!dots.published && !dots.draft && !dots.review) {
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
            </div>
        );
    }
}
