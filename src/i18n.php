<?php

function i18n_locale_candidates(string $language): array
{
    $normalizedLanguage = str_replace('-', '_', trim($language));
    $normalizedLanguage = $normalizedLanguage !== '' ? $normalizedLanguage : 'C';

    if (strtoupper($normalizedLanguage) === 'C' || strtoupper($normalizedLanguage) === 'POSIX') {
        return [$normalizedLanguage, 'C.UTF-8', 'C'];
    }

    $hyphenatedLanguage = str_replace('_', '-', $normalizedLanguage);
    $baseLanguage = strtok($normalizedLanguage, '_') ?: $normalizedLanguage;
    $candidateLocales = [];
    foreach (array_unique([$normalizedLanguage, $hyphenatedLanguage, $baseLanguage]) as $localeName) {
        $candidateLocales[] = $localeName . '.UTF-8';
        $candidateLocales[] = $localeName . '.utf8';
        $candidateLocales[] = $localeName . '.UTF8';
        $candidateLocales[] = $localeName;
        $candidateLocales[] = $localeName . '.ISO8859-15';
        $candidateLocales[] = $localeName . '.ISO8859-1';
    }

    return array_values(array_unique(array_filter($candidateLocales)));
}

$config = load_config();
$language = trim((string)($config['app']['language'] ?? 'C'));
$language = $language !== '' ? $language : 'C';
$normalizedLanguage = str_replace('-', '_', $language);
$candidateLocales = i18n_locale_candidates($normalizedLanguage);
$activeLocale = false;
foreach ($candidateLocales as $candidateLocale) {
    $activeLocale = setlocale(LC_ALL, $candidateLocale);
    if ($activeLocale !== false) {
        break;
    }
}

putenv('LANG=' . ($activeLocale !== false ? $activeLocale : $candidateLocales[0]));
putenv('LC_ALL=' . ($activeLocale !== false ? $activeLocale : $candidateLocales[0]));
putenv('LANGUAGE=' . $normalizedLanguage);
bindtextdomain('messages', SRC_PATH . '/locales');
bind_textdomain_codeset('messages', 'UTF-8');
textdomain('messages');
