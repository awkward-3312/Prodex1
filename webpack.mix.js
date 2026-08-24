const mix = require('laravel-mix');
const MomentLocalesPlugin = require('moment-locales-webpack-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const tailwindcss = require('tailwindcss');
const autoprefixer = require('autoprefixer');

mix.js('resources/src/main.js', 'public')
    .js('resources/src/login.js', 'public')
    .js('resources/src/portal.js', 'public')
    .js('resources/src/customer-display.js', 'public')
    .js('resources/src/storefront.js', 'public')
    .postCss('resources/css/storefront.css', 'public/css', [
        tailwindcss('./tailwind.config.js'),
        autoprefixer(),
    ])
    .copy('resources/static/prodex-sidebar2-organizer.js', 'public/js/prodex-sidebar2-organizer.js')
    .copy('resources/static/prodex-navigation-v3.js', 'public/js/prodex-navigation-v3.js')
    .copy('resources/static/prodex-navigation-stability.js', 'public/js/prodex-navigation-stability.js')
    .copy('resources/static/prodex-sidebar-reopen.js', 'public/js/prodex-sidebar-reopen.js')
    .copy('resources/static/prodex-organization-navigation.js', 'public/js/prodex-organization-navigation.js')
    .copy('resources/static/prodex-transfer-idempotency.js', 'public/js/prodex-transfer-idempotency.js')
    .copy('resources/static/prodex-transfer-logistics.js', 'public/js/prodex-transfer-logistics.js')
    .copy('resources/static/prodex-transfer-permission-ui.js', 'public/js/prodex-transfer-permission-ui.js')
    .copy('resources/static/prodex-transfer-issues.js', 'public/js/prodex-transfer-issues.js')
    .copy('resources/static/prodex-transfer-location-ui.js', 'public/js/prodex-transfer-location-ui.js')
    .copy('resources/static/prodex-transfer-workflow.js', 'public/js/prodex-transfer-workflow.js')
    .copy('resources/static/prodex-damage-location-ui.js', 'public/js/prodex-damage-location-ui.js')
    .copy('resources/static/prodex-inventory-visibility.js', 'public/js/prodex-inventory-visibility.js')
    .copy('resources/static/prodex-inventory-spa-navigation.js', 'public/js/prodex-inventory-spa-navigation.js')
    .copy('resources/static/prodex-pos-location-ui.js', 'public/js/prodex-pos-location-ui.js')
    .copy('resources/static/prodex-pos-location-catalog.js', 'public/js/prodex-pos-location-catalog.js')
    .copy('resources/static/prodex-pos-location-offline.js', 'public/js/prodex-pos-location-offline.js')
    .copy('resources/static/prodex-erp-integrity-ui.js', 'public/js/prodex-erp-integrity-ui.js')
    .vue()

mix.webpackConfig({
    resolve: { alias: { '@': __dirname + '/resources/src' } },
    stats: { children: true },
    output: {
        filename:'js/[name].min.js',
        chunkFilename: 'js/bundle/[name].[hash].js',
    },
    module: {
        rules: [{
            test: /\.scss$/,
            use: [{
                loader: 'sass-loader',
                options: {
                    sassOptions: {
                        quietDeps: true,
                        silenceDeprecations: ['legacy-js-api', 'import', 'global-builtin', 'color-functions', 'slash-div']
                    }
                }
            }]
        }]
    },
    plugins: [
        new MomentLocalesPlugin(),
        new CleanWebpackPlugin({ cleanOnceBeforeBuildPatterns: ['./js/*'] }),
    ],
});