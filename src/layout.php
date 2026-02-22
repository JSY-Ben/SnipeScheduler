<?php
// layout.php
// Shared layout helpers (nav, logo, theme, footer) for SnipeScheduler pages.

require_once __DIR__ . '/bootstrap.php';

/**
 * Cache config and expose helper functions for shared UI elements.
 */
if (!function_exists('layout_cached_config')) {
    function layout_cached_config(?array $cfg = null): array
    {
        static $cachedConfig = null;

        if ($cfg !== null) {
            return $cfg;
        }

        if ($cachedConfig === null) {
            try {
                $cachedConfig = load_config();
            } catch (Throwable $e) {
                $cachedConfig = [];
            }
        }

        return $cachedConfig ?? [];
    }
}

/**
 * Normalize a hex color string to #rrggbb.
 */
if (!function_exists('layout_normalize_hex_color')) {
    function layout_normalize_hex_color(?string $color, string $fallback): string
    {
        $fallback = ltrim($fallback, '#');
        $candidate = trim((string)$color);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
        } elseif (preg_match('/^#?([0-9a-fA-F]{3})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } else {
            $hex = strtolower($fallback);
        }

        return '#' . $hex;
    }
}

/**
 * Convert #rrggbb to [r, g, b].
 */
if (!function_exists('layout_color_to_rgb')) {
    function layout_color_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

/**
 * Adjust lightness: positive to lighten, negative to darken.
 */
if (!function_exists('layout_adjust_lightness')) {
    function layout_adjust_lightness(string $hex, float $ratio): string
    {
        $ratio = max(-1.0, min(1.0, $ratio));
        [$r, $g, $b] = layout_color_to_rgb($hex);

        $adjust = static function (int $channel) use ($ratio): int {
            if ($ratio >= 0) {
                return (int)round($channel + (255 - $channel) * $ratio);
            }
            return (int)round($channel * (1 + $ratio));
        };

        $nr = str_pad(dechex($adjust($r)), 2, '0', STR_PAD_LEFT);
        $ng = str_pad(dechex($adjust($g)), 2, '0', STR_PAD_LEFT);
        $nb = str_pad(dechex($adjust($b)), 2, '0', STR_PAD_LEFT);

        return '#' . $nr . $ng . $nb;
    }
}

if (!function_exists('layout_primary_color')) {
    function layout_primary_color(?array $cfg = null): string
    {
        $config = layout_cached_config($cfg);
        $raw    = $config['app']['primary_color'] ?? '#660000';

        return layout_normalize_hex_color($raw, '#660000');
    }
}

if (!function_exists('layout_theme_styles')) {
    function layout_theme_styles(?array $cfg = null): string
    {
        $primary      = layout_primary_color($cfg);
        $primarySoft  = layout_adjust_lightness($primary, 0.3);   // subtle gradient partner
        $primaryStrong = layout_adjust_lightness($primary, -0.08); // slightly deeper for contrast

        [$r, $g, $b]          = layout_color_to_rgb($primary);
        [$rs, $gs, $bs]       = layout_color_to_rgb($primaryStrong);
        [$rl, $gl, $bl]       = layout_color_to_rgb($primarySoft);

        $style = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">' . "\n"
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/confirmDate/confirmDate.css">' . "\n"
            . <<<CSS
<style>
:root {
    --primary: {$primary};
    --primary-strong: {$primaryStrong};
    --primary-soft: {$primarySoft};
    --primary-rgb: {$r}, {$g}, {$b};
    --primary-strong-rgb: {$rs}, {$gs}, {$bs};
    --primary-soft-rgb: {$rl}, {$gl}, {$bl};
    --accent: var(--primary-strong);
    --accent-2: var(--primary-soft);
}
</style>
CSS;

        return $style;
    }
}

if (!function_exists('layout_render_nav')) {
    /**
     * Render the main app navigation. Highlights the active page and hides staff-only items for non-staff users.
     */
    function layout_render_nav(string $active, bool $isStaff, bool $isAdmin = false): string
    {
        $links = [
            ['href' => 'index.php',          'label' => 'Dashboard',           'staff' => false],
            ['href' => 'catalogue.php',      'label' => 'Catalogue',           'staff' => false],
            ['href' => 'my_bookings.php',    'label' => 'My Reservations',     'staff' => false],
            ['href' => 'reservations.php',   'label' => 'Reservations',        'staff' => true],
            ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout',      'staff' => true],
            ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',       'staff' => true],
            ['href' => 'activity_log.php',   'label' => 'Admin',               'staff' => false, 'admin_only' => true],
        ];

        $html = '<nav class="app-nav">';
        foreach ($links as $link) {
            if (!empty($link['admin_only'])) {
                if (!$isAdmin) {
                    continue;
                }
            } elseif ($link['staff'] && !$isStaff) {
                continue;
            }

            $href    = htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8');
            $label   = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $classes = 'app-nav-link' . ($active === $link['href'] ? ' active' : '');

            $html .= '<a href="' . $href . '" class="' . $classes . '">' . $label . '</a>';
        }
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('layout_footer')) {
    function layout_footer(): void
    {
        $versionFile = APP_ROOT . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
        $flatpickrCfg = app_flatpickr_settings(layout_cached_config());
        $flatpickrCfgJson = json_encode($flatpickrCfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        echo '<script src="assets/nav.js"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/confirmDate/confirmDate.js"></script>';
        echo '<script>window.SnipeSchedulerFlatpickr=' . ($flatpickrCfgJson ?: '{}') . ';</script>';
        echo <<<SCRIPT
<script>
(function () {
    const boot = () => {
        if (typeof window.flatpickr !== 'function') return;
        const cfg = window.SnipeSchedulerFlatpickr || {};

        const normalizeFormats = (formats) => {
            const unique = [];
            formats.forEach((fmt) => {
                const v = String(fmt || '').trim();
                if (!v || unique.indexOf(v) !== -1) return;
                unique.push(v);
            });
            return unique;
        };

        const fallbackFormats = {
            date: normalizeFormats([cfg.machine_date_format, 'Y-m-d']),
            datetime: normalizeFormats([
                cfg.machine_datetime_format,
                'Y-m-d\\TH:i:S',
                'Y-m-d\\TH:i',
                'Y-m-d H:i:S',
                'Y-m-d H:i',
            ]),
            time: normalizeFormats([
                cfg.machine_time_format,
                'H:i:S',
                'H:i',
                'h:i:S K',
                'h:i K',
            ]),
        };

        const detectPickerType = (input) => {
            const explicit = String(input.getAttribute('data-flatpickr') || '').toLowerCase().trim();
            if (explicit === 'date' || explicit === 'time' || explicit === 'datetime') {
                return explicit;
            }

            const inputType = String(input.getAttribute('type') || '').toLowerCase().trim();
            if (inputType === 'date') return 'date';
            if (inputType === 'time') return 'time';
            if (inputType === 'datetime-local') return 'datetime';
            return '';
        };

        const parseDateFactory = (formats) => (raw) => {
            const value = String(raw || '').trim();
            if (!value) return undefined;

            for (let i = 0; i < formats.length; i += 1) {
                const parsed = window.flatpickr.parseDate(value, formats[i]);
                if (parsed instanceof Date && !Number.isNaN(parsed.getTime())) {
                    return parsed;
                }
            }

            const timestamp = Date.parse(value);
            if (!Number.isNaN(timestamp)) {
                return new Date(timestamp);
            }

            return undefined;
        };

        const initInput = (input) => {
            if (!(input instanceof HTMLInputElement)) return;
            if (input.dataset.flatpickrBound === '1') return;
            if (input.getAttribute('data-flatpickr') === 'off') return;

            const pickerType = detectPickerType(input);
            if (!pickerType) return;

            const originalType = String(input.getAttribute('type') || '').toLowerCase().trim();
            if (originalType === 'date' || originalType === 'time' || originalType === 'datetime-local') {
                input.setAttribute('type', 'text');
            }

            const baseOptions = {
                allowInput: true,
                disableMobile: true,
                altInput: true,
                altInputClass: (input.className ? input.className + ' ' : '') + 'flatpickr-alt-input',
                parseDate: parseDateFactory(fallbackFormats[pickerType] || []),
            };
            if ((pickerType === 'time' || pickerType === 'datetime') && typeof window.confirmDatePlugin === 'function') {
                baseOptions.plugins = [
                    new window.confirmDatePlugin({
                        confirmText: 'Apply',
                        showAlways: false,
                        theme: 'light',
                    }),
                ];
            }
            const parsedInitialDate = baseOptions.parseDate(input.value);
            if (parsedInitialDate instanceof Date && !Number.isNaN(parsedInitialDate.getTime())) {
                baseOptions.defaultDate = parsedInitialDate;
            }

            if (pickerType === 'date') {
                baseOptions.dateFormat = String(cfg.machine_date_format || 'Y-m-d');
                baseOptions.altFormat = String(cfg.alt_date_format || 'Y-m-d');
            } else if (pickerType === 'time') {
                baseOptions.enableTime = true;
                baseOptions.noCalendar = true;
                baseOptions.time_24hr = !!cfg.time_24hr;
                baseOptions.enableSeconds = !!cfg.enable_seconds;
                baseOptions.dateFormat = String(cfg.machine_time_format || 'H:i');
                baseOptions.altFormat = String(cfg.alt_time_format || 'H:i');
            } else {
                baseOptions.enableTime = true;
                baseOptions.time_24hr = !!cfg.time_24hr;
                baseOptions.enableSeconds = !!cfg.enable_seconds;
                baseOptions.dateFormat = String(cfg.machine_datetime_format || 'Y-m-d\\TH:i');
                baseOptions.altFormat = String(cfg.alt_datetime_format || 'Y-m-d H:i');
            }

            try {
                const picker = window.flatpickr(input, baseOptions);
                if (picker && picker.altInput) {
                    if (input.style && input.style.cssText) {
                        picker.altInput.style.cssText = input.style.cssText;
                    }
                    if (input.hasAttribute('placeholder')) {
                        picker.altInput.setAttribute('placeholder', input.getAttribute('placeholder') || '');
                    }
                    picker.altInput.required = input.required;
                    picker.altInput.disabled = input.disabled;
                    picker.altInput.readOnly = input.readOnly;
                }
                input.dataset.flatpickrBound = '1';
            } catch (e) {
                // Keep native input as fallback if Flatpickr fails for this field.
            }
        };

        const scan = (root) => {
            if (!(root instanceof Element || root instanceof Document)) return;
            if (root instanceof HTMLInputElement) {
                initInput(root);
            }
            root.querySelectorAll('input').forEach(initInput);
        };

        scan(document);

        if (document.body && typeof MutationObserver === 'function') {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node instanceof Element) {
                            scan(node);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
SCRIPT;
        echo '<footer class="text-center text-muted mt-4 small">'
            . 'SnipeScheduler Version ' . $versionEsc . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
    }
}

if (!function_exists('layout_logo_tag')) {
    function layout_default_logo_url(): string
    {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $baseDir    = $scriptDir;

        $leaf = $scriptDir !== '' ? basename($scriptDir) : '';
        if ($leaf === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
        } elseif ($leaf === 'upgrade' && basename(dirname($scriptDir)) === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname(dirname($scriptDir))), '/');
        }

        if ($baseDir === '') {
            return '/SnipeScheduler-Logo.png';
        }

        return $baseDir . '/SnipeScheduler-Logo.png';
    }

    function layout_logo_tag(?array $cfg = null): string
    {
        $cfg = layout_cached_config($cfg);

        $logoUrl = '';
        if (isset($cfg['app']['logo_url']) && trim($cfg['app']['logo_url']) !== '') {
            $logoUrl = trim($cfg['app']['logo_url']);
        }

        if ($logoUrl === '') {
            $logoUrl = layout_default_logo_url();
        }

        $urlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        return '<div class="app-logo text-center mb-3">'
            . '<a href="index.php" aria-label="Go to dashboard">'
            . '<img src="' . $urlEsc . '" alt="SnipeScheduler logo" style="max-height:80px; width:auto; height:auto; max-width:100%; object-fit:contain;">'
            . '</a>'
            . '</div>';
    }
}
