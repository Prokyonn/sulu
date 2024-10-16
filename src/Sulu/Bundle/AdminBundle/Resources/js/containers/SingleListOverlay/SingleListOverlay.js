// @flow
import React from 'react';
import {autorun, comparer, computed, observable, reaction} from 'mobx';
import type {IObservableValue} from 'mobx/lib/mobx';
import {observer} from 'mobx-react';
import ListStore from '../../containers/List/stores/ListStore';
import ListOverlay from '../ListOverlay';
import type {OverlayType} from '../ListOverlay';

const USER_SETTINGS_KEY = 'single_list_overlay';

type Props = {|
    adapter: string,
    allowActivateForDisabledItems?: boolean,
    clearSelectionOnClose: boolean,
    confirmLoading?: boolean,
    disabledIds: Array<string | number>,
    excludedIds: Array<string | number>,
    itemDisabledCondition?: ?string,
    listKey: string,
    locale?: ?IObservableValue<string>,
    metadataOptions?: ?Object,
    onClose: () => void,
    onConfirm: (selectedItem: Object) => void,
    open: boolean,
    options?: Object,
    overlayType: OverlayType,
    preSelectedItem?: ?Object,
    reloadOnOpen?: boolean,
    resourceKey: string,
    title: string,
|};

@observer
class SingleListOverlay extends React.Component<Props> {
    static defaultProps = {
        clearSelectionOnClose: false,
        disabledIds: [],
        excludedIds: [],
        overlayType: 'overlay',
    };

    page: IObservableValue<number> = observable.box(1);
    listStore: ListStore;
    excludedIdsDisposer: () => void;
    changeOptionsDisposer: () => *;
    selectionDisposer: () => void;

    constructor(props: Props) {
        super(props);

        const excludedIds = computed(
            () => this.props.excludedIds.length ? this.props.excludedIds : undefined,
            {equals: comparer.structural}
        );
        this.excludedIdsDisposer = excludedIds.observe(() => this.listStore.clear());

        const {listKey, locale, metadataOptions, options, preSelectedItem, resourceKey} = this.props;
        const observableOptions = {};
        observableOptions.page = this.page;
        observableOptions.excludedIds = excludedIds;

        if (locale) {
            observableOptions.locale = locale;
        }

        const initialSelectionIds = [];
        if (preSelectedItem) {
            initialSelectionIds.push(preSelectedItem.id);
        }
        this.listStore = new ListStore(
            resourceKey,
            listKey,
            USER_SETTINGS_KEY,
            observableOptions,
            options,
            metadataOptions,
            initialSelectionIds
        );

        this.changeOptionsDisposer = reaction(
            () => this.props.options,
            (options) => {
                // reset liststore to reload whole tree instead of children of current active item
                this.listStore.reset();
                // set selected items as initialSelectionIds to expand them in case of a tree
                this.listStore.initialSelectionIds = this.listStore.selectionIds;
                this.listStore.options = {...this.listStore.options, ...options};
            },
            {equals: comparer.structural}
        );

        this.selectionDisposer = autorun(() => {
            const {selections} = this.listStore;

            if (selections.length <= 1) {
                return;
            }

            const selection = selections[selections.length - 1];

            if (!selection) {
                return;
            }

            this.listStore.clearSelection();
            this.listStore.select(selection);
        });
    }

    componentWillUnmount() {
        this.listStore.destroy();
        this.excludedIdsDisposer();
        this.changeOptionsDisposer();
        this.selectionDisposer();
    }

    handleConfirm = () => {
        if (this.listStore.selections.length > 1) {
            throw new Error(
                'The SingleListOverlay can only handle single selection.'
                + 'This should not happen and is likely a bug.'
            );
        }

        this.props.onConfirm(this.listStore.selections[0]);
    };

    render() {
        const {
            adapter,
            allowActivateForDisabledItems,
            clearSelectionOnClose,
            confirmLoading,
            disabledIds,
            itemDisabledCondition,
            onClose,
            open,
            overlayType,
            preSelectedItem,
            reloadOnOpen,
            title,
        } = this.props;

        return (
            <ListOverlay
                adapter={adapter}
                allowActivateForDisabledItems={allowActivateForDisabledItems}
                clearSelectionOnClose={clearSelectionOnClose}
                confirmLoading={confirmLoading}
                disabledIds={disabledIds}
                itemDisabledCondition={itemDisabledCondition}
                listStore={this.listStore}
                onClose={onClose}
                onConfirm={this.handleConfirm}
                open={open}
                overlayType={overlayType}
                preSelectedItems={preSelectedItem ? [preSelectedItem] : undefined}
                reloadOnOpen={reloadOnOpen}
                title={title}
            />
        );
    }
}

export default SingleListOverlay;
