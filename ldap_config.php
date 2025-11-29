<?php
// ldap_config.php

// LDAP / AD connection details for the booking system

$ldapConfig = [
    // Your working LDAPS URL:
    'host'          => 'ldaps://10.90.201.211',

    // Base DN where users live (adjust if needed)
    'base_dn'       => 'DC=highlands,DC=local',

    // Service account used for searching the directory
    'bind_dn'       => 'CN=ldapmoodle,CN=Users,DC=highlands,DC=local',

    // Service account password
    'bind_password' => 'm00dle',
];
