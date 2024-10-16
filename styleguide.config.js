/* eslint-disable flowtype/require-valid-file-annotation */
/* eslint-disable import/no-nodejs-modules */
const path = require('path');
const fs = require('fs');
const webpack = require('webpack');
const glob = require('glob');
const {styles} = require('@ckeditor/ckeditor5-dev-utils'); // eslint-disable-line import/no-extraneous-dependencies

const firstLetterIsUppercase = (string) => {
    const first = string.charAt(0);
    return first === first.toUpperCase();
};

const compareFolderName = (folderA, folderB) => {
    folderA = path.basename(folderA).toUpperCase();
    folderB = path.basename(folderB).toUpperCase();

    if (folderA < folderB) {
        return -1;
    }

    if (folderA > folderB) {
        return 1;
    }

    return 0;
};

const javaScriptFileExists = (path, fileName) => {
    return fs.existsSync(`${path}/${fileName}.js`);
};

module.exports = { // eslint-disable-line
    title: 'Sulu Javascript Docs',
    require: [
        'regenerator-runtime/runtime',
        './src/Sulu/Bundle/AdminBundle/Resources/js/containers/Application/global.scss',
        './src/Sulu/Bundle/AdminBundle/Resources/js/containers/Application/styleguidist.scss',
    ],
    styles: {
        Playground: {
            preview: {
                background: '#f5f5f5',
            },
        },
    },
    sections: [
        {
            name: 'Views',
            sections: (function() {
                const folders = glob.sync('./src/Sulu/Bundle/*/Resources/js/views/*');

                return folders
                    .filter((folder) => path.basename(folder) !== 'index.js')
                    .filter((folder) => javaScriptFileExists(folder, path.basename(folder)))
                    .map((folder) => {
                        const component = path.basename(folder);
                        return {name: component, content: folder + '/README.md'};
                    });
            })(),
        },
        {
            name: 'Services',
            sections: (function() {
                const folders = glob.sync('./src/Sulu/Bundle/*/Resources/js/services/*');

                return folders
                    .filter((folder) => path.basename(folder) !== 'index.js')
                    .filter((folder) => javaScriptFileExists(folder, path.basename(folder)))
                    .sort(compareFolderName)
                    .map((folder) => {
                        const component = path.basename(folder);

                        return {name: component, content: folder + '/README.md'};
                    });
            })(),
        },
        {
            name: 'Containers',
            components() {
                let folders = glob.sync('./src/Sulu/Bundle/*/Resources/js/containers/*');
                // filter out containers
                folders = folders
                    .filter((folder) => firstLetterIsUppercase(path.basename(folder)))
                    .filter((folder) => javaScriptFileExists(folder, path.basename(folder)))
                    .sort(compareFolderName);

                return folders.map((folder) => {
                    const component = path.basename(folder);

                    return path.join(folder, component + '.js');
                });
            },
        },
        {
            name: 'Higher-Order components',
            sections: (function() {
                let folders = glob.sync('./src/Sulu/Bundle/*/Resources/js/components/*');
                folders = folders.filter((folder) => !firstLetterIsUppercase(path.basename(folder)));

                return folders
                    .filter((folder) => path.basename(folder) !== 'index.js')
                    .sort(compareFolderName)
                    .map((folder) => {
                        const component = path.basename(folder);

                        return {name: component, content: folder + '/README.md'};
                    });
            })(),
        },
        {
            name: 'Components',
            components() {
                let folders = glob.sync('./src/Sulu/Bundle/*/Resources/js/components/*');
                // filter out higher order components
                folders = folders
                    .filter((folder) => firstLetterIsUppercase(path.basename(folder)))
                    .filter((folder) => javaScriptFileExists(folder, path.basename(folder)))
                    .sort(compareFolderName);

                return folders.map((folder) => {
                    const component = path.basename(folder);

                    return path.join(folder, component + '.js');
                });
            },
        },
    ],
    webpackConfig: {
        devServer: {
            disableHostCheck: true,
        },
        devtool: 'eval-source-map',
        plugins: [
            new webpack.DefinePlugin({
                SULU_CONFIG: {},
            }),
        ],
        resolve: {
            alias: {
                // eslint-disable-next-line no-undef
                'fos-jsrouting/router': path.resolve(__dirname, 'tests/js/mocks/empty.js'),
            },
        },
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules\/(?!(sulu-(.*)-bundle|@ckeditor|lodash-es)\/)/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            cacheDirectory: true,
                            cacheCompression: false,
                        },
                    },
                },
                {
                    test: /\.css/,
                    exclude: /ckeditor5-[^/]+\/theme\/[\w-/]+\.css$/,
                    use: [
                        'style-loader',
                        {
                            loader: 'css-loader',
                            options: {
                                modules: false,
                            },
                        },
                    ],
                },
                {
                    test: /ckeditor5-[^/]+\/theme\/[\w-/]+\.css$/,
                    use: [
                        {
                            loader: 'style-loader',
                            options: {
                                injectType: 'singletonStyleTag',
                            },
                        },
                        {
                            loader: 'postcss-loader',
                            options: styles.getPostCssConfig({
                                themeImporter: {
                                    themePath: require.resolve('@ckeditor/ckeditor5-theme-lark'),
                                },
                            }),
                        },
                    ],
                },
                {
                    test: /\.(scss)$/,
                    use: [
                        'style-loader',
                        {
                            loader: 'css-loader',
                            options: {
                                importLoaders: 1,
                                modules: {
                                    localIdentName: '[local]--[hash:base64:10]',
                                    exportLocalsConvention: 'camelCase',
                                },
                            },
                        },
                        'postcss-loader',
                    ],
                },
                {
                    test: /ckeditor5-[^/]+\/theme\/icons\/[^/]+\.svg$/,
                    use: 'raw-loader',
                },
                {
                    test: /\.(jpg|gif|png)(\?.*$|$)/,
                    use: [
                        {
                            loader: 'file-loader',
                        },
                    ],
                },
                {
                    test: /\.(svg|ttf|woff|woff2|eot)(\?.*$|$)/,
                    exclude: /ckeditor5-[^/]+\/theme\/icons\/[^/]+\.svg$/,
                    use: [
                        {
                            loader: 'file-loader',
                        },
                    ],
                },
            ],
        },
    },
};
