// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import {translate} from '../../utils/Translator';
import Icon from '../Icon';
import snackbarStyles from './snackbar.scss';

export type SnackbarType = 'error' | 'warning' | 'info' | 'success';

type Props = {|
    actionLabel?: string,
    icon?: string,
    message: string,
    onActionClick?: () => void,
    onClick?: () => void,
    onCloseClick?: () => void,
    skin: 'static' | 'floating',
    type: SnackbarType,
    visible: boolean,
|};

const ICONS = {
    error: 'su-exclamation-triangle',
    warning: 'su-bell',
    info: 'su-exclamation-circle',
    success: 'su-check-circle',
};

const DEFAULT_SNACKBAR_TYPE: SnackbarType = 'error';

@observer
class Snackbar extends React.Component<Props> {
    static defaultProps = {
        skin: 'static',
        visible: true,
    };

    @observable message: ?string;
    @observable type: SnackbarType = DEFAULT_SNACKBAR_TYPE;

    @action updateMessage = () => {
        this.message = this.props.message;
    };

    @action updateType = () => {
        this.type = this.props.type;
    };

    componentDidMount() {
        this.updateMessage();
        this.updateType();
    }

    componentDidUpdate(prevProps: Props) {
        const {message, type, visible} = this.props;

        if (!visible) {
            return;
        }

        if (prevProps.visible !== visible || prevProps.message !== message) {
            this.updateMessage();
        }

        if (prevProps.visible !== visible || prevProps.type !== type) {
            this.updateType();
        }
    }

    @action handleTransitionEnd = () => {
        const {visible} = this.props;

        if (!visible) {
            this.message = undefined;
            this.type = DEFAULT_SNACKBAR_TYPE;
        }
    };

    handleActionClick = (event: SyntheticMouseEvent<HTMLButtonElement>) => {
        // Prevent the surrounding snackbar's `onClick` from firing when the action button is used.
        event.stopPropagation();

        const {onActionClick} = this.props;
        if (onActionClick) {
            onActionClick();
        }
    };

    render() {
        const {actionLabel, icon, onActionClick, onCloseClick, onClick, skin, visible} = this.props;

        const snackbarClass = classNames(
            snackbarStyles.snackbar,
            snackbarStyles[this.type],
            {
                [snackbarStyles.clickable]: onClick,
                [snackbarStyles.floating]: skin === 'floating',
                [snackbarStyles.visible]: visible,
            }
        );

        return (
            <div className={snackbarClass} onClick={onClick} onTransitionEnd={this.handleTransitionEnd} role="button">
                <Icon className={snackbarStyles.icon} name={icon || ICONS[this.type]} />
                <div className={snackbarStyles.text}>
                    {
                        skin === 'static'
                            ? <>
                                <strong>{translate('sulu_admin.' + this.type)}</strong>{' - '}
                            </>
                            : null
                    }
                    {this.message}
                </div>
                {actionLabel && onActionClick &&
                    <button
                        className={snackbarStyles.actionButton}
                        onClick={this.handleActionClick}
                        type="button"
                    >
                        {actionLabel}
                    </button>
                }
                {onCloseClick &&
                    <Icon className={snackbarStyles.closeIcon} name="su-times" onClick={onCloseClick} />
                }
            </div>
        );
    }
}

export default Snackbar;
