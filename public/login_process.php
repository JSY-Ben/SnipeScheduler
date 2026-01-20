<?php
// login_process.php

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

session_start();

$config   = load_config();

$ldapCfg   = $config['ldap'] ?? [];
$googleCfg = $config['google_oauth'] ?? [];
$msCfg     = $config['microsoft_oauth'] ?? [];
$authCfg   = $config['auth'] ?? [];
$appCfg    = $config['app'] ?? [];
$debugOn   = !empty($appCfg['debug']);

$ldapEnabled   = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
$googleEnabled = !empty($authCfg['google_oauth_enabled']);
$msEnabled     = !empty($authCfg['microsoft_oauth_enabled']);

// Staff group CN(s) from config (string or array)
$normalizeList = static function ($raw): array {
    if (!is_array($raw)) {
        $raw = $raw !== '' ? [$raw] : [];
    }
    return array_values(array_filter(array_map('trim', $raw), 'strlen'));
};
$normalizeEmailList = static function ($raw): array {
    if (!is_array($raw)) {
        $raw = [];
    }
    return array_values(array_filter(array_map('strtolower', array_map('trim', $raw))));
};

$adminCns = $normalizeList($authCfg['admin_group_cn'] ?? []);
$checkoutCns = $normalizeList($authCfg['checkout_group_cn'] ?? []);

$googleAdminEmails = $normalizeEmailList($authCfg['google_admin_emails'] ?? []);
$googleCheckoutEmails = $normalizeEmailList($authCfg['google_checkout_emails'] ?? []);

$msAdminEmails = $normalizeEmailList($authCfg['microsoft_admin_emails'] ?? []);
$msCheckoutEmails = $normalizeEmailList($authCfg['microsoft_checkout_emails'] ?? []);

$provider = strtolower($_GET['provider'] ?? $_POST['provider'] ?? 'local');

$ensureProviderParam = static function (string $uri, string $provider): string {
    $parts = parse_url($uri);
    if ($parts === false) {
        return $uri;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    if (!isset($query['provider'])) {
        $query['provider'] = $provider;
    }

    $rebuilt = ($parts['scheme'] ?? '') !== '' ? $parts['scheme'] . '://' : '';
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    if (isset($parts['path'])) {
        $rebuilt .= $parts['path'];
    }
    $rebuilt .= '?' . http_build_query($query);
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
};

$redirectWithError = static function (string $message) {
    $_SESSION['login_error'] = $message;
    header('Location: login.php');
    exit;
};

$upsertUser = static function (PDO $pdo, string $email, string $fullName, string $username): int {
    $userTable = 'users';
    $userIdCol = 'user_id';

    $stmt = $pdo->prepare("SELECT * FROM {$userTable} WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $update = $pdo->prepare("
            UPDATE {$userTable}
               SET name = :name,
                   username = :username
             WHERE id = :id
        ");
        $update->execute([
            ':name' => $fullName,
            ':username' => $username !== '' ? $username : null,
            ':id'   => $existing['id'],
        ]);
        return (int)$existing['id'];
    }

    $userIdHex = sprintf('%u', crc32(strtolower($email)));
    $insert = $pdo->prepare("
        INSERT INTO {$userTable} ({$userIdCol}, name, email, username, created_at)
        VALUES (:user_id, :name, :email, :username, NOW())
    ");
    $insert->execute([
        ':user_id' => $userIdHex,
        ':name'    => $fullName,
        ':email'   => $email,
        ':username' => $username !== '' ? $username : null,
    ]);
    return (int)$pdo->lastInsertId();
};

$loadLocalRoles = static function (PDO $pdo, int $userId): array {
    $roles = [
        'is_admin' => false,
        'is_staff' => false,
    ];

    try {
        $stmt = $pdo->prepare('SELECT is_admin, is_staff FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $roles['is_admin'] = !empty($row['is_admin']);
            $roles['is_staff'] = !empty($row['is_staff']);
        }
    } catch (Throwable $e) {
        // Ignore missing columns on older installs.
    }

    return $roles;
};

$fetchLocalUser = static function (PDO $pdo, string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    $identifierLower = strtolower($identifier);
    $stmt = $pdo->prepare("
        SELECT *
          FROM users
         WHERE LOWER(email) = :ident
            OR LOWER(username) = :ident
         LIMIT 1
    ");
    $stmt->execute([':ident' => $identifierLower]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

if ($provider === 'local') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';

    if (trim($identifier) === '' || $password === '') {
        $redirectWithError('Please enter your email/username and password.');
    }

    try {
        $user = $fetchLocalUser($pdo, $identifier);
    } catch (Throwable $e) {
        $redirectWithError($debugOn ? 'Login system is currently unavailable (database error): ' . $e->getMessage() : 'Login system is currently unavailable.');
    }

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        $redirectWithError('Incorrect email/username or password.');
    }

    $displayName = $user['name'] ?? $user['email'] ?? '';
    $firstName = $displayName !== '' ? $displayName : ($user['email'] ?? '');

    $isAdmin = !empty($user['is_admin']);
    $isStaff = !empty($user['is_staff']) || $isAdmin;

    $_SESSION['user'] = [
        'id'           => (int)$user['id'],
        'email'        => $user['email'] ?? '',
        'username'     => $user['username'] ?? '',
        'first_name'   => $firstName,
        'last_name'    => '',
        'display_name' => $displayName,
        'is_admin'     => $isAdmin,
        'is_staff'     => $isStaff,
    ];

    activity_log_event('user_login', 'User logged in', [
        'metadata' => [
            'provider' => 'local',
        ],
    ]);

    header('Location: index.php');
    exit;
}

if ($provider === 'google') {
    if (!$googleEnabled) {
        $redirectWithError('Google sign-in is not available.');
    }

    $clientId     = trim($googleCfg['client_id'] ?? '');
    $clientSecret = trim($googleCfg['client_secret'] ?? '');

    if ($clientId === '' || $clientSecret === '') {
        $redirectWithError('Google sign-in is not configured.');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $fallbackRedirect = $scheme . '://' . $host . $base . '/login_process.php?provider=google';
    $redirectUri = trim($googleCfg['redirect_uri'] ?? '') ?: $fallbackRedirect;
    $redirectUri = $ensureProviderParam($redirectUri, 'google');

    $allowedDomains = $googleCfg['allowed_domains'] ?? [];
    if (!is_array($allowedDomains)) {
        $allowedDomains = [];
    }
    $allowedDomains = array_values(array_filter(array_map('strtolower', array_map('trim', $allowedDomains))));

    if (!isset($_GET['code'])) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
            'state'         => $state,
        ]);

        header('Location: ' . $authUrl);
        exit;
    }

    $state = $_GET['state'] ?? '';
    if ($state === '' || empty($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
        unset($_SESSION['google_oauth_state']);
        $redirectWithError('Google sign-in failed. Please try again.');
    }
    unset($_SESSION['google_oauth_state']);

    $code = trim($_GET['code'] ?? '');
    if ($code === '') {
        $redirectWithError('Google sign-in failed (no code returned).');
    }

    $tokenCh = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($tokenCh, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $tokenRaw = curl_exec($tokenCh);
    if ($tokenRaw === false) {
        $err = curl_error($tokenCh);
        curl_close($tokenCh);
        $redirectWithError($debugOn ? 'Google token request failed: ' . $err : 'Google sign-in failed.');
    }
    $tokenCode = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
    curl_close($tokenCh);

    $tokenData = json_decode($tokenRaw, true);
    if ($tokenCode >= 400 || !$tokenData || !empty($tokenData['error'])) {
        $msg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unexpected response';
        $redirectWithError($debugOn ? 'Google token error: ' . $msg : 'Google sign-in failed.');
    }

    $accessToken = $tokenData['access_token'] ?? '';
    if ($accessToken === '') {
        $redirectWithError('Google sign-in failed (no access token).');
    }

    $infoCh = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($infoCh, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $infoRaw = curl_exec($infoCh);
    if ($infoRaw === false) {
        $err = curl_error($infoCh);
        curl_close($infoCh);
        $redirectWithError($debugOn ? 'Google profile request failed: ' . $err : 'Google sign-in failed.');
    }
    $infoCode = curl_getinfo($infoCh, CURLINFO_HTTP_CODE);
    curl_close($infoCh);

    $info = json_decode($infoRaw, true);
    if ($infoCode >= 400 || !$info || empty($info['email'])) {
        $redirectWithError($debugOn ? 'Could not read Google profile (HTTP ' . $infoCode . ')' : 'Google sign-in failed.');
    }

    $email = strtolower(trim($info['email']));
    if (!$email) {
        $redirectWithError('Google sign-in failed (no email returned).');
    }

    if (!empty($allowedDomains)) {
        $domain = strtolower((string)substr(strrchr($email, '@'), 1));
        if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
            $redirectWithError('This Google account is not permitted to sign in.');
        }
    }

    $firstName = $info['given_name'] ?? '';
    $lastName  = $info['family_name'] ?? '';
    $fullName  = trim($info['name'] ?? ($firstName . ' ' . $lastName));
    if ($fullName === '') {
        $fullName = $email;
    }

    try {
        $userId = $upsertUser($pdo, $email, $fullName, $email);
    } catch (Throwable $e) {
        $redirectWithError($debugOn ? 'Login system is currently unavailable (database error): ' . $e->getMessage() : 'Login system is currently unavailable (database error).');
    }

    $isAdmin = in_array($email, $googleAdminEmails, true);
    $isCheckout = in_array($email, $googleCheckoutEmails, true);
    $isStaff = $isAdmin || $isCheckout;

    $localRoles = $loadLocalRoles($pdo, $userId);
    if ($localRoles['is_admin']) {
        $isAdmin = true;
    }
    if ($localRoles['is_staff']) {
        $isStaff = true;
    }
    if ($isAdmin) {
        $isStaff = true;
    }

    $_SESSION['user'] = [
        'id'           => $userId,
        'email'        => $email,
        'username'     => $email,
        'first_name'   => $firstName ?: $email,
        'last_name'    => $lastName ?? '',
        'display_name' => $fullName,
        'is_admin'     => $isAdmin,
        'is_staff'     => $isStaff,
    ];

    activity_log_event('user_login', 'User logged in', [
        'metadata' => [
            'provider' => 'google',
        ],
    ]);

    header('Location: index.php');
    exit;
}

if ($provider === 'microsoft') {
    if (!$msEnabled) {
        $redirectWithError('Microsoft sign-in is not available.');
    }

    $clientId     = trim($msCfg['client_id'] ?? '');
    $clientSecret = trim($msCfg['client_secret'] ?? '');
    $tenant       = trim($msCfg['tenant'] ?? '');

    if ($clientId === '' || $clientSecret === '') {
        $redirectWithError('Microsoft sign-in is not configured.');
    }

    if ($tenant === '') {
        $redirectWithError('Microsoft tenant ID is required.');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $fallbackRedirect = $scheme . '://' . $host . $base . '/login_process.php?provider=microsoft';
    $redirectUri = trim($msCfg['redirect_uri'] ?? '') ?: $fallbackRedirect;
    $redirectUri = $ensureProviderParam($redirectUri, 'microsoft');

    $allowedDomains = $msCfg['allowed_domains'] ?? [];
    if (!is_array($allowedDomains)) {
        $allowedDomains = [];
    }
    $allowedDomains = array_values(array_filter(array_map('strtolower', array_map('trim', $allowedDomains))));

    if (!isset($_SESSION['ms_oauth_retry'])) {
        $_SESSION['ms_oauth_retry'] = 0;
    }

    $startMicrosoftAuth = function (bool $forcePrompt = false) use ($tenant, $clientId, $redirectUri) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['ms_oauth_state'] = $state;
        $_SESSION['ms_oauth_retry'] = $_SESSION['ms_oauth_retry'] ?? 0;

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope'         => 'openid profile email User.Read User.Read.All',
            'state'         => $state,
            'prompt'        => 'none',
        ];

        if ($forcePrompt) {
            $params['prompt'] = 'select_account';
        }

        $authUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . http_build_query($params);
        header('Location: ' . $authUrl);
        exit;
    };

    if (!isset($_GET['code'])) {
        $authError = trim($_GET['error'] ?? '');
        if ($authError !== '') {
            $retryAllowed = ($_SESSION['ms_oauth_retry'] ?? 0) < 1;
            if ($retryAllowed && in_array($authError, ['login_required', 'interaction_required', 'account_selection_required'], true)) {
                $_SESSION['ms_oauth_retry'] = ($_SESSION['ms_oauth_retry'] ?? 0) + 1;
                $startMicrosoftAuth(true);
            }
            $_SESSION['ms_oauth_retry'] = 0;
            $errMsg = $_GET['error_description'] ?? $authError;
            $redirectWithError($debugOn ? 'Microsoft sign-in failed: ' . $errMsg : 'Microsoft sign-in failed.');
        }
        $startMicrosoftAuth(false);
    }

    $state = $_GET['state'] ?? '';
    if ($state === '' || empty($_SESSION['ms_oauth_state']) || !hash_equals($_SESSION['ms_oauth_state'], $state)) {
        unset($_SESSION['ms_oauth_state']);
        if (($_SESSION['ms_oauth_retry'] ?? 0) < 1) {
            $_SESSION['ms_oauth_retry'] = ($_SESSION['ms_oauth_retry'] ?? 0) + 1;
            $startMicrosoftAuth(true);
        }
        $_SESSION['ms_oauth_retry'] = 0;
        $redirectWithError('Microsoft sign-in failed. Please try again.');
    }
    unset($_SESSION['ms_oauth_state'], $_SESSION['ms_oauth_retry']);

    $code = trim($_GET['code'] ?? '');
    if ($code === '') {
        $redirectWithError('Microsoft sign-in failed (no code returned).');
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $tokenCh = curl_init($tokenUrl);
    curl_setopt_array($tokenCh, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'scope'         => 'openid profile email User.Read User.Read.All',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $tokenRaw = curl_exec($tokenCh);
    if ($tokenRaw === false) {
        $err = curl_error($tokenCh);
        curl_close($tokenCh);
        $redirectWithError($debugOn ? 'Microsoft token request failed: ' . $err : 'Microsoft sign-in failed.');
    }
    $tokenCode = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
    curl_close($tokenCh);

    $tokenData = json_decode($tokenRaw, true);
    if ($tokenCode >= 400 || !$tokenData || !empty($tokenData['error'])) {
        $msg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unexpected response';
        $redirectWithError($debugOn ? 'Microsoft token error: ' . $msg : 'Microsoft sign-in failed.');
    }

    $accessToken = $tokenData['access_token'] ?? '';
    if ($accessToken === '') {
        $redirectWithError('Microsoft sign-in failed (no access token).');
    }
    $expiresIn = (int)($tokenData['expires_in'] ?? 0);
    if ($expiresIn > 0) {
        $_SESSION['ms_access_token_expires_at'] = time() + $expiresIn;
    }
    $_SESSION['ms_access_token'] = $accessToken;

    $infoCh = curl_init('https://graph.microsoft.com/v1.0/me?$select=displayName,givenName,surname,mail,userPrincipalName');
    curl_setopt_array($infoCh, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $infoRaw = curl_exec($infoCh);
    if ($infoRaw === false) {
        $err = curl_error($infoCh);
        curl_close($infoCh);
        $redirectWithError($debugOn ? 'Microsoft profile request failed: ' . $err : 'Microsoft sign-in failed.');
    }
    $infoCode = curl_getinfo($infoCh, CURLINFO_HTTP_CODE);
    curl_close($infoCh);

    $info = json_decode($infoRaw, true);
    $email = '';
    if (is_array($info)) {
        $email = $info['mail'] ?? ($info['userPrincipalName'] ?? '');
    }
    $email = strtolower(trim((string)$email));

    if ($infoCode >= 400 || !$info || $email === '') {
        $redirectWithError($debugOn ? 'Could not read Microsoft profile (HTTP ' . $infoCode . ')' : 'Microsoft sign-in failed.');
    }

    if (!empty($allowedDomains)) {
        $domain = strtolower((string)substr(strrchr($email, '@'), 1));
        if ($domain === '' || !in_array($domain, $allowedDomains, true)) {
            $redirectWithError('This Microsoft account is not permitted to sign in.');
        }
    }

    $firstName = $info['givenName'] ?? '';
    $lastName  = $info['surname'] ?? '';
    $fullName  = trim($info['displayName'] ?? ($firstName . ' ' . $lastName));
    if ($fullName === '') {
        $fullName = $email;
    }

    try {
        $userId = $upsertUser($pdo, $email, $fullName, $email);
    } catch (Throwable $e) {
        $redirectWithError($debugOn ? 'Login system is currently unavailable (database error): ' . $e->getMessage() : 'Login system is currently unavailable (database error).');
    }

    $isAdmin = in_array($email, $msAdminEmails, true);
    $isCheckout = in_array($email, $msCheckoutEmails, true);
    $isStaff = $isAdmin || $isCheckout;

    $localRoles = $loadLocalRoles($pdo, $userId);
    if ($localRoles['is_admin']) {
        $isAdmin = true;
    }
    if ($localRoles['is_staff']) {
        $isStaff = true;
    }
    if ($isAdmin) {
        $isStaff = true;
    }

    $_SESSION['user'] = [
        'id'           => $userId,
        'email'        => $email,
        'username'     => $email,
        'first_name'   => $firstName ?: $email,
        'last_name'    => $lastName ?? '',
        'display_name' => $fullName,
        'is_admin'     => $isAdmin,
        'is_staff'     => $isStaff,
    ];

    activity_log_event('user_login', 'User logged in', [
        'metadata' => [
            'provider' => 'microsoft',
        ],
    ]);

    header('Location: index.php');
    exit;
}

if (!$ldapEnabled) {
    $redirectWithError('LDAP sign-in is disabled.');
}

/**
 * Polyfill ldap_escape (older PHP builds)
 */
if (!function_exists('ldap_escape')) {
    function ldap_escape(string $str, string $ignore = '', int $flags = 0): string
    {
        $search  = ['\\', '*', '(', ')', "\x00"];
        $replace = ['\5c', '\2a', '\28', '\29', '\00'];

        if ($ignore !== '') {
            for ($i = 0; $i < strlen($ignore); $i++) {
                $idx = array_search($ignore[$i], $search, true);
                if ($idx !== false) {
                    unset($search[$idx], $replace[$idx]);
                }
            }
            $search  = array_values($search);
            $replace = array_values($replace);
        }

        return str_replace($search, $replace, $str);
    }
}

// ------------------------------------------------------------------
// Read input (EMAIL + password)
// ------------------------------------------------------------------
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    $redirectWithError('Please enter your email address and password.');
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
    $redirectWithError('Login system is currently unavailable (cannot connect to LDAP).');
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
    $redirectWithError($debugOn
        ? 'LDAP service bind failed: ' . ldap_error($ldap)
        : 'Login system is currently unavailable.');
}

// ------------------------------------------------------------------
// Find user by EMAIL
// ------------------------------------------------------------------
$emailEsc = ldap_escape($email, '', defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 0);
$filter = sprintf(
    '(&(objectClass=user)(|(mail=%1$s)(userPrincipalName=%1$s)(proxyAddresses=smtp:%1$s)(proxyAddresses=SMTP:%1$s)))',
    $emailEsc
);

$attrs = [
    'distinguishedName',
    'givenName',
    'sn',
    'displayName',
    'mail',
    'memberOf',
    'sAMAccountName',
    'userPrincipalName',
];

$search  = @ldap_search($ldap, $ldapCfg['base_dn'], $filter, $attrs);
$entries = $search ? ldap_get_entries($ldap, $search) : ['count' => 0];

if (($entries['count'] ?? 0) !== 1) {
    $redirectWithError('Incorrect email address or password.');
}

$user   = $entries[0];
$userDn = $user['distinguishedname'][0] ?? null;

if (empty($userDn)) {
    $redirectWithError($debugOn
        ? 'LDAP: User DN not found for this account.'
        : 'Incorrect email address or password.');
}

// ------------------------------------------------------------------
// Bind as user (check password)
// ------------------------------------------------------------------
if (!@ldap_bind($ldap, $userDn, $password)) {
    ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagMsg);
    error_log('LDAP user bind failed for ' . $email . ': ' . ldap_error($ldap) . ' (' . ($diagMsg ?? 'no detail') . ')');
    $redirectWithError('Incorrect email address or password.');
}

// ------------------------------------------------------------------
// Extract attributes from LDAP
// ------------------------------------------------------------------
$firstName = $user['givenname'][0]       ?? '';
$lastName  = $user['sn'][0]              ?? '';
$display   = $user['displayname'][0]     ?? '';
$mail      = $user['mail'][0]
    ?? ($user['userprincipalname'][0] ?? $email);
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
$isAdmin = false;
$isCheckout = false;
if (!empty($user['memberof']) && is_array($user['memberof'])) {
    for ($i = 0; $i < ($user['memberof']['count'] ?? 0); $i++) {
        $memberOf = $user['memberof'][$i] ?? '';
        foreach ($adminCns as $cn) {
            if ($cn !== '' && stripos($memberOf, 'CN=' . $cn . ',') !== false) {
                $isAdmin = true;
                break;
            }
        }
        foreach ($checkoutCns as $cn) {
            if ($cn !== '' && stripos($memberOf, 'CN=' . $cn . ',') !== false) {
                $isCheckout = true;
                break;
            }
        }
        if ($isAdmin && $isCheckout) {
            break;
        }
    }
}
if ($isAdmin || $isCheckout) {
    $isStaff = true;
} else {
    $isStaff = false;
}

// ------------------------------------------------------------------
// Upsert into users table: id, user_id, name, email, created_at
// We key users by EMAIL only, and store full name in `name`.
// `user_id` must be UNIQUE, so we derive a stable numeric ID from email.
// ------------------------------------------------------------------
try {
    $userId = $upsertUser($pdo, $mail, $fullName, $sam);
} catch (Throwable $e) {
    $redirectWithError($debugOn
        ? 'Login system is currently unavailable (database error): ' . $e->getMessage()
        : 'Login system is currently unavailable (database error).');
}

// ------------------------------------------------------------------
// Local role override (if stored in users table)
// ------------------------------------------------------------------
$localRoles = $loadLocalRoles($pdo, $userId);
if ($localRoles['is_admin']) {
    $isAdmin = true;
}
if ($localRoles['is_staff']) {
    $isStaff = true;
}
if ($isAdmin) {
    $isStaff = true;
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
    'is_admin'     => $isAdmin,
    'is_staff'     => $isStaff,
];

activity_log_event('user_login', 'User logged in', [
    'metadata' => [
        'provider' => 'ldap',
    ],
]);

ldap_unbind($ldap);

header('Location: index.php');
exit;
