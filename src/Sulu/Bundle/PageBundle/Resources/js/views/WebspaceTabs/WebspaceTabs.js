// @flow
import React from 'react';
import {action, computed, intercept, observable} from 'mobx';
import type {IObservableValue} from 'mobx/lib/mobx';
import {observer} from 'mobx-react';
import type {ViewProps} from 'sulu-admin-bundle/containers';
import type {AttributeMap} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import {Tabs} from 'sulu-admin-bundle/views';
import {Route} from 'sulu-admin-bundle/services';
import WebspaceSelect from '../../components/WebspaceSelect';
import webspaceStore from '../../stores/webspaceStore';
import type {Webspace} from '../../stores/webspaceStore/types';
import webspaceTabsStyles from './webspaceTabs.scss';

const USER_SETTING_PREFIX = 'sulu_page.webspace_tabs';
const USER_SETTING_WEBSPACE = [USER_SETTING_PREFIX, 'webspace'].join('.');

@observer
class WebspaceTabs extends React.Component<ViewProps> {
    webspaceKey: IObservableValue<string> = observable.box();
    webspaceDisposer: () => void;
    bindWebspaceToRouterDisposer: () => void;

    static getDerivedRouteAttributes(route: Route, attributes: AttributeMap) {
        const webspace = attributes.webspace
            ? attributes.webspace
            : userStore.getPersistentSetting(USER_SETTING_WEBSPACE);

        return {webspace};
    }

    @computed get webspace() {
        return webspaceStore.getWebspace(this.webspaceKey.get());
    }

    constructor(props: ViewProps) {
        super(props);

        const {router} = this.props;

        this.bindWebspaceToRouter();

        this.webspaceDisposer = intercept(this.webspaceKey, '', (change) => {
            if (!change.newValue) {
                return change;
            }

            userStore.setPersistentSetting(USER_SETTING_WEBSPACE, change.newValue);
            return change;
        });

        this.bindWebspaceToRouterDisposer = router.addUpdateRouteHook(this.bindWebspaceToRouter);
    }

    componentWillUnmount() {
        this.bindWebspaceToRouterDisposer();
        this.webspaceDisposer();
    }

    bindWebspaceToRouter = () => {
        const {router} = this.props;
        router.bind('webspace', this.webspaceKey);

        return true;
    };

    @action handleWebspaceChange = (value: string) => {
        this.webspaceKey.set(value);
    };

    render() {
        return (
            <Tabs
                {...this.props}
                childrenProps={{webspace: this.webspace, webspaceKey: this.webspaceKey}}
                header={
                    <div className={webspaceTabsStyles.webspaceSelect}>
                        <WebspaceSelect onChange={this.handleWebspaceChange} value={this.webspaceKey.get()}>
                            {webspaceStore.grantedWebspaces.map((webspace: Webspace) => (
                                <WebspaceSelect.Item key={webspace.key} value={webspace.key}>
                                    {webspace.name}
                                </WebspaceSelect.Item>
                            ))}
                        </WebspaceSelect>
                    </div>
                }
            />
        );
    }
}

export default WebspaceTabs;
