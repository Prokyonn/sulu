// @flow
import {ResourceFormStore} from '../../../../containers/Form';
import ResourceStore from '../../../../stores/ResourceStore';
import Router from '../../../../services/Router';
import Form from '../../../../views/Form';
import BypassReviewAndPublishToolbarAction from '../../toolbarActions/BypassReviewAndPublishToolbarAction';

jest.mock('../../../../utils/Translator', () => ({
    translate: jest.fn((key) => key),
}));

jest.mock('../../../../stores/ResourceStore', () => jest.fn(function() {
    this.data = {};
}));

jest.mock('../../../../containers/Form/stores/ResourceFormStore', () => (
    class {
        resourceStore;
        constructor(resourceStore) {
            this.resourceStore = resourceStore;
        }

        get data() {
            return this.resourceStore.data;
        }
    }
));

jest.mock('../../../../services/Router', () => jest.fn());

jest.mock('../../../../views/Form', () => jest.fn(function() {
    this.save = jest.fn();
}));

const request = {
    approvalProgress: {approved: 0, rejected: 0, required: 2},
    createdBy: {fullName: 'Adam Ministrator', id: 5},
    id: 'request-1',
    locale: 'en',
    requestedAt: '2026-01-01T08:00:00+00:00',
    resourceId: '3',
    resourceKey: 'pages',
    reviewers: [],
    status: 'pending',
};

function createBypassToolbarAction(options = {}) {
    const resourceStore = new ResourceStore('test');
    const resourceFormStore = new ResourceFormStore(resourceStore, 'test');
    const router = new Router({});
    const form = new Form({
        locales: [],
        resourceStore,
        route: router.route,
        router,
    });

    return new BypassReviewAndPublishToolbarAction(resourceFormStore, form, router, [], options, resourceStore);
}

test('Return no item config and no node without an active request', () => {
    const toolbarAction = createBypassToolbarAction();

    expect(toolbarAction.getToolbarItemConfig()).toBeUndefined();
    expect(toolbarAction.getNode(0)).toBeNull();
});

test('Open the overlay when the button is clicked', () => {
    const toolbarAction = createBypassToolbarAction();
    toolbarAction.resourceFormStore.resourceStore.data.activeWorkflowTransitionRequest = request;

    const toolbarItemConfig = toolbarAction.getToolbarItemConfig();

    if (!toolbarItemConfig) {
        throw new Error('The toolbarItemConfig should be a value!');
    }

    expect(toolbarItemConfig).toEqual(expect.objectContaining({
        label: 'sulu_content.workflow_transition_request.bypass_publish',
        disabled: false,
        type: 'button',
    }));

    toolbarItemConfig.onClick();

    expect(toolbarAction.open).toBe(true);
});

test('Return item config with disabled button if passed disabled_condition is met', () => {
    const toolbarAction = createBypassToolbarAction({
        disabled_condition: 'activeWorkflowTransitionRequest.status == "approved"',
    });
    toolbarAction.resourceFormStore.resourceStore.data.activeWorkflowTransitionRequest = {
        ...request,
        status: 'approved',
    };

    expect(toolbarAction.getToolbarItemConfig()).toEqual(expect.objectContaining({disabled: true}));
});

test('Close the overlay and save without schema validation when the overlay is confirmed', () => {
    const toolbarAction = createBypassToolbarAction();
    toolbarAction.resourceFormStore.resourceStore.data.activeWorkflowTransitionRequest = request;
    toolbarAction.handleOpen();

    toolbarAction.handleBypassConfirm();

    expect(toolbarAction.open).toBe(false);
    expect(toolbarAction.form.save).toHaveBeenCalledWith({action: 'bypass_publish', skipValidation: true});
});
