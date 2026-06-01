<?php
// Simple Snipe-IT user API response tester.
// Place behind your normal server/admin protections before exposing publicly.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

function test_page_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function test_page_pretty_json($value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json === false ? 'Could not encode response as JSON.' : $json;
}

$query = trim((string)($_GET['q'] ?? ''));
$lookupMode = (string)($_GET['mode'] ?? 'email');
$userId = (int)($_GET['user_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min(500, $limit));

$results = [];
$error = '';

if ($query !== '' || $userId > 0) {
    try {
        if ($userId > 0) {
            $endpoint = 'users/' . $userId;
            $params = [];
            $response = snipeit_request('GET', $endpoint, $params, false);
            $results[] = [
                'label' => 'GET /api/v1/' . $endpoint,
                'params' => $params,
                'response' => $response,
            ];
        } else {
            $params = [
                $lookupMode === 'search' ? 'search' : 'email' => $query,
                'limit' => $limit,
            ];
            $response = snipeit_request('GET', 'users', $params, false);
            $results[] = [
                'label' => 'GET /api/v1/users',
                'params' => $params,
                'response' => $response,
            ];

            $rows = isset($response['rows']) && is_array($response['rows']) ? $response['rows'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0) {
                    continue;
                }
                $detailEndpoint = 'users/' . $rowId;
                $detailResponse = snipeit_request('GET', $detailEndpoint, [], false);
                $results[] = [
                    'label' => 'GET /api/v1/' . $detailEndpoint,
                    'params' => [],
                    'response' => $detailResponse,
                ];
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Snipe-IT User API Test</title>
    <style>
        body {
            color: #1f2933;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
            margin: 24px;
            max-width: 1100px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
        }
        input,
        select,
        button {
            font: inherit;
            padding: 8px 10px;
        }
        .row {
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(220px, 1fr) 160px 110px 120px;
            margin-bottom: 16px;
        }
        .notice {
            background: #fff7e6;
            border: 1px solid #ffd591;
            border-radius: 6px;
            margin: 16px 0;
            padding: 12px;
        }
        .error {
            background: #fff1f0;
            border: 1px solid #ffa39e;
            border-radius: 6px;
            color: #a8071a;
            margin: 16px 0;
            padding: 12px;
        }
        .result {
            border: 1px solid #d9e2ec;
            border-radius: 6px;
            margin: 16px 0;
            overflow: hidden;
        }
        .result h2 {
            background: #f5f7fa;
            font-size: 16px;
            margin: 0;
            padding: 10px 12px;
        }
        pre {
            margin: 0;
            overflow: auto;
            padding: 12px;
            white-space: pre-wrap;
        }
        @media (max-width: 760px) {
            .row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <h1>Snipe-IT User API Test</h1>
    <p>This page uses the existing SnipeScheduler Snipe-IT configuration and bypasses the local API response cache.</p>

    <div class="notice">
        This diagnostic page can expose user data and raw API responses. Remove it or protect it after testing.
    </div>

    <form method="get">
        <div class="row">
            <div>
                <label for="q">Email or search text</label>
                <input id="q" name="q" type="text" value="<?= test_page_h($query) ?>" placeholder="user@example.com" style="width: 100%;">
            </div>
            <div>
                <label for="mode">Lookup mode</label>
                <select id="mode" name="mode" style="width: 100%;">
                    <option value="email" <?= $lookupMode === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="search" <?= $lookupMode === 'search' ? 'selected' : '' ?>>Search</option>
                </select>
            </div>
            <div>
                <label for="limit">Limit</label>
                <input id="limit" name="limit" type="number" min="1" max="500" value="<?= (int)$limit ?>" style="width: 100%;">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit">Run lookup</button>
            </div>
        </div>
        <div class="row" style="grid-template-columns: minmax(220px, 1fr) 120px;">
            <div>
                <label for="user_id">Or fetch exact Snipe-IT user ID</label>
                <input id="user_id" name="user_id" type="number" min="1" value="<?= $userId > 0 ? (int)$userId : '' ?>" style="width: 100%;">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit">Fetch user ID</button>
            </div>
        </div>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error"><?= test_page_h($error) ?></div>
    <?php endif; ?>

    <?php if (empty($results) && $error === ''): ?>
        <p>Enter an email/search term or a Snipe-IT user ID to see the raw API response.</p>
    <?php endif; ?>

    <?php foreach ($results as $result): ?>
        <section class="result">
            <h2><?= test_page_h((string)$result['label']) ?></h2>
            <pre><?= test_page_h(test_page_pretty_json([
                'params' => $result['params'],
                'response' => $result['response'],
            ])) ?></pre>
        </section>
    <?php endforeach; ?>
</body>
</html>
