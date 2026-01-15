<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$isStaff = !empty($currentUser['is_admin']);
$currentUserId = (string)($currentUser['id'] ?? '');

$from      = $_GET['from'] ?? ($_POST['from'] ?? '');
$embedded  = $from === 'reservations';
$fromMy    = $from === 'my_bookings';
$pageBase  = $embedded ? 'reservations.php' : ($fromMy ? 'my_bookings.php' : 'staff_reservations.php');
$baseQuery = $embedded ? ['tab' => 'history'] : ($fromMy ? ['tab' => 'reservations'] : []);

if (!$isStaff && !$embedded && !$fromMy) {
    $fromMy = true;
    $pageBase = 'my_bookings.php';
    $baseQuery = ['tab' => 'reservations'];
}

$actionUrl = $pageBase;
if (!empty($baseQuery)) {
    $actionUrl .= '?' . http_build_query($baseQuery);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'model_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $resp = snipeit_request('GET', 'models', [
            'search' => $q,
            'limit'  => 20,
        ]);
        $rows = $resp['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            if (empty($row['requestable'])) {
                continue;
            }
            $mid = (int)($row['id'] ?? 0);
            $name = $row['name'] ?? '';
            if ($mid <= 0 || $name === '') {
                continue;
            }
            $manu = $row['manufacturer']['name'] ?? '';
            $label = $manu !== '' ? $name . ' — ' . $manu : $name;
            $results[] = [
                'id'    => $mid,
                'name'  => $name,
                'label' => $label,
                'image' => $row['image'] ?? '',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => []]);
    }
    exit;
}

function datetime_local_value(?string $isoDatetime): string
{
    if (!$isoDatetime) {
        return '';
    }
    $ts = strtotime($isoDatetime);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

$errors = [];
$addModelId = 0;
$addQtyRaw = '';
$addModelLabel = '';
$addModelImage = '';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

if (!$isStaff) {
    $reservationUserId = (string)($reservation['user_id'] ?? '');
    if ($reservationUserId === '' || $reservationUserId !== $currentUserId) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

if (($reservation['status'] ?? '') !== 'pending') {
    http_response_code(403);
    echo 'Only pending reservations can be edited.';
    exit;
}

try {
    $itemsStmt = $pdo->prepare('
        SELECT model_id, quantity, model_name_cache
        FROM reservation_items
        WHERE reservation_id = :id
        ORDER BY model_id
    ');
    $itemsStmt->execute([':id' => $id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $items = [];
    $errors[] = 'Error loading reservation items: ' . $e->getMessage();
}

$displayItems = $items;
$displayQty = [];
$modelImageMap = [];
if (!empty($items)) {
    $uniqueModels = [];
    foreach ($items as $item) {
        $mid = (int)($item['model_id'] ?? 0);
        if ($mid > 0) {
            $uniqueModels[$mid] = true;
        }
    }
    foreach (array_keys($uniqueModels) as $mid) {
        try {
            $model = get_model($mid);
            $modelImageMap[$mid] = $model['image'] ?? '';
        } catch (Exception $e) {
            $modelImageMap[$mid] = '';
        }
    }
}
foreach ($items as $item) {
    $mid = (int)($item['model_id'] ?? 0);
    if ($mid > 0) {
        $displayQty[$mid] = (int)($item['quantity'] ?? 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startRaw = $_POST['start_datetime'] ?? '';
    $endRaw   = $_POST['end_datetime'] ?? '';
    $qtyInput = $_POST['qty'] ?? [];
    $addModelIds = $_POST['add_model_id'] ?? [];
    $addQtyList  = $_POST['add_qty'] ?? [];
    $addLabels   = $_POST['add_model_label'] ?? [];
    $addImages   = $_POST['add_model_image'] ?? [];

    $startTs = strtotime($startRaw);
    $endTs   = strtotime($endRaw);

    if ($startTs === false || $endTs === false) {
        $errors[] = 'Start and end date/time must be valid.';
    } else {
        $start = date('Y-m-d H:i:s', $startTs);
        $end   = date('Y-m-d H:i:s', $endTs);
        if ($end <= $start) {
            $errors[] = 'End time must be after start time.';
        }
    }

    $existingModels = [];
    $modelNameMap = [];
    $updatedItems = [];

    foreach ($items as $item) {
        $mid = (int)($item['model_id'] ?? 0);
        $qty = isset($qtyInput[$mid]) ? (int)$qtyInput[$mid] : (int)($item['quantity'] ?? 0);

        if ($mid <= 0) {
            continue;
        }

        if ($qty < 0) {
            $errors[] = 'Quantities must be zero or greater.';
            break;
        }

        $existingModels[$mid] = true;
        $modelNameMap[$mid] = $item['model_name_cache'] ?? ('Model #' . $mid);
        $updatedItems[$mid] = $qty;
        $displayQty[$mid] = $qty;
    }

    $addCount = max(count($addModelIds), count($addQtyList));
    for ($i = 0; $i < $addCount; $i++) {
        $rawId = $addModelIds[$i] ?? '';
        $rawQty = $addQtyList[$i] ?? '';
        $addModelId = (int)$rawId;
        $addQtyRaw  = trim((string)$rawQty);
        $addQtyInt  = (int)$addQtyRaw;
        $addModelLabel = (string)($addLabels[$i] ?? '');
        $addModelImage = (string)($addImages[$i] ?? '');

        if ($addModelId <= 0 && $addQtyInt <= 0) {
            continue;
        }

        if ($addModelId <= 0 || $addQtyInt <= 0) {
            $errors[] = 'Select a model and quantity to add.';
            continue;
        }

        if ($addModelLabel === '') {
            try {
                $addModel = get_model($addModelId);
                if (empty($addModel['id'])) {
                    throw new Exception('Model not found in Snipe-IT.');
                }
                $addModelLabel = $addModel['name'] ?? ('Model #' . $addModelId);
                $addModelImage = $addModel['image'] ?? '';
            } catch (Exception $e) {
                $errors[] = 'Unable to add model: ' . $e->getMessage();
                continue;
            }
        }

        $modelNameMap[$addModelId] = $addModelLabel;
        $modelImageMap[$addModelId] = $addModelImage;
        $updatedItems[$addModelId] = ($updatedItems[$addModelId] ?? 0) + $addQtyInt;
        $displayQty[$addModelId] = $updatedItems[$addModelId];

        if (empty($existingModels[$addModelId])) {
            $displayItems[] = [
                'model_id' => $addModelId,
                'quantity' => $updatedItems[$addModelId],
                'model_name_cache' => $addModelLabel,
            ];
        }
    }

    if (empty($errors)) {
        foreach ($updatedItems as $mid => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $modelName = $modelNameMap[$mid] ?? ('Model #' . $mid);

            $sql = '
                SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
                FROM reservation_items ri
                JOIN reservations r ON r.id = ri.reservation_id
                WHERE ri.model_id = :model_id
                  AND r.status IN (\'pending\',\'confirmed\')
                  AND r.id <> :res_id
                  AND (r.start_datetime < :end AND r.end_datetime > :start)
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':model_id' => $mid,
                ':res_id'   => $id,
                ':start'    => $start,
                ':end'      => $end,
            ]);
            $row = $stmt->fetch();
            $existingBooked = $row ? (int)$row['booked_qty'] : 0;

            $totalRequestable = count_requestable_assets_by_model($mid);
            $activeCheckedOut = count_checked_out_assets_by_model($mid);
            $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

            if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
                $errors[] = 'Not enough units available for "' . $modelName . '" in that time period.';
            }
        }
    }

    $totalQty = 0;
    foreach ($updatedItems as $qty) {
        if ($qty > 0) {
            $totalQty += $qty;
        }
    }

    if (empty($errors) && $totalQty <= 0) {
        $errors[] = 'Reservation must include at least one item.';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();

        try {
            $updateRes = $pdo->prepare('
                UPDATE reservations
                SET start_datetime = :start,
                    end_datetime = :end
                WHERE id = :id
            ');
            $updateRes->execute([
                ':start' => $start,
                ':end'   => $end,
                ':id'    => $id,
            ]);

            $updateItem = $pdo->prepare('
                UPDATE reservation_items
                SET quantity = :qty
                WHERE reservation_id = :res_id
                  AND model_id = :model_id
            ');
            $insertItem = $pdo->prepare('
                INSERT INTO reservation_items (
                    reservation_id, model_id, model_name_cache, quantity
                ) VALUES (
                    :res_id, :model_id, :model_name, :qty
                )
            ');
            $deleteItem = $pdo->prepare('
                DELETE FROM reservation_items
                WHERE reservation_id = :res_id
                  AND model_id = :model_id
            ');

            foreach ($updatedItems as $mid => $qty) {
                if ($qty <= 0) {
                    $deleteItem->execute([
                        ':res_id'   => $id,
                        ':model_id' => $mid,
                    ]);
                } elseif (!empty($existingModels[$mid])) {
                    $updateItem->execute([
                        ':qty'      => $qty,
                        ':res_id'   => $id,
                        ':model_id' => $mid,
                    ]);
                } else {
                    $insertItem->execute([
                        ':res_id'     => $id,
                        ':model_id'   => $mid,
                        ':model_name' => $modelNameMap[$mid] ?? ('Model #' . $mid),
                        ':qty'        => $qty,
                    ]);
                }
            }

            $pdo->commit();

            $redirect = $actionUrl;
            $glue = strpos($redirect, '?') === false ? '?' : '&';
            header('Location: ' . $redirect . $glue . 'updated=' . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Unable to update reservation: ' . $e->getMessage();
        }
    }
}

$startValue = datetime_local_value($reservation['start_datetime'] ?? '');
$endValue   = datetime_local_value($reservation['end_datetime'] ?? '');

$active = $embedded ? 'reservations.php' : ($fromMy ? 'my_bookings.php' : 'staff_reservations.php');
$ajaxBase = 'reservation_edit.php?id=' . (int)$id;
if ($from !== '') {
    $ajaxBase .= '&from=' . urlencode($from);
}
$preserveAddRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $addModelIds = $_POST['add_model_id'] ?? [];
    $addQtyList  = $_POST['add_qty'] ?? [];
    $addLabels   = $_POST['add_model_label'] ?? [];
    $addImages   = $_POST['add_model_image'] ?? [];
    $count = max(count($addModelIds), count($addQtyList));
    for ($i = 0; $i < $count; $i++) {
        $preserveAddRows[] = [
            'id'    => (string)($addModelIds[$i] ?? ''),
            'label' => (string)($addLabels[$i] ?? ''),
            'image' => (string)($addImages[$i] ?? ''),
            'qty'   => (string)($addQtyList[$i] ?? ''),
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Reservation #<?= (int)$id ?></title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Edit Reservation #<?= (int)$id ?></h1>
            <div class="page-subtitle">
                Update dates and quantities for a pending reservation.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <?php $backLabel = $fromMy ? 'Back to My Reservations' : 'Back to reservations'; ?>
                <a href="<?= h($actionUrl) ?>" class="btn btn-outline-secondary btn-sm"><?= h($backLabel) ?></a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card">
            <div class="card-body">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php if ($from !== ''): ?>
                    <input type="hidden" name="from" value="<?= h($from) ?>">
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Start date/time</label>
                        <input type="datetime-local"
                               name="start_datetime"
                               class="form-control"
                               value="<?= h($startValue) ?>"
                               required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End date/time</label>
                        <input type="datetime-local"
                               name="end_datetime"
                               class="form-control"
                               value="<?= h($endValue) ?>"
                               required>
                    </div>
                </div>

                <?php if (empty($displayItems)): ?>
                    <div class="alert alert-warning mb-0">
                        No items are attached to this reservation.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 200px;">Image</th>
                                    <th>Model</th>
                                    <th style="width: 120px;">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($displayItems as $item): ?>
                                    <?php
                                        $mid = (int)($item['model_id'] ?? 0);
                                        $qty = $displayQty[$mid] ?? (int)($item['quantity'] ?? 0);
                                        $name = $item['model_name_cache'] ?? ('Model #' . $mid);
                                        $imagePath = $modelImageMap[$mid] ?? '';
                                        $proxiedImage = $imagePath !== ''
                                            ? 'image_proxy.php?src=' . urlencode($imagePath)
                                            : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($proxiedImage !== ''): ?>
                                                <img src="<?= h($proxiedImage) ?>"
                                                     alt="<?= h($name) ?>"
                                                     class="reservation-model-image">
                                            <?php else: ?>
                                                <div class="reservation-model-image reservation-model-image--placeholder">
                                                    No image
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($name) ?></td>
                                        <td>
                                            <input type="number"
                                                   class="form-control form-control-sm"
                                                   name="qty[<?= $mid ?>]"
                                                   min="0"
                                                   value="<?= $qty ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="row g-3 align-items-end mt-2">
                    <div class="col-12">
                        <label class="form-label">Add models (optional)</label>
                        <div id="add-model-rows">
                            <?php if (!empty($preserveAddRows)): ?>
                                <?php foreach ($preserveAddRows as $row): ?>
                                    <div class="row g-2 align-items-end add-model-row">
                                        <div class="col-md-6">
                                            <div class="position-relative model-autocomplete-wrapper">
                                                <input type="text"
                                                       class="form-control model-autocomplete"
                                                       placeholder="Search by model name..."
                                                       autocomplete="off"
                                                       value="<?= h($row['label']) ?>">
                                                <input type="hidden"
                                                       name="add_model_id[]"
                                                       class="model-autocomplete-id"
                                                       value="<?= h($row['id']) ?>">
                                                <input type="hidden"
                                                       name="add_model_label[]"
                                                       class="model-autocomplete-label"
                                                       value="<?= h($row['label']) ?>">
                                                <input type="hidden"
                                                       name="add_model_image[]"
                                                       class="model-autocomplete-image"
                                                       value="<?= h($row['image']) ?>">
                                                <div class="list-group position-absolute w-100 shadow-sm"
                                                     data-model-suggestions
                                                     style="display: none; z-index: 20;"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number"
                                                   name="add_qty[]"
                                                   class="form-control"
                                                   min="1"
                                                   placeholder="Qty"
                                                   value="<?= h($row['qty']) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-outline-secondary w-100 add-model-row-remove">
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="row g-2 align-items-end add-model-row">
                                    <div class="col-md-6">
                                        <div class="position-relative model-autocomplete-wrapper">
                                            <input type="text"
                                                   class="form-control model-autocomplete"
                                                   placeholder="Search by model name..."
                                                   autocomplete="off">
                                            <input type="hidden"
                                                   name="add_model_id[]"
                                                   class="model-autocomplete-id">
                                            <input type="hidden"
                                                   name="add_model_label[]"
                                                   class="model-autocomplete-label">
                                            <input type="hidden"
                                                   name="add_model_image[]"
                                                   class="model-autocomplete-image">
                                            <div class="list-group position-absolute w-100 shadow-sm"
                                                 data-model-suggestions
                                                 style="display: none; z-index: 20;"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number"
                                               name="add_qty[]"
                                               class="form-control"
                                               min="1"
                                               placeholder="Qty">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-secondary w-100 add-model-row-remove">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-outline-primary" id="add-model-row-btn">
                                Add another model
                            </button>
                        </div>
                        <div class="form-text">Add one or more models before saving.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="<?= h($actionUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
<script>
(function () {
    function initAutocomplete(wrapper) {
        const input = wrapper.querySelector('.model-autocomplete');
        const hidden = wrapper.querySelector('.model-autocomplete-id');
        const label = wrapper.querySelector('.model-autocomplete-label');
        const image = wrapper.querySelector('.model-autocomplete-image');
        const list = wrapper.querySelector('[data-model-suggestions]');
        if (!input || !hidden || !label || !image || !list) return;

        let timer = null;
        let lastQuery = '';

        input.addEventListener('input', () => {
            const q = input.value.trim();
            hidden.value = '';
            label.value = '';
            image.value = '';
            if (q.length < 2) {
                hideSuggestions();
                return;
            }
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => fetchSuggestions(q), 250);
        });

        input.addEventListener('blur', () => {
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('<?= h($ajaxBase) ?>&ajax=model_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
                    renderSuggestions(data.results || []);
                })
                .catch(() => {
                    renderSuggestions([]);
                });
        }

        function renderSuggestions(items) {
            list.innerHTML = '';
            if (!items || !items.length) {
                hideSuggestions();
                return;
            }

            items.forEach((item) => {
                const labelText = item.label || item.name || '';
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.textContent = labelText;
                btn.dataset.id = item.id || '';
                btn.dataset.label = labelText;
                btn.dataset.image = item.image || '';

                btn.addEventListener('click', () => {
                    input.value = btn.dataset.label;
                    hidden.value = btn.dataset.id;
                    label.value = btn.dataset.label;
                    image.value = btn.dataset.image;
                    hideSuggestions();
                    input.focus();
                });

                list.appendChild(btn);
            });

            list.style.display = 'block';
        }

        function hideSuggestions() {
            list.style.display = 'none';
            list.innerHTML = '';
        }
    }

    document.querySelectorAll('.model-autocomplete-wrapper').forEach(initAutocomplete);

    const addBtn = document.getElementById('add-model-row-btn');
    const addRows = document.getElementById('add-model-rows');
    if (addBtn && addRows) {
        addBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-end add-model-row';
            row.innerHTML = `
                <div class="col-md-6">
                    <div class="position-relative model-autocomplete-wrapper">
                        <input type="text"
                               class="form-control model-autocomplete"
                               placeholder="Search by model name..."
                               autocomplete="off">
                        <input type="hidden"
                               name="add_model_id[]"
                               class="model-autocomplete-id">
                        <input type="hidden"
                               name="add_model_label[]"
                               class="model-autocomplete-label">
                        <input type="hidden"
                               name="add_model_image[]"
                               class="model-autocomplete-image">
                        <div class="list-group position-absolute w-100 shadow-sm"
                             data-model-suggestions
                             style="display: none; z-index: 20;"></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <input type="number"
                           name="add_qty[]"
                           class="form-control"
                           min="1"
                           placeholder="Qty">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100 add-model-row-remove">
                        Remove
                    </button>
                </div>
            `;
            addRows.appendChild(row);
            const wrapper = row.querySelector('.model-autocomplete-wrapper');
            if (wrapper) {
                initAutocomplete(wrapper);
            }
        });
    }

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('.add-model-row-remove');
        if (!btn) return;
        const row = btn.closest('.add-model-row');
        if (row) {
            row.remove();
        }
    });
})();
</script>
</body>
</html>
