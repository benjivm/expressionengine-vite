<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Loads Vite builds in templates.
 *
 *   {exp:vite src="resources/css/app.css|resources/js/app.js"}
 *       Tags for one or more entry points: their CSS, preloads for their shared chunks, and
 *       the module script. Each file is printed once per request, so the layout and the page
 *       can both ask for the same thing.
 *
 *   {exp:vite:asset src="resources/img/logo.svg"}
 *       URL of a single built file, e.g. an image picked up by resources/js/static.js.
 *
 * While `npm run dev` is running, the `hot` file in the web root holds the dev server URL and
 * everything is served from there instead of the build directory.
 *
 * Missing files throw for anyone EE shows error details to (debug mode or Super Admins),
 * otherwise they're logged to the Developer Log and the tag prints nothing.
 */
class Vite
{
    private const BUILD_DIR = 'build';

    public $return_data = '';

    /**
     * Manifest for this request.
     */
    private static ?array $manifest = null;

    /**
     * Dev server URL for this request, or '' when it isn't running.
     */
    private static ?string $hot = null;

    /**
     * Files already printed this request.
     */
    private static array $rendered = [];

    /**
     * EE runs the constructor for every tag, so only render for the bare {exp:vite}.
     */
    public function __construct()
    {
        if (count(ee()->TMPL->tagparts) === 1) {
            $this->return_data = $this->safely(
                fn () => $this->tags(explode('|', (string) ee()->TMPL->fetch_param('src')))
            );
        }
    }

    public function asset(): string
    {
        return $this->safely(function () {
            $src = $this->normalize((string) ee()->TMPL->fetch_param('src'));

            if ($hot = $this->hotUrl()) {
                return "{$hot}/{$src}";
            }

            return $this->buildUrl($this->chunk($src)['file']);
        });
    }

    /**
     * Throws for anyone EE would show error details to, same check as EE's Exceptions class,
     * otherwise logs and prints nothing so a bad tag doesn't take the page down.
     */
    private function safely(callable $render): string
    {
        try {
            return $render();
        } catch (Exception $e) {
            if ($this->showErrors()) {
                throw $e;
            }

            ee()->logger->developer('Vite: '.$e->getMessage());
            ee()->TMPL->log_item('Vite: '.$e->getMessage());

            return '';
        }
    }

    private function showErrors(): bool
    {
        return (defined('DEBUG') && DEBUG)
            || ee()->config->item('debug') > 1
            || (isset(ee()->session) && ee('Permission')->isSuperAdmin());
    }

    private function tags(array $entries): string
    {
        $entries = array_filter(array_map([$this, 'normalize'], $entries));

        if ($hot = $this->hotUrl()) {
            return $this->hotTags($hot, $entries);
        }

        $styles = $preloads = $scripts = [];

        foreach ($entries as $entry) {
            $chunk = $this->chunk($entry);

            foreach ($this->imports($chunk) as $import) {
                $preloads[] = $import['file'];
                array_push($styles, ...($import['css'] ?? []));
            }

            array_push($styles, ...($chunk['css'] ?? []));

            if ($this->isCss($chunk['file'])) {
                $styles[] = $chunk['file'];
            } else {
                $scripts[] = $chunk['file'];
            }
        }

        $html = '';

        foreach ($this->unrendered($styles) as $file) {
            $html .= $this->styleTag($this->buildUrl($file));
        }

        foreach ($this->unrendered($preloads) as $file) {
            $html .= '<link rel="modulepreload" href="'.htmlspecialchars($this->buildUrl($file)).'" />'."\n";
        }

        foreach ($this->unrendered($scripts) as $file) {
            $html .= $this->scriptTag($this->buildUrl($file));
        }

        return $html;
    }

    private function hotTags(string $hot, array $entries): string
    {
        $html = '';

        foreach ($this->unrendered(['@vite/client', ...$entries]) as $entry) {
            $html .= $this->isCss($entry)
                ? $this->styleTag("{$hot}/{$entry}")
                : $this->scriptTag("{$hot}/{$entry}");
        }

        return $html;
    }

    /**
     * Every chunk a chunk statically imports, depth first.
     */
    private function imports(array $chunk, array &$seen = []): array
    {
        $imports = [];

        foreach ($chunk['imports'] ?? [] as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $import = $this->chunk($key);

            array_push($imports, ...$this->imports($import, $seen));
            $imports[] = $import;
        }

        return $imports;
    }

    private function chunk(string $src): array
    {
        $manifest = $this->manifest();

        if (! isset($manifest[$src])) {
            throw new Exception("Unable to locate file in Vite manifest: {$src}.");
        }

        return $manifest[$src];
    }

    private function manifest(): array
    {
        if (self::$manifest === null) {
            $path = $this->publicPath(self::BUILD_DIR.'/manifest.json');

            if (! is_file($path)) {
                throw new Exception("The Vite manifest does not exist: {$path}. Run `npm run build`.");
            }

            self::$manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$manifest;
    }

    private function hotUrl(): ?string
    {
        if (self::$hot === null) {
            $path = $this->publicPath('hot');
            self::$hot = is_file($path) ? rtrim(trim(file_get_contents($path)), '/') : '';
        }

        return self::$hot ?: null;
    }

    /**
     * The files not printed yet this request, marking them as printed.
     */
    private function unrendered(array $files): array
    {
        $files = array_values(array_diff(array_unique($files), array_keys(self::$rendered)));

        foreach ($files as $file) {
            self::$rendered[$file] = true;
        }

        return $files;
    }

    private function scriptTag(string $url): string
    {
        return '<script type="module" src="'.htmlspecialchars($url).'"></script>'."\n";
    }

    private function styleTag(string $url): string
    {
        return '<link rel="stylesheet" href="'.htmlspecialchars($url).'" />'."\n";
    }

    private function isCss(string $path): bool
    {
        return (bool) preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path);
    }

    private function normalize(string $path): string
    {
        return ltrim(trim($path), '/');
    }

    private function buildUrl(string $file): string
    {
        // Only the path, so subfolder installs work and URLs stay root-relative
        $base = rtrim((string) parse_url((string) ee()->config->item('base_url'), PHP_URL_PATH), '/');

        return $base.'/'.self::BUILD_DIR.'/'.$file;
    }

    private function publicPath(string $path): string
    {
        $base = ee()->config->item('base_path') ?: FCPATH;

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
