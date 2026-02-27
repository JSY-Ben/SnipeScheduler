<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/config_writer.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$configPath  = CONFIG_PATH . '/config.php';
$examplePath = CONFIG_PATH . '/config.example.php';
$messages = [];
$errors = [];

try {
    $config = load_config();
} catch (Throwable $e) {
    $config = is_file($examplePath) ? require $examplePath : [];
    $errors[] = 'Config file missing - showing defaults from config.example.php.';
}

$definedValues = [
    'SNIPEIT_API_PAGE_LIMIT'    => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
    'CATALOGUE_ITEMS_PER_PAGE'  => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
];

$tz = app_get_timezone($config);
$timeFormat = app_get_time_format($config);
$dateInputFormat = strpos($timeFormat, 's') !== false ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
$dateInputStep = strpos($timeFormat, 's') !== false ? 1 : 60;

$appCfg = is_array($config['app'] ?? null) ? $config['app'] : [];
$storedAnnouncements = app_announcements_from_app_config($appCfg, $tz);

$editorForm = [
    'start' => '',
    'end' => '',
    'message' => '',
    'original_token' => '',
];
$openEditorOnLoad = false;

$normalizeAnnouncements = static function (array $items) use ($tz): array {
    $seen = [];
    $unique = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = app_announcement_normalize_entry($item, $tz);
        if (!$normalized) {
            continue;
        }
        $token = (string)($normalized['token'] ?? '');
        if ($token !== '' && isset($seen[$token])) {
            continue;
        }
        if ($token !== '') {
            $seen[$token] = true;
        }
        $unique[] = $normalized;
    }

    usort($unique, static function (array $a, array $b): int {
        if ($a['start_ts'] !== $b['start_ts']) {
            return $a['start_ts'] <=> $b['start_ts'];
        }
        if ($a['end_ts'] !== $b['end_ts']) {
            return $a['end_ts'] <=> $b['end_ts'];
        }
        return strcmp($a['message'], $b['message']);
    });

    return $unique;
};

$persistAnnouncements = static function (array $normalizedAnnouncements) use (
    &$config,
    &$appCfg,
    &$storedAnnouncements,
    $configPath,
    $definedValues,
    &$errors
): bool {
    $newConfig = $config;
    $newApp = is_array($newConfig['app'] ?? null) ? $newConfig['app'] : [];

    $newApp['announcements'] = array_map(static function (array $item): array {
        return [
            'message' => (string)$item['message'],
            'start_ts' => (int)$item['start_ts'],
            'end_ts' => (int)$item['end_ts'],
            'start_datetime' => (string)$item['start_datetime'],
            'end_datetime' => (string)$item['end_datetime'],
        ];
    }, $normalizedAnnouncements);

    $firstAnnouncement = $normalizedAnnouncements[0] ?? null;
    if ($firstAnnouncement) {
        $newApp['announcement_message'] = (string)$firstAnnouncement['message'];
        $newApp['announcement_start_ts'] = (int)$firstAnnouncement['start_ts'];
        $newApp['announcement_end_ts'] = (int)$firstAnnouncement['end_ts'];
        $newApp['announcement_start_datetime'] = (string)$firstAnnouncement['start_datetime'];
        $newApp['announcement_end_datetime'] = (string)$firstAnnouncement['end_datetime'];
    } else {
        $newApp['announcement_message'] = '';
        $newApp['announcement_start_ts'] = 0;
        $newApp['announcement_end_ts'] = 0;
        $newApp['announcement_start_datetime'] = '';
        $newApp['announcement_end_datetime'] = '';
    }

    $newConfig['app'] = $newApp;
    $content = layout_build_config_file($newConfig, $definedValues);

    if (!is_dir(CONFIG_PATH)) {
        @mkdir(CONFIG_PATH, 0755, true);
    }

    if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
        $errors[] = 'Could not write config.php. Check file permissions on the config/ directory.';
        return false;
    }

    $config = $newConfig;
    $appCfg = $newApp;
    $storedAnnouncements = $normalizedAnnouncements;
    return true;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete') {
        $tokenToDelete = trim((string)($_POST['announcement_token'] ?? ''));
        if ($tokenToDelete === '') {
            $errors[] = 'Could not delete announcement: missing identifier.';
        } else {
            $remaining = [];
            $removed = false;
            foreach ($storedAnnouncements as $item) {
                $itemToken = (string)($item['token'] ?? '');
                if (!$removed && $itemToken === $tokenToDelete) {
                    $removed = true;
                    continue;
                }
                $remaining[] = $item;
            }

            if (!$removed) {
                $errors[] = 'Announcement not found. Refresh and try again.';
            } else {
                $remaining = $normalizeAnnouncements($remaining);
                if ($persistAnnouncements($remaining)) {
                    $messages[] = 'Announcement deleted.';
                }
            }
        }
    } elseif ($action === 'save') {
        $startRaw = trim((string)($_POST['announcement_start'] ?? ''));
        $endRaw = trim((string)($_POST['announcement_end'] ?? ''));
        $messageRaw = trim((string)($_POST['announcement_message'] ?? ''));
        $originalToken = trim((string)($_POST['announcement_original_token'] ?? ''));

        $editorForm = [
            'start' => $startRaw,
            'end' => $endRaw,
            'message' => $messageRaw,
            'original_token' => $originalToken,
        ];

        if ($messageRaw === '') {
            $errors[] = 'Message is required.';
        }
        if ($startRaw === '' || $endRaw === '') {
            $errors[] = 'Start and end date/time are required.';
        }

        $startDt = $startRaw !== '' ? app_announcement_parse_datetime_input($startRaw, $tz) : null;
        $endDt = $endRaw !== '' ? app_announcement_parse_datetime_input($endRaw, $tz) : null;
        if (($startRaw !== '' && !$startDt) || ($endRaw !== '' && !$endDt)) {
            $errors[] = 'Please provide valid start and end date/time values.';
        } elseif ($startDt && $endDt && $endDt->getTimestamp() <= $startDt->getTimestamp()) {
            $errors[] = 'End date/time must be after start date/time.';
        }

        $normalizedNew = null;
        if (empty($errors)) {
            $normalizedNew = app_announcement_normalize_entry([
                'message' => $messageRaw,
                'start_ts' => $startDt->getTimestamp(),
                'end_ts' => $endDt->getTimestamp(),
            ], $tz);

            if (!$normalizedNew) {
                $errors[] = 'Could not normalize the announcement values.';
            }
        }

        if (empty($errors) && $normalizedNew) {
            $merged = [];
            $replaced = false;

            foreach ($storedAnnouncements as $item) {
                $itemToken = (string)($item['token'] ?? '');
                if (!$replaced && $originalToken !== '' && $itemToken === $originalToken) {
                    $merged[] = $normalizedNew;
                    $replaced = true;
                    continue;
                }
                $merged[] = $item;
            }

            if ($originalToken === '') {
                $merged[] = $normalizedNew;
            } elseif (!$replaced) {
                $errors[] = 'Announcement not found. Refresh and try again.';
            }

            if (empty($errors)) {
                $merged = $normalizeAnnouncements($merged);
                if ($persistAnnouncements($merged)) {
                    if ($originalToken === '') {
                        $messages[] = 'Announcement created.';
                    } else {
                        $messages[] = 'Announcement updated.';
                    }
                    $editorForm = [
                        'start' => '',
                        'end' => '',
                        'message' => '',
                        'original_token' => '',
                    ];
                } else {
                    $openEditorOnLoad = true;
                }
            }
        }

        if (!empty($errors)) {
            $openEditorOnLoad = true;
        }
    } else {
        $errors[] = 'Unsupported action.';
    }
}

$nowTs = time();
$announcementRows = [];
foreach ($storedAnnouncements as $item) {
    $startTs = (int)($item['start_ts'] ?? 0);
    $endTs = (int)($item['end_ts'] ?? 0);
    $status = 'Scheduled';
    if ($startTs > 0 && $endTs > 0) {
        if ($nowTs >= $startTs && $nowTs <= $endTs) {
            $status = 'Active now';
        } elseif ($nowTs > $endTs) {
            $status = 'Ended';
        }
    }

    $announcementRows[] = [
        'token' => (string)($item['token'] ?? ''),
        'start' => app_format_datetime($startTs, $config, $tz),
        'end' => app_format_datetime($endTs, $config, $tz),
        'status' => $status,
        'message' => (string)($item['message'] ?? ''),
        'start_input' => app_announcement_format_datetime_for_input($startTs, $tz, $dateInputFormat),
        'end_input' => app_announcement_format_datetime_for_input($endTs, $tz, $dateInputFormat),
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Announcements - <?= h(layout_app_name($config)) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Admin</h1>
            <div class="page-subtitle">
                Manage catalogue announcements shown to users when they open the catalogue.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('h', $errors)) ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="announcements.php">Announcements</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reports.php">Reports</a>
            </li>
        </ul>

        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-primary" id="announcement-new-open">New Announcement</button>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Configured Announcements</h5>
                <p class="text-muted small mb-3">Current announcement list and status.</p>

                <?php if (empty($announcementRows)): ?>
                    <div class="text-muted small">No announcements configured.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Message</th>
                                <th class="text-nowrap">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($announcementRows as $index => $row): ?>
                                <tr>
                                    <td><?= (int)($index + 1) ?></td>
                                    <td class="text-nowrap"><?= h($row['start']) ?></td>
                                    <td class="text-nowrap"><?= h($row['end']) ?></td>
                                    <td class="text-nowrap"><?= h($row['status']) ?></td>
                                    <td style="white-space: pre-wrap;"><?= h($row['message']) ?></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-announcement-edit>Edit</button>
                                        <form method="post"
                                              action="announcements.php"
                                              class="d-inline-block ms-1"
                                              onsubmit="return confirm('Delete this announcement?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="announcement_token" value="<?= h($row['token']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        <input type="hidden" data-announcement-token value="<?= h($row['token']) ?>">
                                        <input type="hidden" data-announcement-start value="<?= h($row['start_input']) ?>">
                                        <input type="hidden" data-announcement-end value="<?= h($row['end_input']) ?>">
                                        <textarea class="d-none" data-announcement-message><?= h($row['message']) ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="announcement-editor-modal"
     class="catalogue-modal catalogue-modal--announcement-editor"
     role="dialog"
     aria-modal="true"
     aria-hidden="true"
     aria-labelledby="announcement-editor-title"
     data-open-on-load="<?= $openEditorOnLoad ? '1' : '0' ?>"
     hidden>
    <div class="catalogue-modal__backdrop" data-announcement-editor-close></div>
    <div class="catalogue-modal__dialog" role="document">
        <div class="catalogue-modal__header">
            <h2 id="announcement-editor-title" class="catalogue-modal__title">New Announcement</h2>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-announcement-editor-close>
                Close
            </button>
        </div>
        <div class="catalogue-modal__body">
            <form method="post" action="announcements.php" class="row g-3 settings-form" id="announcement-editor-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden"
                       name="announcement_original_token"
                       id="announcement-original-token"
                       value="<?= h((string)$editorForm['original_token']) ?>">

                <div class="col-12">
                    <p class="text-muted small mb-1">
                        Users will see all active announcements together in one modal when opening the catalogue.
                    </p>
                    <div class="form-text mt-0">Time zone: <?= h((string)($appCfg['timezone'] ?? 'Europe/Jersey')) ?>.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="announcement-start">Start date/time</label>
                    <input type="datetime-local"
                           id="announcement-start"
                           name="announcement_start"
                           class="form-control"
                           step="<?= $dateInputStep ?>"
                           value="<?= h((string)$editorForm['start']) ?>"
                           required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="announcement-end">End date/time</label>
                    <input type="datetime-local"
                           id="announcement-end"
                           name="announcement_end"
                           class="form-control"
                           step="<?= $dateInputStep ?>"
                           value="<?= h((string)$editorForm['end']) ?>"
                           required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="announcement-message">Message</label>
                    <textarea id="announcement-message"
                              name="announcement_message"
                              rows="4"
                              class="form-control"
                              placeholder="Enter the announcement shown on catalogue load."
                              required><?= h((string)$editorForm['message']) ?></textarea>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-announcement-editor-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" id="announcement-save-btn">Create announcement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editorModal = document.getElementById('announcement-editor-modal');
    const newBtn = document.getElementById('announcement-new-open');
    const editorForm = document.getElementById('announcement-editor-form');
    const titleEl = document.getElementById('announcement-editor-title');
    const saveBtn = document.getElementById('announcement-save-btn');
    const originalTokenInput = document.getElementById('announcement-original-token');
    const startInput = document.getElementById('announcement-start');
    const endInput = document.getElementById('announcement-end');
    const messageInput = document.getElementById('announcement-message');
    const openOnLoad = editorModal && editorModal.dataset.openOnLoad === '1';
    let editorOpen = false;

    if (!editorModal || !newBtn || !editorForm || !titleEl || !saveBtn || !originalTokenInput || !startInput || !endInput || !messageInput) {
        return;
    }

    const applyEditorMode = function () {
        const isEdit = originalTokenInput.value.trim() !== '';
        titleEl.textContent = isEdit ? 'Edit Announcement' : 'New Announcement';
        saveBtn.textContent = isEdit ? 'Save changes' : 'Create announcement';
    };

    const setEditorState = function (open) {
        editorOpen = !!open;
        editorModal.classList.toggle('is-open', editorOpen);
        editorModal.hidden = !editorOpen;
        editorModal.setAttribute('aria-hidden', editorOpen ? 'false' : 'true');
        document.body.classList.toggle('catalogue-modal-open', editorOpen);
    };

    const openEditorModal = function () {
        if (editorOpen) {
            return;
        }
        setEditorState(true);
    };

    const closeEditorModal = function () {
        if (!editorOpen) {
            return;
        }
        setEditorState(false);
    };

    const setEditorForNew = function () {
        originalTokenInput.value = '';
        setDatetimeInputValue(startInput, '');
        setDatetimeInputValue(endInput, '');
        messageInput.value = '';
        applyEditorMode();
    };

    const setDatetimeInputValue = function (input, value) {
        if (!input) {
            return;
        }

        const nextValue = String(value || '');
        if (input._flatpickr) {
            if (nextValue === '') {
                input._flatpickr.clear(false);
            } else {
                input._flatpickr.setDate(nextValue, false, input._flatpickr.config.dateFormat);
            }
            return;
        }

        input.value = nextValue;
    };

    const setEditorForRow = function (row) {
        const tokenEl = row.querySelector('[data-announcement-token]');
        const startEl = row.querySelector('[data-announcement-start]');
        const endEl = row.querySelector('[data-announcement-end]');
        const messageEl = row.querySelector('[data-announcement-message]');

        if (!tokenEl || !startEl || !endEl || !messageEl) {
            return;
        }

        originalTokenInput.value = tokenEl.value || '';
        setDatetimeInputValue(startInput, startEl.value || '');
        setDatetimeInputValue(endInput, endEl.value || '');
        messageInput.value = messageEl.value || '';
        applyEditorMode();
    };

    newBtn.addEventListener('click', function () {
        setEditorForNew();
        openEditorModal();
    });

    document.querySelectorAll('[data-announcement-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const row = button.closest('tr');
            if (!row) {
                return;
            }
            setEditorForRow(row);
            openEditorModal();
        });
    });

    editorModal.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }
        if (target.closest('[data-announcement-editor-close]')) {
            closeEditorModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !editorOpen) {
            return;
        }
        event.preventDefault();
        closeEditorModal();
    });

    applyEditorMode();
    if (openOnLoad) {
        openEditorModal();
    } else {
        setEditorState(false);
    }
});
</script>
</body>
</html>
