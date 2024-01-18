<?php /** @noinspection PhpUnused */

namespace Flying\Wordpress;

use _WP_Dependency;
use Flying\Wordpress\Queue\AssetQueue;
use Symfony\Component\Filesystem\Path;

class ClientAssetsManager
{
    private const DEFERRED_HEAD_CONTENTS_TAG = '<!-- Deferred HEAD client assets -->';
    private const DEFERRED_FOOTER_CONTENTS_TAG = '<!-- Deferred FOOTER client assets -->';
    private const COMBINED_SCRIPT_ID = 'client-assets-manager-combined-script';

    private static self $instance;
    private bool $jqueryIncluded = false;
    private AssetQueue $head;
    private AssetQueue $footer;
    private AssetQueue $styles;
    private array $scripts = [];
    private bool $optimizeAssets = false;
    private bool $assetsApplied = false;
    private array $renderScripts = [];
    private string $cacheDir;

    public static function getInstance(?string $cacheDir = null): self
    {
        if (!self::$instance) {
            self::$instance = new self($cacheDir);
        }
        return self::$instance;
    }

    protected function __construct(?string $cacheDir = null)
    {
        $this->head = new AssetQueue();
        $this->footer = new AssetQueue();
        $this->styles = new AssetQueue();
        $this->cacheDir = $cacheDir ?? (str_replace('\\', '/', WP_CONTENT_DIR) . '/assets-cache');
        $this->init();
    }

    private function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        // Force scripts to be loaded from footer
        remove_action('wp_head', 'wp_print_scripts');
        remove_action('wp_head', 'wp_print_head_scripts', 9);
        remove_action('wp_head', 'wp_enqueue_scripts', 1);
        add_action('wp_footer', 'wp_enqueue_scripts', 10);
        add_action('wp_footer', 'wp_print_scripts', 900);
        add_action('wp_enqueue_scripts', function () {
            foreach ($this->scripts as $script) {
                wp_enqueue_script(...$script);
            }
        });

        // Render HEAD assets
        add_action('wp_head', function () {
            // Mark a place to put assets into the HEAD section of the page
            echo self::DEFERRED_HEAD_CONTENTS_TAG;
            $this->initJavaScriptOptimization();
        }, PHP_INT_MIN);

        // Render footer contents
        add_action('wp_footer', static function () {
            // Mark a place to put assets into footer of the page
            echo self::DEFERRED_FOOTER_CONTENTS_TAG;
        }, PHP_INT_MIN);

        // Remove rendering of styles into footer
        // by replacing contents of _wp_footer_scripts() in a way
        // to remove call to print_late_styles()
        // as it will be replaced by own styles rendering
        remove_action('wp_print_footer_scripts', '_wp_footer_scripts');
        add_action('wp_print_footer_scripts', 'print_footer_scripts');
        add_action('wp_print_footer_scripts', function () {
            ob_start();
            print_late_styles();
            $this->addCode(ob_get_clean(), false, 10);
        });

        add_filter('wp_redirect_status', function ($status, $location) {
            // We're in the process of redirect, we have to disable manager in this case
            if ($location) {
                $this->assetsApplied = true;
            }
            return $status;
        }, 10, 2);

        // Capture output, so we will be able to apply assets to it later
        // @see applyAssets()
        ob_start();
    }

    /**
     * Render collected client assets and apply them to given (or available in buffer) HTML
     */
    public function applyAssets(?string $html = null): string
    {
        if ($html === null && ob_get_level() > 0) {
            $html = ob_get_clean();
        }
        if ($this->assetsApplied || !$this->isEnabled()) {
            return $html;
        }
        $this->renderCombinedStylesheet();
        // By this time the page is completely rendered, but we still need to put assets referenced into previously marked places
        $html = str_replace(
            [self::DEFERRED_HEAD_CONTENTS_TAG, self::DEFERRED_FOOTER_CONTENTS_TAG],
            [$this->merge($this->head), $this->merge($this->footer)],
            $html
        );
        $this->assetsApplied = true;
        return $html;
    }

    private function initJavaScriptOptimization(): void
    {
        $filterOutJQuery = function ($scripts) {
            if ($this->jqueryIncluded) {
                // Filter out jQuery libraries that are replaced with jQuery loaded from CDN
                $scripts = array_filter($scripts, static function ($handle) {
                    return is_admin() || !in_array($handle, ['jquery', 'jquery-core', 'jquery-migrate'], true);
                });
            }
            return $scripts;
        };
        if (!$this->optimizeAssets) {
            // Collect information about all scripts, used on the page
            add_filter('print_scripts_array', $filterOutJQuery);
            return;
        }
        // Collect information about all scripts, used on the page
        add_filter('print_scripts_array', function ($scripts) use ($filterOutJQuery) {
            $scripts = $filterOutJQuery($scripts);
            $wpScripts = wp_scripts();
            $scripts = array_filter($scripts, function ($script) use ($wpScripts) {
                // We should replace all references to scripts that are loaded from local urls
                // with the combined script that will be rendered at later stage of page generation
                if ($script === self::COMBINED_SCRIPT_ID) {
                    return true;
                }
                if (!array_key_exists($script, $wpScripts->registered)) {
                    return true;
                }
                /** @var _WP_Dependency $dependency */
                $dependency = $wpScripts->registered[$script];
                if (str_contains($dependency->src, '://') && !str_contains($dependency->src, $wpScripts->base_url . '/')) {
                    return true;
                }
                if (!in_array($script, $this->renderScripts, true)) {
                    $wpScripts->dequeue($script);
                    $this->renderScripts[] = $script;
                }
                return false;
            });
            if (!in_array(self::COMBINED_SCRIPT_ID, $scripts, true)) {
                $scripts[] = self::COMBINED_SCRIPT_ID;
            }
            return $scripts;
        });
        // Render proper script source code
        add_filter('script_loader_src', function ($src, $handle) {
            if ($handle !== self::COMBINED_SCRIPT_ID) {
                // This is not a reference to the combined script, so leave it as it is
                return $src;
            }
            $wpScripts = wp_scripts();
            $basePath = rtrim(str_replace('\\', '/', ABSPATH), '/');
            $hash = [];
            $parts = [];
            // Collect information about scripts that should be included in the combined script
            foreach ($this->renderScripts as $script) {
                /** @var _WP_Dependency $dependency */
                $dependency = $wpScripts->registered[$script];
                $path = $dependency->src;
                if ($path === false) {
                    continue;
                }
                if (str_contains($path, '://')) {
                    $path = str_replace($wpScripts->base_url, $basePath, $path);
                    if (str_contains($path, '?')) {
                        $path = explode('?', $path, 2)[0];
                    }
                } else {
                    $path = $basePath . '/' . ltrim($path, '/');
                }
                if (is_file($path)) {
                    $parts[] = $path;
                    $hash[] = implode('|', [$script, filesize($path), filemtime($path)]);
                } else {
                    trigger_error('Client Assets Manager: Missed local file for JavaScript dependency "' . $script . '": ' . $path, E_USER_WARNING);
                }
            }
            $cachePath = $this->cacheDir . '/' . sha1(implode('|', $hash)) . '.js';
            if (!is_file($cachePath)) {
                // Combined script is not yet available - generate it and put into cache
                $content = [];
                foreach ($parts as $part) {
                    $content[] = '/* [' . basename($part) . '] */;';
                    $content[] = file_get_contents($part);
                }
                file_put_contents($cachePath, implode("\n", $content));
            }
            // Return url of the resulted combined script
            return str_replace($basePath, $wpScripts->base_url, $cachePath);

        }, 10, 2);
        // Initialize script dependency for "all scripts" to let it be rendered
        wp_scripts()->add(self::COMBINED_SCRIPT_ID, self::COMBINED_SCRIPT_ID . '.js');
        // Mark it with conditional comment, so it will be possible to find it later
        wp_scripts()->add_data(self::COMBINED_SCRIPT_ID, 'conditional', self::COMBINED_SCRIPT_ID);
        // Print additional scripts, registered for optimized versions of the scripts
        add_action('wp_footer', static function () {
            // Capture output because we need to have merged scripts to be loaded after scripts localization
            ob_start();
        }, 1);
        add_action('wp_footer', function () {
            $scripts = wp_scripts();
            // Render all additional scripts that may be attached to scripts
            // and need to appear before merged scripts
            foreach ($this->renderScripts as $script) {
                $scripts->print_inline_script($script, 'before');
                $scripts->print_extra_script($script);
            }
            $html = ob_get_clean();
            // Move our combined script at the end of the script area to make sure that all additional scripts will stay above it
            /** @noinspection NotOptimalRegularExpressionsInspection */
            $parts = preg_split(
                '/' . preg_quote('<!--[if ' . self::COMBINED_SCRIPT_ID . ']>', '/') . '(.+?)' . preg_quote('<![endif]-->', '/') . '/s',
                $html,
                -1,
                PREG_SPLIT_DELIM_CAPTURE,
            );
            $before = array_shift($parts);
            $script = array_shift($parts);
            $after = array_shift($parts);
            $this->footer->insert(implode("\n", [$before, $after, $script]), 10);
            ob_start();
            // Render all additional scripts that may be attached to scripts
            // and need to appear after merged scripts
            foreach ($this->renderScripts as $script) {
                $scripts->print_inline_script($script);
            }
            $this->footer->insert((string)ob_get_clean(), 5);
        }, 999);
    }

    private function renderCombinedStylesheet(): void
    {
        if (!$this->optimizeAssets) {
            return;
        }
        // Generate URL of combined stylesheets from all collected CSS files
        $paths = [];
        $hash = [];
        $stylesheets = new AssetQueue();
        foreach ($this->styles as $item) {
            if ($item['external']) {
                // This is an external stylesheet, we need to keep it as it is
                $stylesheets->insert($item, $item['priority']);
            } else {
                // This is local stylesheet, it should be merged
                $path = $item['url'];
                if (is_file($path)) {
                    $paths[] = $path;
                    $hash[] = $path;
                    $hash[] = filesize($path);
                    $hash[] = filemtime($path);
                }
            }
        }
        $basePath = str_replace('\\', '/', ABSPATH);
        $cachePath = $this->cacheDir . '/' . sha1(implode('|', $hash)) . '.css';
        if (!is_file($cachePath)) {
            // Stylesheet is not yet cached - create it and put in cache
            $content = [];
            foreach ($paths as $path) {
                $pp = $path;
                if (!str_contains($path, '://')) {
                    $pp = str_replace($basePath, '', $path);
                    if (!is_file($path)) {
                        $path = null;
                        trigger_error('Client Assets Manager: Missed included stylesheet file:' . $pp, E_USER_WARNING);
                    }
                }
                $content[] = '/* [' . basename($pp) . '] */';
                if ($path) {
                    $css = file_get_contents($path);
                    $base = Path::makeRelative(dirname($path), dirname($cachePath));
                    $css = preg_replace_callback('/url\(([\'\"]?)(.+?)(\1)\)/is', static function ($data) use ($base) {
                        $url = $data[2];
                        if (!preg_match('/^(data|https?):/i', $url)) {
                            $url = Path::join($base, $url);
                        }
                        return sprintf('url(%s%s%s)', $data[1], $url, $data[3]);
                    }, $css);
                    $content[] = $css;
                } else {
                    $content[] = '/* =====[ FILE IS MISSED: ' . $pp . ']===== */';
                }
            }
            file_put_contents($cachePath, implode("\n", $content));
        }
        // Include resulted combined stylesheet in the list of stylesheets
        $stylesheets->insert([
            'url'      => str_replace($basePath, wp_styles()->base_url . '/', $cachePath),
            'priority' => 1,
        ], 1);
        foreach ($stylesheets as $item) {
            $this->head->insert('<link rel="stylesheet" type="text/css" href="' . $item['url'] . '">', $item['priority']);
        }
    }

    private function isAjax(): bool
    {
        return (array_key_exists('HTTP_X_REQUESTED_WITH', $_SERVER) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
            || (function_exists('wp_doing_ajax') && wp_doing_ajax());
    }

    /**
     * Determine if client assets manager is enabled
     */
    private function isEnabled(): bool
    {
        return !(
            (PHP_SAPI === 'cli')
            || ($this->isAjax())
            || (function_exists('is_protected_endpoint') && is_protected_endpoint())
            || (function_exists('is_admin') && is_admin())
        );
    }

    public function setOptimizeAssets(bool $status): self
    {
        $this->optimizeAssets = $status;
        if ($this->optimizeAssets && !@mkdir($this->cacheDir) && !is_dir($this->cacheDir)) {
            trigger_error('Client Assets Manager: Assets optimization is disabled because cache directory is missed and can\'t be created',
                E_USER_WARNING);
            $this->optimizeAssets = false;
        }
        return $this;
    }

    /**
     * Add jQuery as an external library from Google CDN
     */
    public function addJquery(string $version = '3.6.0', bool $minified = true): self
    {
        if ($this->jqueryIncluded || !$this->isEnabled()) {
            return $this;
        }
        $this->jqueryIncluded = true;
        $url = sprintf('https://ajax.googleapis.com/ajax/libs/jquery/%s/jquery%s.js', $version, ($minified ? '.min' : ''));
        $this->addCode('<script type="text/javascript" src="' . $url . '"></script>', true, 9999);
        // Based on code from https://gist.github.com/brunoais/4690937#file-loadingjqueryasync-original-html
        $this->addJs('(function(w,d,u){w.readyQ=[];w.bindReadyQ=[];function p(x,y){if(x=="ready"){w.bindReadyQ.push(y);}else' .
            '{w.readyQ.push(x);}};var a={ready:p,bind:p};w.$=w.jQuery=function(f){if(f===d||f===u){return a}else{p(f)}}})(window,document)',
            false
        );
        $this->addJs('(function($,d){$.each(readyQ,function(i,f){$(f)});$.each(bindReadyQ,function(i,f){$(d).bind("ready",f)})})(jQuery,document)');
        return $this;
    }

    /**
     * Determine if the script with the given handle is already available in collected assets
     */
    public function haveScript(string $handle): bool
    {
        return array_key_exists($handle, $this->scripts);
    }

    /**
     * Add a given script into the list of assets
     */
    public function addScript(string $handle, string $url, array $deps = [], ?string $version = null): self
    {
        if ($this->isAjax()) {
            return $this;
        }
        if (!str_contains($url, '//')) {
            $url = get_template_directory_uri() . '/' . ltrim($url, '/');
        }
        $this->scripts[$handle] = [$handle, $url, $deps, $version];
        return $this;
    }

    /**
     * Add plain JavaScript code into the list of assets
     */
    public function addJs(string $code, bool $inFooter = true, int $priority = 100): self
    {
        $this->addCode('<script type="text/javascript">' . $code . '</script>', $inFooter, $priority);
        return $this;
    }

    /**
     * Include given fonts from Google Fonts into page
     *
     * @param string|array $font
     * @param string|array $subset
     * @return $this
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function addFont($font, $subset = null): self
    {
        if ($this->isAjax()) {
            return $this;
        }
        $url = $font;
        if (is_array($font)) {
            $query = [];
            $family = [];
            foreach ($font as $ff => $fw) {
                $family[] = $ff . ':' . (is_array($fw) ? implode(',', $fw) : $fw);
            }
            $query['family'] = implode('|', $family);
            $query['display'] = 'swap';
            if ($subset !== null) {
                $query['subset'] = is_array($subset) ? implode(',', $subset) : $subset;
            }
            $url = 'https://fonts.googleapis.com/css?' . http_build_query($query);
        }
        if ($this->optimizeAssets) {
            $hash = sha1($url);
            $cachePath = $this->cacheDir . '/font-' . $hash . '.css';
            if (!is_file($cachePath) || (int)filemtime($cachePath) < strtotime('last sunday')) {
                if (function_exists('curl_init')) {
                    $ch = curl_init();
                    $fp = fopen($cachePath, 'wb');
                    // We should expose ourselves as MSIE 11 to be able to get fonts
                    // into WOFF format because it is the format with the widest adoption
                    curl_setopt_array($ch, array(
                        CURLOPT_URL        => $url,
                        CURLOPT_FILE       => $fp,
                        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko'],
                        CURLOPT_TIMEOUT    => 10,
                    ));
                    /** @noinspection UnusedFunctionResultInspection */
                    curl_exec($ch);
                    curl_close($ch);
                    fclose($fp);
                } else {
                    $css = file_get_contents($url);
                    file_put_contents($cachePath, $css);
                }
            }
            $this->styles->insert([
                'external' => false,
                'url'      => $cachePath,
                'priority' => 9999,
            ], 9999);
        } else {
            $this->addCode('<link rel="stylesheet" type="text/css" href="' . $url . '" />', false, 9999);
        }
        return $this;
    }

    /**
     * Add stylesheet by url
     */
    public function addStylesheet(string $url, bool $inline = false, int $priority = 100): self
    {
        if ($this->isAjax()) {
            return $this;
        }
        if ($this->optimizeAssets && !$inline) {
            $external = false;
            $basePath = rtrim(str_replace('\\', '/', ABSPATH), '/');
            if (str_contains($url, '://')) {
                // This is URL
                if (str_contains($url, wp_styles()->base_url . '/')) {
                    // This is a local url
                    $path = str_replace(wp_styles()->base_url, $basePath, $url);
                    if (str_contains($path, '?')) {
                        $path = explode('?', $path, 2)[0];
                    }
                    $path = str_replace('\\', '/', $path);
                } else {
                    // This is an external url
                    $path = $url;
                    $external = true;
                }
            } else {
                // This is a path
                $path = str_replace('\\', '/', $url);
                $path = str_replace('\\', '/', rtrim(get_template_directory(), '/') . '/' . ltrim($path, '/'));
            }
            $this->styles->insert([
                'external' => $external,
                'url'      => $path,
                'priority' => $priority,
            ], $priority);
        } else {
            if ((!$inline) && (!str_contains($url, '//'))) {
                $url = get_template_directory_uri() . '/' . ltrim($url, '/');
            }
            if ($inline) {
                $path = str_replace('\\', '/', $url);
                $path = rtrim(get_template_directory(), '/') . '/' . ltrim($path, '/');
                if (is_file($path)) {
                    $css = file_get_contents($path);
                    $css = preg_replace('/\s+/', ' ', $css);
                    $html = '<style>' . trim($css) . '</style>';
                } else {
                    $html = '';
                    trigger_error('Client Assets Manager: Unavailable path is given to inline style: ' . $url, E_USER_WARNING);
                }
            } else {
                $html = '<link rel="stylesheet" type="text/css" href="' . $url . '" />';
            }
            $this->addCode($html, false, $priority);
        }
        return $this;
    }

    /**
     * Add plain CSS styles into the list of assets
     */
    public function addCSS(string $code, int $priority = 100): self
    {
        $this->addCode('<style>' . $code . '</style>', false, $priority);
        return $this;
    }

    private function addCode(string $html, bool $inFooter = true, int $priority = 100): void
    {
        if ($inFooter) {
            $this->footer->insert($html, $priority);
        } else {
            $this->head->insert($html, $priority);
        }
    }

    private function merge(\SplPriorityQueue $queue): string
    {
        return implode("\n", iterator_to_array($queue, false));
    }
}
