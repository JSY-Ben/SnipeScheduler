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

$formatRow = static function (array $announcement) use ($tz, $dateInputFormat): array {
    return [
        'start' => app_announcement_format_datetime_for_input((int)$announcement['start_ts'], $tz, $dateInputFormat),
        'end' => app_announcement_format_datetime_for_input((int)$announcement['end_ts'], $tz, $dateInputFormat),
        'message' => (string)($announcement['message'] ?? ''),
    ];
};

$formRows = array_map($formatRow, $storedAnnouncements);
if (empty($formRows)) {
    $formRows[] = ['start' => '', 'end' => '', 'message' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'save'));
    $starts = $_POST['announcement_start'] ?? [];
    $ends = $_POST['announcement_end'] ?? [];
    $messagesRaw = $_POST['announcement_message'] ?? [];

    $starts = is_array($starts) ? $starts : [];
    $ends = is_array($ends) ? $ends : [];
    $messagesRaw = is_array($messagesRaw) ? $messagesRaw : [];

    $newConfig = $config;
    $newApp = is_array($newConfig['app'] ?? null) ? $newConfig['app'] : [];
    $normalizedAnnouncements = [];
    $formRows = [];

    if ($action === 'clear') {
        $normalizedAnnouncements = [];
        $formRows = [['start' => '', 'end' => '', 'message' => '']];
    } else {
        $rowCount = max(count($starts), count($ends), count($messagesRaw));

        for ($i = 0; $i < $rowCount; $i++) {
            $startRaw = trim((string)($starts[$i] ?? ''));
            $endRaw = trim((string)($ends[$i] ?? ''));
            $messageRaw = trim((string)($messagesRaw[$i] ?? ''));

            $formRows[] = [
                'start' => $startRaw,
                'end' => $endRaw,
                'message' => $messageRaw,
            ];

            if ($startRaw === '' && $endRaw === '' && $messageRaw === '') {
                continue;
            }

            $rowNumber = $i + 1;
            if ($messageRaw === '') {
                $errors[] = 'Announcement ' . $rowNumber . ': message is required.';
                continue;
            }
            if ($startRaw === '' || $endRaw === '') {
                $errors[] = 'Announcement ' . $rowNumber . ': start and end date/time are required.';
                continue;
            }

            $startDt = app_announcement_parse_datetime_input($startRaw, $tz);
            $endDt = app_announcement_parse_datetime_input($endRaw, $tz);
            if (!$startDt || !$endDt) {
                $errors[] = 'Announcement ' . $rowNumber . ': please provide valid start and end date/time values.';
                continue;
            }
            if ($endDt->getTimestamp() <= $startDt->getTimestamp()) {
                $errors[] = 'Announcement ' . $rowNumber . ': end date/time must be after start date/time.';
                continue;
            }

            $normalized = app_announcement_normalize_entry([
                'message' => $messageRaw,
                'start_ts' => $startDt->getTimestamp(),
                'end_ts' => $endDt->getTimestamp(),
            ], $tz);
            if (!$normalized) {
                $errors[] = 'Announcement ' . $rowNumber . ': could not normalize this announcement.';
                continue;
            }
            $normalizedAnnouncements[] = $normalized;
        }

        if (empty($formRows)) {
            $formRows[] = ['start' => '', 'end' => '', 'message' => ''];
        }
    }

    if (empty($errors)) {
        $seen = [];
        $uniqueAnnouncements = [];
        foreach ($normalizedAnnouncements as $item) {
            $token = (string)($item['token'] ?? '');
            if ($token !== '' && isset($seen[$token])) {
                continue;
            }
            if ($token !== '') {
                $seen[$token] = true;
            }
            $uniqueAnnouncements[] = $item;
        }

        usort($uniqueAnnouncements, static function (array $a, array $b): int {
            if ($a['start_ts'] !== $b['start_ts']) {
                return $a['start_ts'] <=> $b['start_ts'];
            }
            if ($a['end_ts'] !== $b['end_ts']) {
                return $a['end_ts'] <=> $b['end_ts'];
            }
            return strcmp($a['message'], $b['message']);
        });

        $newApp['announcements'] = array_map(static function (array $item): array {
            return [
                'message' => (string)$item['message'],
                'start_ts' => (int)$item['start_ts'],
                'end_ts' => (int)$item['end_ts'],
                'start_datetime' => (string)$item['start_datetime'],
                'end_datetime' => (string)$item['end_datetime'],
            ];
        }, $uniqueAnnouncements);

        $firstAnnouncement = $uniqueAnnouncements[0] ?? null;
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
        } else {
            $savedCount = count($uniqueAnnouncements);
            if ($savedCount === 0) {
                $messages[] = 'All announcements cleared.';
            } elseif ($savedCount === 1) {
                $messages[] = '1 announcement saved.';
            } else {
                $messages[] = $savedCount . ' announcements saved.';
            }

            $config = $newConfig;
            $appCfg = $newApp;
            $storedAnnouncements = app_announcements_from_app_config($appCfg, $tz);
            $formRows = array_map($formatRow, $storedAnnouncements);
            if (empty($formRows)) {
                $formRows[] = ['start' => '', 'end' => '', 'message' => ''];
            }
        }
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
        'start' => app_format_datetime($startTs, $config, $tz),
        'end' => app_format_datetime($endTs, $config, $tz),
        'status' => $status,
        'message' => (string)($item['message'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Announcements - SnipeScheduler</title>
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
        </ul>

        <form method="post" action="announcements.php" class="row g-3 settings-form" id="announcements-form">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Announcement Entries</h5>
                        <p class="text-muted small mb-3">
                            Add one or more announcements. Users will see all active announcements in one modal when opening the catalogue.
                        </p>

                        <div id="announcement-rows" class="d-grid gap-3">
                            <?php foreach ($formRows as $idx => $row): ?>
                                <div class="announcement-editor__row border rounded-3 p-3" data-announcement-row>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="small text-muted fw-semibold text-uppercase">Announcement <span data-announcement-row-index><?= (int)($idx + 1) ?></span></div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-announcement-row-remove>Remove</button>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Start date/time</label>
                                            <input type="datetime-local"
                                                   name="announcement_start[]"
                                                   class="form-control"
                                                   step="<?= $dateInputStep ?>"
                                                   value="<?= h((string)$row['start']) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">End date/time</label>
                                            <input type="datetime-local"
                                                   name="announcement_end[]"
                                                   class="form-control"
                                                   step="<?= $dateInputStep ?>"
                                                   value="<?= h((string)$row['end']) ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Message</label>
                                            <textarea name="announcement_message[]"
                                                      rows="4"
                                                      class="form-control"
                                                      placeholder="Enter the announcement shown on catalogue load."><?= h((string)$row['message']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-3 d-flex justify-content-start">
                            <button type="button" id="announcement-add-row" class="btn btn-outline-secondary btn-sm">Add announcement</button>
                        </div>
                        <div class="form-text mt-2">Time zone: <?= h((string)($appCfg['timezone'] ?? 'Europe/Jersey')) ?>.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
                <button type="submit" name="action" value="clear" class="btn btn-outline-secondary">Clear all announcements</button>
                <button type="submit" name="action" value="save" class="btn btn-primary">Save announcements</button>
            </div>
        </form>

        <div class="card mt-3">
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

<template id="announcement-row-template">
    <div class="announcement-editor__row border rounded-3 p-3" data-announcement-row>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="small text-muted fw-semibold text-uppercase">Announcement <span data-announcement-row-index></span></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-announcement-row-remove>Remove</button>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Start date/time</label>
                <input type="datetime-local"
                       name="announcement_start[]"
                       class="form-control"
                       step="<?= $dateInputStep ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">End date/time</label>
                <input type="datetime-local"
                       name="announcement_end[]"
                       class="form-control"
                       step="<?= $dateInputStep ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Message</label>
                <textarea name="announcement_message[]"
                          rows="4"
                          class="form-control"
                          placeholder="Enter the announcement shown on catalogue load."></textarea>
            </div>
        </div>
    </div>
</template>

<?php layout_footer(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rowsContainer = document.getElementById('announcement-rows');
    const addBtn = document.getElementById('announcement-add-row');
    const template = document.getElementById('announcement-row-template');

    if (!rowsContainer || !addBtn || !template) {
        return;
    }

    const renumberRows = function () {
        const rows = Array.from(rowsContainer.querySelectorAll('[data-announcement-row]'));
        rows.forEach(function (row, index) {
            const label = row.querySelector('[data-announcement-row-index]');
            if (label) {
                label.textContent = String(index + 1);
            }
        });
        rows.forEach(function (row) {
            const removeBtn = row.querySelector('[data-announcement-row-remove]');
            if (removeBtn) {
                removeBtn.disabled = rows.length <= 1;
            }
        });
    };

    const addRow = function () {
        const fragment = template.content.cloneNode(true);
        rowsContainer.appendChild(fragment);
        renumberRows();
    };

    addBtn.addEventListener('click', function () {
        addRow();
    });

    rowsContainer.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const removeBtn = target.closest('[data-announcement-row-remove]');
        if (!removeBtn) return;

        const row = removeBtn.closest('[data-announcement-row]');
        if (!row) return;

        row.remove();
        if (!rowsContainer.querySelector('[data-announcement-row]')) {
            addRow();
        }
        renumberRows();
    });

    renumberRows();
});
</script>
</body>
</html>
