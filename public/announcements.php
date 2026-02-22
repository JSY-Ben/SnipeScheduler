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

function announcement_parse_datetime_input(string $raw, ?DateTimeZone $tz = null): ?DateTime
{
    $text = trim($raw);
    if ($text === '') {
        return null;
    }

    $formats = [
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
    ];
    foreach ($formats as $format) {
        $dt = $tz
            ? DateTime::createFromFormat('!' . $format, $text, $tz)
            : DateTime::createFromFormat('!' . $format, $text);
        if (!$dt) {
            continue;
        }
        $lastErrors = DateTime::getLastErrors();
        if (is_array($lastErrors) && ((int)($lastErrors['warning_count'] ?? 0) > 0 || (int)($lastErrors['error_count'] ?? 0) > 0)) {
            continue;
        }
        return $dt;
    }

    return app_parse_datetime_value($text, $tz);
}

function announcement_format_datetime_for_input(int $timestamp, ?DateTimeZone $tz, string $format): string
{
    if ($timestamp <= 0) {
        return '';
    }
    $dt = new DateTime('@' . $timestamp);
    if ($tz) {
        $dt->setTimezone($tz);
    }
    return $dt->format($format);
}

$tz = app_get_timezone($config);
$timeFormat = app_get_time_format($config);
$dateInputFormat = strpos($timeFormat, 's') !== false ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
$dateInputStep = strpos($timeFormat, 's') !== false ? 1 : 60;

$appCfg = is_array($config['app'] ?? null) ? $config['app'] : [];
$storedMessage = trim((string)($appCfg['announcement_message'] ?? ''));
$storedStartTs = max(0, (int)($appCfg['announcement_start_ts'] ?? 0));
$storedEndTs = max(0, (int)($appCfg['announcement_end_ts'] ?? 0));

if ($storedStartTs <= 0) {
    $legacyStartRaw = trim((string)($appCfg['announcement_start_datetime'] ?? ''));
    $legacyStart = $legacyStartRaw !== '' ? announcement_parse_datetime_input($legacyStartRaw, $tz) : null;
    if ($legacyStart instanceof DateTime) {
        $storedStartTs = $legacyStart->getTimestamp();
    }
}
if ($storedEndTs <= 0) {
    $legacyEndRaw = trim((string)($appCfg['announcement_end_datetime'] ?? ''));
    $legacyEnd = $legacyEndRaw !== '' ? announcement_parse_datetime_input($legacyEndRaw, $tz) : null;
    if ($legacyEnd instanceof DateTime) {
        $storedEndTs = $legacyEnd->getTimestamp();
    }
}

$formMessage = $storedMessage;
$formStartRaw = announcement_format_datetime_for_input($storedStartTs, $tz, $dateInputFormat);
$formEndRaw = announcement_format_datetime_for_input($storedEndTs, $tz, $dateInputFormat);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'save'));
    $formMessage = trim((string)($_POST['announcement_message'] ?? ''));
    $formStartRaw = trim((string)($_POST['announcement_start'] ?? ''));
    $formEndRaw = trim((string)($_POST['announcement_end'] ?? ''));
    $newConfig = $config;
    $newApp = is_array($newConfig['app'] ?? null) ? $newConfig['app'] : [];
    $statusMessage = '';

    if ($action === 'clear' || ($formMessage === '' && $formStartRaw === '' && $formEndRaw === '')) {
        $newApp['announcement_message'] = '';
        $newApp['announcement_start_ts'] = 0;
        $newApp['announcement_end_ts'] = 0;
        $newApp['announcement_start_datetime'] = '';
        $newApp['announcement_end_datetime'] = '';
        $statusMessage = 'Announcement cleared.';
        $formMessage = '';
        $formStartRaw = '';
        $formEndRaw = '';
    } else {
        if ($formMessage === '') {
            $errors[] = 'Announcement message is required.';
        }
        if ($formStartRaw === '' || $formEndRaw === '') {
            $errors[] = 'Announcement start and end are required.';
        }

        $startDt = announcement_parse_datetime_input($formStartRaw, $tz);
        $endDt = announcement_parse_datetime_input($formEndRaw, $tz);
        if (!$startDt || !$endDt) {
            $errors[] = 'Please provide a valid start and end date/time.';
        } elseif ($endDt->getTimestamp() <= $startDt->getTimestamp()) {
            $errors[] = 'Announcement end date/time must be after the start date/time.';
        } else {
            $newApp['announcement_message'] = $formMessage;
            $newApp['announcement_start_ts'] = $startDt->getTimestamp();
            $newApp['announcement_end_ts'] = $endDt->getTimestamp();
            $newApp['announcement_start_datetime'] = $startDt->format('Y-m-d H:i:s');
            $newApp['announcement_end_datetime'] = $endDt->format('Y-m-d H:i:s');
            $statusMessage = 'Announcement saved.';
            $formStartRaw = announcement_format_datetime_for_input($startDt->getTimestamp(), $tz, $dateInputFormat);
            $formEndRaw = announcement_format_datetime_for_input($endDt->getTimestamp(), $tz, $dateInputFormat);
        }
    }

    if (empty($errors)) {
        $newConfig['app'] = $newApp;
        $content = layout_build_config_file($newConfig, $definedValues);
        if (!is_dir(CONFIG_PATH)) {
            @mkdir(CONFIG_PATH, 0755, true);
        }
        if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
            $errors[] = 'Could not write config.php. Check file permissions on the config/ directory.';
        } else {
            $messages[] = $statusMessage;
            $config = $newConfig;
            $appCfg = $newApp;
            $storedMessage = trim((string)($appCfg['announcement_message'] ?? ''));
            $storedStartTs = max(0, (int)($appCfg['announcement_start_ts'] ?? 0));
            $storedEndTs = max(0, (int)($appCfg['announcement_end_ts'] ?? 0));
        }
    }
}

$announcementConfigured = ($storedMessage !== '' && $storedStartTs > 0 && $storedEndTs > $storedStartTs);
$nowTs = time();
$announcementActive = $announcementConfigured && $nowTs >= $storedStartTs && $nowTs <= $storedEndTs;
$rangeDisplay = $announcementConfigured
    ? app_format_datetime($storedStartTs, $config, $tz) . ' to ' . app_format_datetime($storedEndTs, $config, $tz)
    : 'No announcement scheduled.';
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

        <form method="post" action="announcements.php" class="row g-3 settings-form">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Catalogue Announcement</h5>
                        <p class="text-muted small mb-3">
                            Users will see this message as a modal when the catalogue loads during the selected date/time window.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="announcement_start">Start date/time</label>
                                <input type="datetime-local"
                                       name="announcement_start"
                                       id="announcement_start"
                                       class="form-control"
                                       step="<?= $dateInputStep ?>"
                                       value="<?= h($formStartRaw) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="announcement_end">End date/time</label>
                                <input type="datetime-local"
                                       name="announcement_end"
                                       id="announcement_end"
                                       class="form-control"
                                       step="<?= $dateInputStep ?>"
                                       value="<?= h($formEndRaw) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="announcement_message">Message</label>
                                <textarea name="announcement_message"
                                          id="announcement_message"
                                          rows="6"
                                          class="form-control"
                                          placeholder="Enter the announcement text shown on catalogue load."><?= h($formMessage) ?></textarea>
                                <div class="form-text">Time zone: <?= h((string)($appCfg['timezone'] ?? 'Europe/Jersey')) ?>. Leave all fields blank and save to clear.</div>
                            </div>
                            <div class="col-12">
                                <div class="alert <?= $announcementActive ? 'alert-info' : 'alert-secondary' ?> mb-0">
                                    <strong>Status:</strong>
                                    <?= $announcementActive ? 'Active now' : ($announcementConfigured ? 'Scheduled' : 'Not configured') ?>
                                    <br>
                                    <strong>Range:</strong> <?= h($rangeDisplay) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
                <button type="submit" name="action" value="clear" class="btn btn-outline-secondary">Clear announcement</button>
                <button type="submit" name="action" value="save" class="btn btn-primary">Save announcement</button>
            </div>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
