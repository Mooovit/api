const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.js('resources/js/app.js', 'public/js').vue()
    .postCss('resources/css/app.css', 'public/css', [
        require('postcss-import'),
        require('tailwindcss'),
    ])
    .webpackConfig(require('./webpack.config'));

/* Disable the desktop-notification plugin (webpack-notifier → node-notifier):
   on Apple Silicon it spawns the bundled x86_64-only terminal-notifier binary,
   which dies with "spawn Unknown system error -86" (EBADARCH) AFTER a
   successful compile — and the non-zero exit makes `make build` fail the
   whole deploy. Notifications are worthless in CI/deploy anyway. */
mix.disableNotifications();

if (mix.inProduction()) {
    mix.version();
}
