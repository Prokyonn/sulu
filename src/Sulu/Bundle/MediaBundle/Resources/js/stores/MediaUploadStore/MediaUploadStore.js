// @flow
import {action, computed, observable} from 'mobx';
import type {IObservableValue} from 'mobx/lib/mobx';
import {ResourceRequester, resourceRouteRegistry} from 'sulu-admin-bundle/services';
import type {Media} from '../../types';

const RESOURCE_KEY = 'media';
const PREVIEW_RESOURCE_KEY = 'media_preview';

const MEDIA_FORM_NAME = 'fileVersion';
const PREVIEW_MEDIA_FORM_NAME = 'previewImage';

export default class MediaUploadStore {
    @observable uploading: boolean;
    @observable progress: number;
    @observable media: ?Media;
    locale: IObservableValue<string>;

    constructor(media: ?Media, locale: IObservableValue<string>) {
        this.media = media;
        this.locale = locale;
    }

    @computed get id(): ?number | string {
        const {media} = this;

        if (!media) {
            return undefined;
        }

        return media.id;
    }

    @computed get downloadUrl(): ?string {
        const {media} = this;

        if (!media) {
            return undefined;
        }

        if (!media.adminUrl) {
            return media.url;
        }

        return media.adminUrl;
    }

    getThumbnail(size: string): ?string {
        const {media} = this;

        if (!media) {
            return;
        }

        const {
            thumbnails,
        } = media;

        if (!thumbnails || !thumbnails[size]) {
            return;
        }

        return thumbnails[size];
    }

    @computed get mimeType(): ?string {
        const {media} = this;

        if (!media) {
            return undefined;
        }

        return media.mimeType;
    }

    @action setUploading(uploading: boolean) {
        this.uploading = uploading;
    }

    @action setProgress(progress: number) {
        this.progress = Math.ceil(progress);
    }

    @action delete() {
        if (!this.id) {
            throw new Error('The "id" property must be available for deleting a media');
        }

        return ResourceRequester.delete(RESOURCE_KEY, {id: this.id})
            .then(action(() => {
                this.media = undefined;
            }));
    }

    update(file: File): Promise<*> {
        const id = this.media ? this.media.id : undefined;

        if (!id) {
            throw new Error('The "id" property must be available for updating a media');
        }

        const url = resourceRouteRegistry.getDetailUrl(
            RESOURCE_KEY,
            {
                action: 'new-version',
                id,
                locale: this.locale.get(),
            }
        );

        this.setUploading(true);

        return this.upload(file, url, MEDIA_FORM_NAME)
            .then(this.handleResponse);
    }

    create(collectionId: string | number, file: File): Promise<*> {
        const url = resourceRouteRegistry.getDetailUrl(
            RESOURCE_KEY,
            {
                collection: collectionId,
                locale: this.locale.get(),
            }
        );

        this.setUploading(true);

        return this.upload(file, url, MEDIA_FORM_NAME)
            .then(this.handleResponse);
    }

    updatePreviewImage(file: File): Promise<*> {
        const id = this.media ? this.media.id : undefined;

        if (!id) {
            throw new Error('The "id" property must be available for updating a media');
        }

        const url = resourceRouteRegistry.getDetailUrl(
            PREVIEW_RESOURCE_KEY,
            {
                id,
                locale: this.locale.get(),
            }
        );

        this.setUploading(true);

        return this.upload(file, url, PREVIEW_MEDIA_FORM_NAME)
            .then(this.handleResponse);
    }

    deletePreviewImage(): Promise<*> {
        if (!this.id) {
            throw new Error('The "id" property must be available for deleting a preview media');
        }

        return ResourceRequester.delete(PREVIEW_RESOURCE_KEY, {id: this.id})
            .then(action((media) => {
                Object.assign(this.media, media);
            }));
    }

    @action handleResponse = (media: Object) => {
        this.setUploading(false);
        this.setProgress(0);

        this.media = Object.assign(this.media || {}, media);

        return media;
    };

    upload(file: File, url: string, formName: string): Promise<*> {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const form = new FormData();

            xhr.open('POST', url);

            xhr.onload = (event: any) => {
                // mimic ok property of fetch response: https://developer.mozilla.org/en-US/docs/Web/API/Response/ok
                if (event.target.status >= 200 && event.target.status <= 299) {
                    resolve(JSON.parse(event.target.response));
                } else {
                    try {
                        reject(JSON.parse(event.target.response));
                    } catch (e) {
                        reject(event.target);
                    }
                }
            };
            xhr.onerror = (event: any) => reject(event.target);

            if (xhr.upload) {
                xhr.upload.onprogress = (event) => this.setProgress(event.loaded / event.total * 100);
            }

            form.append(formName, file);
            xhr.send(form);
        });
    }
}
