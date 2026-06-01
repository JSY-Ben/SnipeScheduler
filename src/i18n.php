<?php

$config = load_config();
$locale = ($config['app']['language'] ?? 'C') . '.UTF-8';
putenv('LANG=' . $locale);
setlocale(LC_ALL, $locale);
bindtextdomain('messages', SRC_PATH . '/locales');
textdomain('messages');