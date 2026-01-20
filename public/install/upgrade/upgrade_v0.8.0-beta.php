<?php
require_once __DIR__ . '/../../../src/config_writer.php';

function upgrade_apply_v0_8_0_beta(string $configFile, array $config, array &$messages, array &$errors): array
{
    if ($configFile === '') {
        $errors[] = 'config.php not found; cannot migrate staff roles to admin.';
        return $config;
    }

    $auth = $config['auth'] ?? [];
    $normalizeList = static function ($raw): array {
        if (!is_array($raw)) {
            $raw = $raw !== '' ? [$raw] : [];
        }
        return array_values(array_filter(array_map('trim', $raw), 'strlen'));
    };
    $normalizeEmails = static function ($raw): array {
        if (!is_array($raw)) {
            $raw = $raw !== '' ? [$raw] : [];
        }
        return array_values(array_filter(array_map('strtolower', array_map('trim', $raw)), 'strlen'));
    };

    $changed = false;
    if (empty($auth['admin_group_cn']) && !empty($auth['staff_group_cn'])) {
        $auth['admin_group_cn'] = $normalizeList($auth['staff_group_cn']);
        $changed = true;
    }
    if (empty($auth['google_admin_emails']) && !empty($auth['google_staff_emails'])) {
        $auth['google_admin_emails'] = $normalizeEmails($auth['google_staff_emails']);
        $changed = true;
    }
    if (empty($auth['microsoft_admin_emails']) && !empty($auth['microsoft_staff_emails'])) {
        $auth['microsoft_admin_emails'] = $normalizeEmails($auth['microsoft_staff_emails']);
        $changed = true;
    }

    if (empty($auth['checkout_group_cn'])) {
        $auth['checkout_group_cn'] = [];
    }
    if (empty($auth['google_checkout_emails'])) {
        $auth['google_checkout_emails'] = [];
    }
    if (empty($auth['microsoft_checkout_emails'])) {
        $auth['microsoft_checkout_emails'] = [];
    }

    if ($changed) {
        $config['auth'] = $auth;
        try {
            $content = layout_build_config_file($config, [
                'SNIPEIT_API_PAGE_LIMIT' => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
                'CATALOGUE_ITEMS_PER_PAGE' => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
            ]);
            file_put_contents($configFile, $content);
            $messages[] = 'Updated auth configuration to promote existing staff entries to admin.';
        } catch (Throwable $e) {
            $errors[] = 'Failed to update config.php for admin migration: ' . $e->getMessage();
        }
    }

    return $config;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $appRoot = realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..');
    $defaultConfig = $appRoot . '/config/config.php';
    $legacyConfig = $appRoot . '/config.php';

    $configFile = $argv[1] ?? '';
    if ($configFile === '') {
        $configFile = is_file($defaultConfig) ? $defaultConfig : (is_file($legacyConfig) ? $legacyConfig : '');
    }

    $messages = [];
    $errors = [];
    $config = [];

    if ($configFile === '' || !is_file($configFile)) {
        fwrite(STDERR, "config.php not found. Provide a path as the first argument.\n");
        exit(1);
    }

    try {
        $config = require $configFile;
        if (!is_array($config)) {
            $config = [];
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed to load config.php: " . $e->getMessage() . "\n");
        exit(1);
    }

    upgrade_apply_v0_8_0_beta($configFile, $config, $messages, $errors);

    foreach ($messages as $msg) {
        fwrite(STDOUT, $msg . "\n");
    }
    foreach ($errors as $err) {
        fwrite(STDERR, $err . "\n");
    }

    exit(!empty($errors) ? 1 : 0);
}
