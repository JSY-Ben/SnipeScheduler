<?php

if (!function_exists('app_capture_pending_login_action')) {
    function app_capture_pending_login_action(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $scriptFile = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $publicRoot = realpath(APP_ROOT . '/public');
        $script = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($scriptFile !== false && $publicRoot !== false && strpos($scriptFile, $publicRoot . DIRECTORY_SEPARATOR) === 0) {
            $script = str_replace(DIRECTORY_SEPARATOR, '/', substr($scriptFile, strlen($publicRoot) + 1));
        }
        $scriptBase = basename($script);
        if ($script === '' || in_array($scriptBase, ['login.php', 'login_process.php', 'resume_action.php'], true)) {
            return;
        }

        if ($method === 'GET') {
            $query = http_build_query($_GET);
            $_SESSION['pending_login_action'] = [
                'method' => 'GET',
                'target' => $script . ($query !== '' ? '?' . $query : ''),
                'created_at' => time(),
            ];
            return;
        }

        // Only replay POST actions deliberately exposed to logged-out users.
        $resumablePosts = ['basket_add.php'];
        if ($method === 'POST' && in_array($script, $resumablePosts, true)) {
            $payload = $_POST;
            if (strlen(serialize($payload)) <= 65536) {
                $_SESSION['pending_login_action'] = [
                    'method' => 'POST',
                    'target' => $script,
                    'payload' => $payload,
                    'created_at' => time(),
                ];
            }
        }
    }
}

if (!function_exists('app_pending_login_redirect')) {
    function app_pending_login_redirect(): string
    {
        $pending = $_SESSION['pending_login_action'] ?? null;
        if (!is_array($pending) || (time() - (int)($pending['created_at'] ?? 0)) > 1800) {
            unset($_SESSION['pending_login_action']);
            return 'index.php';
        }

        if (($pending['method'] ?? '') === 'POST') {
            return 'resume_action.php';
        }

        $target = (string)($pending['target'] ?? '');
        unset($_SESSION['pending_login_action']);
        if ($target === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $target)) {
            return 'index.php';
        }

        return $target;
    }
}
