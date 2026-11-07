# Vite for ExpressionEngine

Load [Vite](https://vite.dev) assets in ExpressionEngine templates.

## Installation

1. Copy `vite/` to `system/user/addons/vite/`.
2. Install it under Developer > Add-Ons.

## Setup

Install Vite and [laravel-vite-plugin](https://github.com/laravel/vite-plugin):

```sh
npm install --save-dev vite laravel-vite-plugin
```

laravel-vite-plugin doesn't need Laravel. This add-on uses it for:

- The build manifest (`public/build/manifest.json`)
- The `public/hot` file, which tells the add-on the dev server is running
- Dev server URLs and CORS
- Reloading the page when you save a template

```js
// vite.config.js
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            publicDirectory: 'public', // your web root
            refresh: ['system/user/templates/**'],
        }),
    ],
});
```

The add-on finds the web root from EE's `base_path` (or the `index.php` folder if that isn't set) and uses the path from `base_url` for subfolder installs.

Add `public/build/` and `public/hot` to `.gitignore`.

Set these in `.env` if you need them:

- `APP_URL`: your local site's URL. Required if it isn't on `localhost` or a `.test` domain, otherwise the browser blocks requests to the dev server.
- `ASSET_URL`: required if the site is in a subfolder (e.g. `/site`).

## Usage

```html
{!-- CSS, preloads, and scripts for one or more entry points --}
{exp:vite src="resources/css/app.css|resources/js/app.js"}

{!-- URL of a single built file --}
<img src="{exp:vite:asset src='resources/img/logo.svg'}" />
```

## Notes

- Each file prints once per request. EE parses the page before its layout, so if both load the same entry it ends up wherever the page's tag is. Keep shared entries in the layout.
- `{exp:vite:asset}` only finds files in the manifest. For images your CSS and JS don't reference, add an entry like this to `input`:

    ```js
    // resources/js/static.js
    export default import.meta.glob(['../img/**'], {
        eager: true,
        import: 'default',
        query: '?url',
    });
    ```

- A missing manifest or entry throws an error for Super Admins or in debug mode. Otherwise it's logged to the Developer Log and the tag outputs nothing.
- Scripts load as ES modules, so they're deferred.
- Not supported: CSP nonces, SRI, or React Refresh.

## License

MIT
