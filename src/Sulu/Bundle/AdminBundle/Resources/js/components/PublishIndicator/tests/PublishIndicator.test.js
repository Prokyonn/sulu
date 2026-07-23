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
    expect(container.querySelector('.draft, .draft-plain, .review')).not.toBeInTheDocument();
});

test('state="unpublished" renders only the grey dot', () => {
    const {container} = render(<PublishIndicator state="unpublished" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draftPlain')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published, .draft, .review')).not.toBeInTheDocument();
});

test('state="review" renders only the yellow review dot', () => {
    const {container} = render(<PublishIndicator state="review" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.review')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published, .draft, .draftPlain')).not.toBeInTheDocument();
});

test('state="draft" renders green + grey', () => {
    const {container} = render(<PublishIndicator state="draft" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draftPlain')).toBeInTheDocument();
});

test('state="review_draft" renders green + yellow', () => {
    const {container} = render(<PublishIndicator state="review_draft" />);

    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.published')).toBeInTheDocument();
    // eslint-disable-next-line testing-library/no-container
    expect(container.querySelector('.draft')).toBeInTheDocument();
});
