// @flow
import React from 'react';
import {render} from '@testing-library/react';
import PublishIndicator from '../PublishIndicator';

test('Show only the publish icon', () => {
    const {container} = render(<PublishIndicator published={true} />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).not.toBeInTheDocument();
});

test('Show only the draft icon', () => {
    const {container} = render(<PublishIndicator draft={true} />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published, .review')).not.toBeInTheDocument();
});

test('Show the draft and published icon', () => {
    const {container} = render(<PublishIndicator draft={true} published={true} />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).toBeInTheDocument();
});

test('state="published" renders only the green dot', () => {
    const {container} = render(<PublishIndicator state="published" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft, .review')).not.toBeInTheDocument();
});

test('state="unpublished" renders only the grey draft dot', () => {
    const {container} = render(<PublishIndicator state="unpublished" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published, .review')).not.toBeInTheDocument();
});

test('state="review" renders only the yellow review dot', () => {
    const {container} = render(<PublishIndicator state="review" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.review')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published, .draft')).not.toBeInTheDocument();
});

test('state="draft" renders the green and the grey draft dot', () => {
    const {container} = render(<PublishIndicator state="draft" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).toBeInTheDocument();
});

test('state="review_draft" renders the green and the yellow review dot', () => {
    const {container} = render(<PublishIndicator state="review_draft" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.review')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).not.toBeInTheDocument();
});
