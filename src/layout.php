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

if (!function_exists('layout_default_app_name')) {
    function layout_default_app_name(): string
    {
        return 'SnipeScheduler';
    }
}

if (!function_exists('layout_app_name')) {
    function layout_app_name(?array $cfg = null): string
    {
        $config = layout_cached_config($cfg);
        $name = trim((string)($config['app']['name'] ?? ''));
        if ($name === '') {
            return layout_default_app_name();
        }
        return $name;
    }
}

if (!function_exists('layout_html_escape')) {
    function layout_html_escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('layout_upgrade_description_from_sql')) {
    function layout_upgrade_description_from_sql(string $path, string $version): string
    {
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;
        if (is_array($lines)) {
            foreach (array_slice($lines, 0, 20) as $line) {
                $line = trim((string)$line);
                if (preg_match('/^--\s*Upgrade:\s*(.+)$/i', $line, $matches)) {
                    return trim((string)$matches[1]);
                }
            }

            foreach (array_slice($lines, 0, 20) as $line) {
                $line = trim((string)$line);
                if (preg_match('/^--\s*(.+)$/', $line, $matches)) {
                    $comment = trim((string)$matches[1]);
                    if ($comment !== '' && stripos($comment, 'compatible with') !== 0) {
                        return $comment;
                    }
                }
            }
        }

        $label = preg_replace('/^v?\d+(?:\.\d+)*(?:-[a-z]+)?-/i', '', $version);
        $label = is_string($label) && $label !== '' ? $label : $version;
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
        $label = str_replace(['_', '-'], ' ', (string)$label);
        $label = trim((string)preg_replace('/\s+/', ' ', (string)$label));

        return $label !== '' ? ucfirst($label) : $version;
    }
}

if (!function_exists('layout_database_upgrade_scripts')) {
    function layout_database_upgrade_scripts(): array
    {
        $upgradeDir = APP_ROOT . '/public/install/upgrade';
        $files = glob($upgradeDir . '/*.sql') ?: [];
        sort($files);

        $scripts = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $scripts[] = [
                'version' => $version,
                'path' => $file,
                'description' => layout_upgrade_description_from_sql($file, $version),
            ];
        }

        return $scripts;
    }
}

if (!function_exists('layout_pending_database_upgrades')) {
    function layout_pending_database_upgrades(): array
    {
        $scripts = layout_database_upgrade_scripts();
        $appliedVersions = [];
        $loadError = '';

        try {
            require_once SRC_PATH . '/db.php';
            global $pdo;

            $rows = $pdo->query('SELECT version FROM schema_version ORDER BY applied_at ASC')->fetchAll(PDO::FETCH_COLUMN);
            $appliedVersions = array_map('strval', $rows);
        } catch (Throwable $e) {
            $loadError = $e->getMessage();
        }

        $pending = [];
        foreach ($scripts as $script) {
            if (!in_array($script['version'], $appliedVersions, true)) {
                $pending[] = $script;
            }
        }

        return [
            'pending' => $pending,
            'load_error' => $loadError,
        ];
    }
}

if (!function_exists('layout_is_install_upgrade_page')) {
    function layout_is_install_upgrade_page(): bool
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return (bool)preg_match('#/install/upgrade/(?:index\.php)?$#', $scriptName)
            || (bool)preg_match('#/install/upgrade$#', $scriptName);
    }
}

if (!function_exists('layout_upgrade_page_url')) {
    function layout_upgrade_page_url(): string
    {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $leaf = $scriptDir !== '' ? basename($scriptDir) : '';

        if ($leaf === 'upgrade' && basename(dirname($scriptDir)) === 'install') {
            return 'index.php';
        }

        if ($leaf === 'install') {
            return 'upgrade/';
        }

        return 'install/upgrade/';
    }
}

if (!function_exists('layout_render_pending_upgrade_modal')) {
    function layout_render_pending_upgrade_modal(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (empty($_SESSION['show_pending_upgrade_modal'])) {
            return;
        }
        unset($_SESSION['show_pending_upgrade_modal']);

        $sessionUser = $_SESSION['user'] ?? [];
        if (empty($sessionUser['is_admin']) || layout_is_install_upgrade_page()) {
            return;
        }

        $upgradeStatus = layout_pending_database_upgrades();
        $pending = $upgradeStatus['pending'] ?? [];
        if (empty($pending)) {
            return;
        }

        $upgradeUrl = layout_html_escape(layout_upgrade_page_url());
        $loadError = trim((string)($upgradeStatus['load_error'] ?? ''));

        echo '<div id="pending-upgrade-modal"'
            . ' class="catalogue-modal catalogue-modal--upgrade"'
            . ' role="dialog"'
            . ' aria-modal="true"'
            . ' aria-hidden="true"'
            . ' aria-labelledby="pending-upgrade-title"'
            . ' hidden>';
        echo '<div class="catalogue-modal__backdrop" data-pending-upgrade-close></div>';
        echo '<div class="catalogue-modal__dialog" role="document">';
        echo '<div class="catalogue-modal__header">';
        echo '<h2 id="pending-upgrade-title" class="catalogue-modal__title">Database Upgrade Pending</h2>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-pending-upgrade-close>Close</button>';
        echo '</div>';
        echo '<div class="catalogue-modal__body">';
        echo '<p class="text-muted mb-3">The booking database has pending upgrade scripts. Review them before running the upgrade.</p>';

        if ($loadError !== '') {
            echo '<div class="alert alert-warning small mb-3">'
                . 'Could not read the applied upgrade history, so all upgrade scripts are listed for review.'
                . '</div>';
        }

        echo '<ul class="upgrade-modal__list">';
        foreach ($pending as $item) {
            $version = layout_html_escape((string)($item['version'] ?? ''));
            $description = layout_html_escape((string)($item['description'] ?? ''));
            echo '<li class="upgrade-modal__item">';
            echo '<strong>' . $version . '</strong>';
            if ($description !== '') {
                echo '<span>' . $description . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">';
        echo '<button type="button" class="btn btn-outline-secondary" data-pending-upgrade-close>Remind me later</button>';
        echo '<a class="btn btn-primary" href="' . $upgradeUrl . '">Open upgrade page</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo <<<'SCRIPT'
<script>
(function () {
    const openModal = function () {
        const modal = document.getElementById('pending-upgrade-modal');
        if (!modal) return;

        const closeModal = function () {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('catalogue-modal-open');
            window.setTimeout(function () {
                modal.hidden = true;
            }, 220);
        };

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('catalogue-modal-open');
        window.requestAnimationFrame(function () {
            modal.classList.add('is-open');
            const action = modal.querySelector('a.btn-primary');
            if (action && typeof action.focus === 'function') {
                action.focus();
            }
        });

        modal.querySelectorAll('[data-pending-upgrade-close]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openModal);
    } else {
        openModal();
    }
})();
</script>
SCRIPT;
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

.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange,
.flatpickr-day.selected.inRange,
.flatpickr-day.startRange.inRange,
.flatpickr-day.endRange.inRange,
.flatpickr-day.selected:hover,
.flatpickr-day.startRange:hover,
.flatpickr-day.endRange:hover,
.flatpickr-day.selected:focus,
.flatpickr-day.startRange:focus,
.flatpickr-day.endRange:focus {
    background: var(--primary);
    border-color: var(--primary);
}

.flatpickr-day.today {
    border-color: var(--primary);
}

.flatpickr-day.today:hover,
.flatpickr-day.today:focus {
    background: var(--primary-soft);
    border-color: var(--primary-soft);
}

.flatpickr-months .flatpickr-prev-month:hover svg,
.flatpickr-months .flatpickr-next-month:hover svg {
    fill: var(--primary);
}

.flatpickr-time input:hover,
.flatpickr-time .flatpickr-am-pm:hover,
.flatpickr-time input:focus,
.flatpickr-time .flatpickr-am-pm:focus {
    background: rgba(var(--primary-rgb), 0.12);
}

.flatpickr-calendar .flatpickr-confirm {
    background: var(--primary) !important;
    border-color: var(--primary) !important;
    color: #fff !important;
}

.flatpickr-calendar .flatpickr-confirm:hover,
.flatpickr-calendar .flatpickr-confirm:focus {
    background: var(--primary-strong) !important;
    border-color: var(--primary-strong) !important;
    color: #fff !important;
}

.flatpickr-calendar .flatpickr-confirm svg {
    fill: currentColor;
}
</style>
CSS;

        return $style;
    }
}

if (!function_exists('layout_render_nav')) {
    /**
     * Render the main app navigation.
     * Highlights the active page and hides staff-only items for non-staff users.
     * Guests only see Dashboard and Catalogue.
     */
    function layout_render_nav(string $active, bool $isStaff, bool $isAdmin = false, bool $isAuthenticated = true): string
    {
        if (!$isAuthenticated) {
            $links = [
                ['href' => 'index.php',     'label' => 'Dashboard', 'staff' => false],
                ['href' => 'catalogue.php', 'label' => 'Catalogue', 'staff' => false],
            ];
        } else {
            $links = [
                ['href' => 'index.php',          'label' => 'Dashboard',           'staff' => false],
                ['href' => 'catalogue.php',      'label' => 'Catalogue',           'staff' => false],
                ['href' => 'my_bookings.php',    'label' => 'My Reservations',     'staff' => false],
                ['href' => 'reservations.php',   'label' => 'Reservations',        'staff' => true],
                ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout',      'staff' => true],
                ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',       'staff' => true],
                ['href' => 'activity_log.php',   'label' => 'Admin',               'staff' => false, 'admin_only' => true],
            ];
        }

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

        layout_render_pending_upgrade_modal();

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
            date: normalizeFormats([
                cfg.machine_date_format,
                cfg.alt_date_format,
                'Y-m-d',
            ]),
            datetime: normalizeFormats([
                cfg.machine_datetime_format,
                cfg.alt_datetime_format,
                'Y-m-d\\TH:i:S',
                'Y-m-d\\TH:i',
                'Y-m-d H:i:S',
                'Y-m-d H:i',
            ]),
            time: normalizeFormats([
                cfg.machine_time_format,
                cfg.alt_time_format,
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

        const isMobilePickerMode = () => {
            const hasMatchMedia = typeof window.matchMedia === 'function';
            const narrowViewport = hasMatchMedia && window.matchMedia('(max-width: 768px)').matches;
            const coarsePointer = hasMatchMedia && window.matchMedia('(pointer: coarse)').matches;
            return narrowViewport || coarsePointer;
        };

        const parseDateFactory = (formats) => (raw, formatHint) => {
            const value = String(raw || '').trim();
            if (!value) return undefined;

            const parseFormats = normalizeFormats([formatHint].concat(formats || []));
            for (let i = 0; i < parseFormats.length; i += 1) {
                const parsed = window.flatpickr.parseDate(value, parseFormats[i]);
                if (parsed instanceof Date && !Number.isNaN(parsed.getTime())) {
                    return parsed;
                }
            }

            // Only allow native parsing for strict ISO-like values to avoid
            // locale-dependent reinterpretation on blur/close.
            if (/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/.test(value)) {
                const timestamp = Date.parse(value.replace(' ', 'T'));
                if (!Number.isNaN(timestamp)) {
                    return new Date(timestamp);
                }
            }

            return undefined;
        };

        const centerOpenCalendar = (_selectedDates, _dateStr, instance) => {
            if (!instance || !instance.calendarContainer) return;

            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            if (viewportHeight <= 0) return;

            const calendar = instance.calendarContainer;
            const topPadding = 16;
            const bottomPadding = 16;
            const availableHeight = Math.max(1, viewportHeight - topPadding - bottomPadding);

            const scrollCalendarIntoView = () => {
                const rect = calendar.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;

                let targetTop = topPadding;
                if (rect.height < availableHeight) {
                    targetTop = topPadding + ((availableHeight - rect.height) / 2);
                }

                const desiredY = window.scrollY + (rect.top - targetTop);
                const maxScrollY = Math.max(0, document.documentElement.scrollHeight - viewportHeight);
                const nextY = Math.max(0, Math.min(maxScrollY, desiredY));
                if (Math.abs(nextY - window.scrollY) < 2) return;

                const reduceMotion = window.matchMedia
                    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({
                    top: nextY,
                    behavior: reduceMotion ? 'auto' : 'smooth',
                });
            };

            window.requestAnimationFrame(() => {
                scrollCalendarIntoView();
                window.setTimeout(scrollCalendarIntoView, 90);
            });
        };

        const initInput = (input) => {
            if (!(input instanceof HTMLInputElement)) return;
            if (input.dataset.flatpickrBound === '1') return;
            if (input.getAttribute('data-flatpickr') === 'off') return;

            const pickerType = detectPickerType(input);
            if (!pickerType) return;

            const originalType = String(input.getAttribute('type') || '').toLowerCase().trim();
            const nativeType = originalType === 'date' || originalType === 'time' || originalType === 'datetime-local';
            const mobilePickerMode = isMobilePickerMode();
            if (mobilePickerMode && nativeType) {
                // Keep native phone picker controls on mobile devices.
                input.dataset.flatpickrBound = '1';
                return;
            }
            if (nativeType) {
                input.setAttribute('type', 'text');
            }

            const baseOptions = {
                allowInput: true,
                disableMobile: true,
                altInput: true,
                altInputClass: (input.className ? input.className + ' ' : '') + 'flatpickr-alt-input',
                parseDate: parseDateFactory(fallbackFormats[pickerType] || []),
                onOpen: [centerOpenCalendar],
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
        $appName = layout_app_name($cfg);

        $logoUrl = '';
        if (isset($cfg['app']['logo_url']) && trim($cfg['app']['logo_url']) !== '') {
            $logoUrl = trim($cfg['app']['logo_url']);
        }

        if ($logoUrl === '') {
            $logoUrl = layout_default_logo_url();
        }

        $urlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $altEsc = htmlspecialchars($appName . ' logo', ENT_QUOTES, 'UTF-8');
        return '<div class="app-logo text-center mb-3">'
            . '<a href="index.php" aria-label="Go to dashboard">'
            . '<img src="' . $urlEsc . '" alt="' . $altEsc . '" style="max-height:80px; width:auto; height:auto; max-width:100%; object-fit:contain;">'
            . '</a>'
            . '</div>';
    }
}
