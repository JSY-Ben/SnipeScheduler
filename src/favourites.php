<?php

function favourites_normalize_user_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }

    return $email;
}

function favourites_storage_available(PDO $pdo): bool
{
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;
    try {
        $pdo->query('SELECT 1 FROM user_favourite_models LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

/**
 * @return int[] model IDs favourited by the user
 */
function favourites_get_model_ids(PDO $pdo, string $userEmail): array
{
    $email = favourites_normalize_user_email($userEmail);
    if ($email === '' || !favourites_storage_available($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT model_id
            FROM user_favourite_models
            WHERE user_email = :email
            ORDER BY model_id ASC
        ");
        $stmt->execute([':email' => $email]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function favourites_set_model(PDO $pdo, string $userEmail, int $modelId, bool $isFavourite): void
{
    $email = favourites_normalize_user_email($userEmail);
    if ($email === '' || $modelId <= 0 || !favourites_storage_available($pdo)) {
        return;
    }

    if ($isFavourite) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_favourite_models (user_email, model_id)
            VALUES (:email, :model_id)
        ");
        $stmt->execute([
            ':email' => $email,
            ':model_id' => $modelId,
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM user_favourite_models
        WHERE user_email = :email
          AND model_id = :model_id
    ");
    $stmt->execute([
        ':email' => $email,
        ':model_id' => $modelId,
    ]);
}
