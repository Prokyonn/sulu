// @flow
import type {ElementRef} from 'react';
import React from 'react';
import type {IObservableValue} from 'mobx/lib/mobx';
import {action, computed, toJS, isObservableArray, observable} from 'mobx';
import {observer} from 'mobx-react';
import equals from 'fast-deep-equal';
import log from 'loglevel';
import Dialog from '../../components/Dialog';
import PublishIndicator from '../../components/PublishIndicator';
import {default as FormContainer, ResourceFormStore, resourceFormStoreFactory} from '../../containers/Form';
import {withToolbar} from '../../containers/Toolbar';
import type {ViewProps} from '../../containers/ViewRenderer';
import type {AttributeMap, UpdateRouteMethod} from '../../services/Router/types';
import ResourceStore from '../../stores/ResourceStore';
import CollaborationStore from '../../stores/CollaborationStore';
import {translate} from '../../utils/Translator';
import {Route} from '../../services/Router';
import formToolbarActionRegistry from './registries/formToolbarActionRegistry';
import AbstractFormToolbarAction from './toolbarActions/AbstractFormToolbarAction';
import formStyles from './form.scss';

type Props = {
    ...ViewProps,
    locales: Array<string>,
    resourceStore: ResourceStore,
    title?: string,
};

const FORM_STORE_UPDATE_ROUTE_HOOK_PRIORITY = 2048;

const HAS_CHANGED_ERROR_CODE = 1102;

@observer
class Form extends React.Component<Props> {
    resourceStore: ResourceStore;
    resourceFormStore: ResourceFormStore;
    collaborationStore: ?CollaborationStore;
    form: ?ElementRef<typeof FormContainer>;
    @observable errors: Array<string> = [];
    showSuccess: IObservableValue<boolean> = observable.box(false);
    @observable toolbarActions: Array<AbstractFormToolbarAction> = [];
    @observable showDirtyWarning: boolean = false;
    @observable showHasChangedWarning: boolean = false;
    postponedSaveOptions: Object;
    postponedUpdateRouteMethod: ?UpdateRouteMethod;
    postponedRoute: ?Route;
    postponedRouteAttributes: ?AttributeMap;
    checkFormStoreDirtyStateBeforeNavigationDisposer: () => void;

    @computed get hasOwnResourceStore() {
        const {resourceStore} = this.props;

        return this.resourceKey && resourceStore.resourceKey !== this.resourceKey;
    }

    @computed.struct get locales() {
        const {
            locales: propsLocales,
            route: {
                options: {
                    locales: routeLocales,
                },
            },
        } = this.props;

        return routeLocales ? routeLocales : propsLocales;
    }

    @computed get id() {
        const {
            router: {
                attributes: {
                    id,
                },
            },
        } = this.props;

        if (id !== undefined && typeof id !== 'string' && typeof id !== 'number') {
            throw new Error('The "id" router attribute must be a string or a number if given!');
        }

        return id;
    }

    @computed get resourceKey() {
        const {
            route: {
                options: {
                    resourceKey,
                },
            },
        } = this.props;

        return resourceKey;
    }

    @computed get formKey() {
        const {
            route: {
                options: {
                    formKey,
                },
            },
        } = this.props;

        if (!formKey) {
            throw new Error('The route does not define the mandatory "formKey" option');
        }

        return formKey;
    }

    @computed get formStoreOptions() {
        const {
            attributes,
            route: {
                options: {
                    requestParameters = {},
                    routerAttributesToFormRequest = {},
                },
            },
        } = this.props.router;

        const formStoreOptions = requestParameters ? requestParameters : {};
        Object.keys(toJS(routerAttributesToFormRequest)).forEach((key) => {
            const formOptionKey = routerAttributesToFormRequest[key];
            const attributeName = isNaN(key) ? key : toJS(routerAttributesToFormRequest[key]);

            formStoreOptions[formOptionKey] = attributes[attributeName];
        });

        return formStoreOptions;
    }

    @computed get metadataOptions() {
        const {
            attributes,
            route: {
                options: {
                    routerAttributesToFormMetadata = {},
                    metadataRequestParameters = {},
                },
            },
        } = this.props.router;

        const metadataOptions = {...metadataRequestParameters};

        Object.keys(toJS(routerAttributesToFormMetadata)).forEach((key) => {
            const listOptionKey = routerAttributesToFormMetadata[key];
            const attributeName = isNaN(key) ? key : toJS(routerAttributesToFormMetadata[key]);

            metadataOptions[listOptionKey] = attributes[attributeName];
        });

        return metadataOptions;
    }

    constructor(props: Props) {
        super(props);

        const {router} = this.props;

        this.createResourceFormStore();
        this.createCollaborationStore();

        this.checkFormStoreDirtyStateBeforeNavigationDisposer = router.addUpdateRouteHook(
            this.checkFormStoreDirtyStateBeforeNavigation,
            FORM_STORE_UPDATE_ROUTE_HOOK_PRIORITY
        );
    }

    createResourceFormStore = () => {
        const {resourceStore, router} = this.props;
        const {
            route: {
                options: {
                    idQueryParameter,
                },
            },
        } = router;

        if (!resourceStore) {
            throw new Error(
                'The view "Form" needs a resourceStore to work properly.'
                + 'Did you maybe forget to make this view a child of a "ResourceTabs" view?'
            );
        }

        if (this.hasOwnResourceStore) {
            let locale = resourceStore.locale;
            if (!locale && this.locales) {
                locale = observable.box();
            }

            if (idQueryParameter) {
                this.resourceStore = new ResourceStore(
                    this.resourceKey,
                    this.id,
                    {locale},
                    this.formStoreOptions,
                    idQueryParameter
                );
            } else {
                this.resourceStore = new ResourceStore(this.resourceKey, this.id, {locale}, this.formStoreOptions);
            }
        } else {
            this.resourceStore = resourceStore;
        }

        this.resourceFormStore = resourceFormStoreFactory.createFromResourceStore(
            this.resourceStore,
            this.formKey,
            this.formStoreOptions,
            this.metadataOptions
        );

        if (this.resourceStore.locale) {
            router.bind('locale', this.resourceStore.locale);
        }
    };

    createCollaborationStore = () => {
        if (this.resourceKey && this.id) {
            this.collaborationStore = new CollaborationStore(this.resourceKey, this.id);
        }
    };

    @action checkFormStoreDirtyStateBeforeNavigation = (
        route: ?Route,
        attributes: ?AttributeMap,
        updateRouteMethod: ?UpdateRouteMethod
    ) => {
        if (!this.resourceFormStore.dirty) {
            return true;
        }

        const {route: viewRoute, router} = this.props;
        if (router.route !== viewRoute) {
            // If the route of this view does not match the currently active route anymore, then another view has
            // already been loaded, and the warning does not need to be shown anymore. This happens e.g. when this view
            // navigates to a Tab view, which will in turn do a redirect.
            return true;
        }

        if (
            this.showDirtyWarning === true
            && this.postponedRoute === route
            && equals(this.postponedRouteAttributes, attributes)
            && this.postponedUpdateRouteMethod === updateRouteMethod
        ) {
            // If the warning has already been displayed for the exact same route and attributes we can assume that the
            // confirm button in the warning has been clicked, since it calls the same routing action again.
            return true;
        }

        if (!route && !attributes && !updateRouteMethod) {
            // If none of these attributes are set the call comes because the user wants to close the window
            return false;
        }

        this.showDirtyWarning = true;
        this.postponedUpdateRouteMethod = updateRouteMethod;
        this.postponedRoute = route;
        this.postponedRouteAttributes = attributes;

        return false;
    };

    @action componentDidMount() {
        const {resourceStore: parentResourceStore, router} = this.props;
        const {
            route: {
                options: {
                    toolbarActions: rawToolbarActions,
                },
            },
        } = router;

        if (
            !Array.isArray(rawToolbarActions)
            && !isObservableArray(rawToolbarActions)
        ) {
            throw new Error('The view "Form" needs some defined toolbarActions to work properly!');
        }

        const toolbarActions = toJS(rawToolbarActions);

        toolbarActions.forEach((toolbarAction) => {
            if (typeof toolbarAction !== 'object') {
                throw new Error(
                    'The value of a toolbarAction entry must be an object, but ' + typeof toolbarAction + ' was given!'
                );
            }
        });

        this.toolbarActions = toolbarActions
            .map((toolbarAction): AbstractFormToolbarAction => new (formToolbarActionRegistry.get(toolbarAction.type))(
                this.resourceFormStore,
                this,
                router,
                this.locales,
                toolbarAction.options,
                parentResourceStore
            ));
    }

    componentDidUpdate(prevProps: Props) {
        if (!equals(this.props.locales, prevProps.locales)) {
            this.toolbarActions.forEach((toolbarAction) => {
                toolbarAction.setLocales(this.locales);
            });
        }
    }

    componentWillUnmount() {
        this.checkFormStoreDirtyStateBeforeNavigationDisposer();

        this.resourceFormStore.destroy();

        if (this.collaborationStore) {
            this.collaborationStore.destroy();
        }

        if (this.hasOwnResourceStore) {
            this.resourceStore.destroy();
        }

        this.toolbarActions.forEach((toolbarAction) => toolbarAction.destroy());
    }

    @action showSuccessSnackbar = () => {
        this.showSuccess.set(true);
    };

    @action submit = (options: ?string | {[string]: any}) => {
        if (typeof options === 'string') {
            log.warn(
                'Passing a string to the "submit" method is deprecated since 2.2 and will be removed. ' +
                'Pass an object with an "action" property instead.'
            );
        }

        if (!this.form) {
            throw new Error('The form ref has not been set! This should not happen and is likely a bug.');
        }
        this.form.submit(options);
    };

    handleSubmit = (options: ?string | {[string]: any}) => {
        if (typeof options === 'string') {
            log.warn(
                'Passing a string to the "submit" method is deprecated since 2.2 and will be removed. ' +
                'Pass an object with an "action" property instead.'
            );

            options = {action: options};
        }

        return this.save(options);
    };

    handleSuccess = () => {
        this.showSuccessSnackbar();
    };

    save = (options: Object) => {
        const {resourceStore, router} = this.props;

        const {
            attributes,
            route: {
                options: {
                    editView,
                    routerAttributesToEditView,
                },
            },
        } = router;

        if (editView) {
            resourceStore.destroy();
        }

        const saveOptions = {...options};

        const editViewParameters = {};

        if (routerAttributesToEditView) {
            Object.keys(toJS(routerAttributesToEditView)).forEach((key) => {
                const formOptionKey = routerAttributesToEditView[key];
                const attributeName = isNaN(key) ? key : routerAttributesToEditView[key];

                editViewParameters[formOptionKey] = attributes[attributeName];
            });
        }

        return this.resourceFormStore.save(saveOptions)
            .then((response) => {
                this.showSuccessSnackbar();
                this.clearErrors();

                if (editView) {
                    router.navigate(
                        editView,
                        {
                            id: resourceStore.id,
                            locale: resourceStore.locale,
                            ...editViewParameters,
                        }
                    );
                }

                return response;
            })
            .catch(action((error) => {
                if (error.code === HAS_CHANGED_ERROR_CODE) {
                    this.showHasChangedWarning = true;
                    this.postponedSaveOptions = options;

                    return;
                }

                this.errors.push(error.detail || error.title || translate('sulu_admin.form_save_server_error'));
            }));
    };

    navigateBack = () => {
        const {router} = this.props;
        const {
            attributes,
            route: {
                options: {
                    backView,
                    routerAttributesToBackView,
                },
            },
        } = router;

        if (!backView) {
            return;
        }

        const backViewParameters = {};

        if (routerAttributesToBackView) {
            Object.keys(toJS(routerAttributesToBackView)).forEach((key) => {
                const formOptionKey = routerAttributesToBackView[key];
                const attributeName = isNaN(key) ? key : routerAttributesToBackView[key];

                backViewParameters[formOptionKey] = attributes[attributeName];
            });
        }

        if (this.resourceStore.locale) {
            backViewParameters.locale = this.resourceStore.locale.get();
        }

        router.restore(backView, backViewParameters);
    };

    handleError = () => {
        this.errors.push(translate('sulu_admin.form_contains_invalid_values'));
    };

    @action clearErrors = () => {
        this.errors.splice(0, this.errors.length);
    };

    handleMissingTypeCancel = () => {
        this.navigateBack();
    };

    @action handleDirtyWarningCancelClick = () => {
        this.showDirtyWarning = false;
        this.postponedUpdateRouteMethod = undefined;
        this.postponedRoute = undefined;
        this.postponedRouteAttributes = undefined;
    };

    @action handleDirtyWarningConfirmClick = () => {
        if (!this.postponedUpdateRouteMethod || !this.postponedRoute || !this.postponedRouteAttributes) {
            throw new Error('Some routing information is missing. This should not happen and is likely a bug.');
        }

        this.postponedUpdateRouteMethod(this.postponedRoute.name, this.postponedRouteAttributes);
        this.postponedUpdateRouteMethod = undefined;
        this.postponedRoute = undefined;
        this.postponedRouteAttributes = undefined;
        this.showDirtyWarning = false;
    };

    @action handleHasChangedWarningCancelClick = () => {
        this.showHasChangedWarning = false;
        this.postponedSaveOptions = undefined;
    };

    @action handleHasChangedWarningConfirmClick = () => {
        this.save({...this.postponedSaveOptions, force: true});
        this.showHasChangedWarning = false;
        this.postponedSaveOptions = undefined;
    };

    setFormRef = (form: ?ElementRef<typeof FormContainer>) => {
        this.form = form;
    };

    render() {
        const {
            route: {
                options: {
                    titleVisible = false,
                },
            },
            router,
            title,
        } = this.props;

        return (
            <div className={formStyles.form}>
                {titleVisible && title && <h1>{title}</h1>}
                <FormContainer
                    onError={this.handleError}
                    onMissingTypeCancel={this.handleMissingTypeCancel}
                    onSubmit={this.handleSubmit}
                    onSuccess={this.handleSuccess}
                    ref={this.setFormRef}
                    router={router}
                    store={this.resourceFormStore}
                />
                {this.toolbarActions.map((toolbarAction, index) => toolbarAction.getNode(index))}
                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('sulu_admin.confirm')}
                    onCancel={this.handleDirtyWarningCancelClick}
                    onConfirm={this.handleDirtyWarningConfirmClick}
                    open={this.showDirtyWarning}
                    title={translate('sulu_admin.dirty_warning_dialog_title')}
                >
                    {translate('sulu_admin.dirty_warning_dialog_text')}
                </Dialog>
                <Dialog
                    cancelText={translate('sulu_admin.cancel')}
                    confirmText={translate('sulu_admin.confirm')}
                    onCancel={this.handleHasChangedWarningCancelClick}
                    onConfirm={this.handleHasChangedWarningConfirmClick}
                    open={this.showHasChangedWarning}
                    title={translate('sulu_admin.has_changed_warning_dialog_title')}
                >
                    {translate('sulu_admin.has_changed_warning_dialog_text')}
                </Dialog>
            </div>
        );
    }
}

export default withToolbar(Form, function() {
    const {router} = this.props;
    const {
        route: {
            options: {
                backView,
            },
        },
    } = router;
    const {errors, resourceStore, showSuccess} = this;

    const backButton = backView
        ? {
            onClick: this.navigateBack,
        }
        : undefined;
    const locale = this.locales
        ? {
            value: resourceStore.locale.get(),
            onChange: (locale) => {
                router.navigate(router.route.name, {...router.attributes, locale});
            },
            options: this.locales.map((locale) => ({
                value: locale,
                label: locale,
            })),
        }
        : undefined;

    const items = this.toolbarActions
        .map((toolbarAction) => toolbarAction.getToolbarItemConfig())
        .filter((item) => item != null);

    const icons = [];
    const formData = this.resourceFormStore.data;

    if (formData.hasOwnProperty('publishedState') || formData.hasOwnProperty('published')) {
        const {publishedState, published} = formData;
        icons.push(
            <PublishIndicator
                draft={publishedState === undefined ? false : !publishedState}
                key="publish"
                published={published === undefined ? false : !!published}
            />
        );
    }

    const warnings = [];
    if (this.collaborationStore && this.collaborationStore.collaborations.length > 0) {
        warnings.push([
            translate('sulu_admin.form_used_by'),
            this.collaborationStore.collaborations.map((collaboration) => collaboration.fullName).join(', '),
        ].join(' '));
    }

    return {
        backButton,
        errors,
        locale,
        items,
        icons,
        showSuccess,
        warnings,
    };
});
