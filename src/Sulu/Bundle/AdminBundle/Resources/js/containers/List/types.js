// @flow
import type {Node} from 'react';
import type {IObservableValue} from 'mobx/lib/mobx';
import {RequestPromise} from '../../services/Requester';

export type DataItem = {
    id: string | number,
};

export type ColumnItem = DataItem & {
    hasChildren: boolean,
};

export type SchemaEntry = {
    filterType: ?string,
    filterTypeParameters: ?{[string]: mixed},
    label: string,
    sortable: boolean,
    transformerTypeParameters: {[string]: mixed},
    type: string,
    visibility: 'always' | 'yes' | 'no' | 'never',
};

export type Schema = {
    [string]: SchemaEntry,
};

export type SortOrder = 'asc' | 'desc';

export type ItemActionConfig = {|
    disabled?: boolean,
    icon: string,
    onClick: ?(rowId: string | number, index: number) => void,
|};

export type ItemActionsProvider = (item: ?Object) => Array<ItemActionConfig>;

export type AdapterOptions = {[key: string]: mixed};

export type ListAdapterProps = {|
    active: ?string | number,
    activeItems: ?Array<?string | number>,
    adapterOptions?: AdapterOptions,
    data: Array<*>,
    disabledIds: Array<string | number>,
    itemActionsProvider?: ItemActionsProvider,
    limit: number,
    loading: boolean,
    onAllSelectionChange: ?(selected?: boolean) => void,
    onItemActivate: (itemId: ?string | number) => void,
    onItemAdd: ?(id: ?string | number) => void,
    onItemClick: ?(itemId: string | number) => void,
    onItemDeactivate: (itemId: string | number) => void,
    onItemSelectionChange: ?(rowId: string | number, selected?: boolean) => void,
    onLimitChange: (limit: number) => void,
    onPageChange: (page: number) => void,
    onRequestItemCopy: ?(id: string | number) => Promise<{copied: boolean, parent: ?Object}>,
    onRequestItemDelete: ?(id: string | number) => Promise<{deleted: boolean}>,
    onRequestItemMove: ?(id: string | number) => Promise<{moved: boolean, parent: ?Object}>,
    onRequestItemOrder: ?(id: string | number, position: number) => Promise<{ordered: boolean}>,
    onSort: (column: string, order: SortOrder) => void,
    options: Object,
    page: ?number,
    pageCount: ?number,
    paginated: boolean,
    schema: Schema,
    selections: Array<number | string>,
    sortColumn: ?string,
    sortOrder: ?SortOrder,
|};

export type ObservableOptions = {
    locale?: ?IObservableValue<string>,
    page: IObservableValue<number>,
};

export type LoadOptions = {
    limit?: number,
    locale?: ?string,
    page?: number,
    sortBy?: string,
    sortOrder?: SortOrder,
};

export interface LoadingStrategyInterface {
    constructor(options: LoadingStrategyOptions): void,
    load(resourceKey: string, options: LoadOptions, parentId: ?string | number): RequestPromise<Object>,
    setStructureStrategy(structureStrategy: StructureStrategyInterface): void,
}

export type LoadingStrategyOptions = {
    paginated: boolean,
}

export interface StructureStrategyInterface {
    +activate?: (id: ?string | number) => void,
    +activeItems?: Array<*>,
    +addItem: (item: Object, parentId: ?string | number) => void,
    +clear: (parentId: ?string | number) => void,
    +constructor: () => void,
    +data: Array<*>,
    +deactivate?: (id: ?string | number) => void,
    +findById: (identifier: string | number) => ?Object,
    +order: (id: string | number, position: number) => void,
    +remove: (id: string | number) => void,
    +visibleItems: Array<Object>,
}

export type TreeItem = {
    children: Array<TreeItem>,
    data: DataItem,
    hasChildren: boolean,
};

export interface FieldTransformer {
    transform(value: *, parameters: {[string]: mixed}): Node,
}

export type ResolveCopyArgument = {copied: boolean, parent?: ?Object};
export type ResolveDeleteArgument = {deleted: boolean};
export type ResolveMoveArgument = {moved: boolean, parent?: ?Object};
export type ResolveOrderArgument = {ordered: boolean};
