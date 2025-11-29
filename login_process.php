<?php
// login_process.php
session_start();

require_once __DIR__ . '/db.php';
$config   = require __DIR__ . '/config.php';

$ldapCfg  = $config['ldap'];
$authCfg  = $config['auth'];
$appCfg   = $config['app'] ?? [];
$debugOn  = !empty($appCfg['debug']);

// Staff group CN from config only
$staffCn  = $authCfg['staff_group_cn'] ?? '';

/**
 * Polyfill ldap_escape (older PHP builds)
 */
if (!function_exists('ldap_escape')) {
    function ldap_escape(string $str): string
    {
        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\5c', '\2a', '\28', '\29', '\00'],
            $str
        );
    }
}

// ------------------------------------------------------------------
// Read input (EMAIL + password)
// ------------------------------------------------------------------
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email address and password.';
    header('Location: login.php');
    exit;
}

// ------------------------------------------------------------------
// Connect to LDAP
// ------------------------------------------------------------------
if (!empty($ldapCfg['ignore_cert'])) {
    putenv('LDAPTLS_REQCERT=never');
    if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    }
    if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
        ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0); // reset TLS context per request
    }
}

$ldap = @ldap_connect($ldapCfg['host']);
if (!$ldap) {
    $_SESSION['login_error'] = 'Login system is currently unavailable (cannot connect to LDAP).';
    header('Location: login.php');
    exit;
}

ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

// ------------------------------------------------------------------
// Service bind (bind as service account)
// ------------------------------------------------------------------
if (!@ldap_bind($ldap, $ldapCfg['bind_dn'], $ldapCfg['bind_password'])) {
    ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagMsg);
    error_log('LDAP service bind failed: ' . ldap_error($ldap) . ' (' . ($diagMsg ?? 'no detail') . ')');
    $_SESSION['login_error'] = $debugOn
        ? 'LDAP service bind failed: ' . ldap_error($ldap)
        : 'Login system is currently unavailable.';
    header('Location: login.php');
    exit;
}

// ------------------------------------------------------------------
// Find user by EMAIL
// ------------------------------------------------------------------
$filter = sprintf(
    '(&(objectClass=user)(mail=%s))',
    ldap_escape($email)
);

$attrs = [
    'distinguishedName',
    'givenName',
    'sn',
    'displayName',
    'mail',
    'memberOf',
    'sAMAccountName',
];

$search  = @ldap_search($ldap, $ldapCfg['base_dn'], $filter, $attrs);
$entries = $search ? ldap_get_entries($ldap, $search) : ['count' => 0];

if (($entries['count'] ?? 0) !== 1) {
    $_SESSION['login_error'] = 'Incorrect email address or password.';
    header('Location: login.php');
    exit;
}

$user   = $entries[0];
$userDn = $user['distinguishedname'][0] ?? null;

if (empty($userDn)) {
    $_SESSION['login_error'] = $debugOn
        ? 'LDAP: User DN not found for this account.'
        : 'Incorrect email address or password.';
    header('Location: login.php');
    exit;
}

// ------------------------------------------------------------------
// Bind as user (check password)
// ------------------------------------------------------------------
if (!@ldap_bind($ldap, $userDn, $password)) {
    ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagMsg);
    error_log('LDAP user bind failed for ' . $email . ': ' . ldap_error($ldap) . ' (' . ($diagMsg ?? 'no detail') . ')');
    $_SESSION['login_error'] = 'Incorrect email address or password.';
    header('Location: login.php');
    exit;
}

// ------------------------------------------------------------------
// Extract attributes from LDAP
// ------------------------------------------------------------------
$firstName = $user['givenname'][0]       ?? '';
$lastName  = $user['sn'][0]              ?? '';
$display   = $user['displayname'][0]     ?? '';
$mail      = $user['mail'][0]            ?? $email;
$sam       = $user['samaccountname'][0]  ?? '';

// Fallback name logic
if ($firstName === '' && $lastName === '' && $display !== '') {
    [$firstName, $lastName] = explode(' ', $display . ' ', 2);
}

if ($firstName === '' && $lastName === '') {
    $firstName = $mail;
}

$fullName = trim($firstName . ' ' . $lastName);
if ($fullName === '') {
    $fullName = $display !== '' ? $display : $mail;
}

// ------------------------------------------------------------------
// Staff check (LDAP group via config)
// ------------------------------------------------------------------
$isStaff = false;
if ($staffCn !== '' && !empty($user['memberof']) && is_array($user['memberof'])) {
    for ($i = 0; $i < ($user['memberof']['count'] ?? 0); $i++) {
        if (stripos($user['memberof'][$i], 'CN=' . $staffCn . ',') !== false) {
            $isStaff = true;
            break;
        }
    }
}

// ------------------------------------------------------------------
// Upsert into students table (schema: id, student_id, name, email, created_at)
// We key users by EMAIL only, and store full name in `name`.
// `student_id` must be UNIQUE, so we derive a stable numeric ID from email.
// ------------------------------------------------------------------
try {
    // Look up existing record by email
    $stmt = $pdo->prepare('SELECT * FROM students WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $mail]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update `name` only (email is the key)
        $update = $pdo->prepare("
            UPDATE students
               SET name = :name
             WHERE id = :id
        ");
        $update->execute([
            ':name' => $fullName,
            ':id'   => $existing['id'],
        ]);
        $userId = (int)$existing['id'];
    } else {
        // Create a stable numeric student_id from the email (for the UNIQUE constraint)
        // e.g. student_id = crc32(lower(email))
        $studentId = sprintf('%u', crc32(strtolower($mail)));

        $insert = $pdo->prepare("
            INSERT INTO students (student_id, name, email, created_at)
            VALUES (:student_id, :name, :email, NOW())
        ");
        $insert->execute([
            ':student_id' => $studentId,
            ':name'       => $fullName,
            ':email'      => $mail,
        ]);
        $userId = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    $_SESSION['login_error'] = $debugOn
        ? 'Login system is currently unavailable (database error): ' . $e->getMessage()
        : 'Login system is currently unavailable (database error).';
    header('Location: login.php');
    exit;
}

// ------------------------------------------------------------------
// Successful login – store full user info in SESSION only
// ------------------------------------------------------------------
$_SESSION['user'] = [
    'id'           => $userId,
    'email'        => $mail,
    'username'     => $sam,          // AD username, for display only
    'first_name'   => $firstName,
    'last_name'    => $lastName,
    'display_name' => $fullName,
    'is_admin'     => $isStaff,
];

ldap_unbind($ldap);

header('Location: index.php');
exit;
