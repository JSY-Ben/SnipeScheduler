<?php
// src/config_writer.php
// Helper for generating config.php content from an array and constant definitions.

/**
 * Build a config.php file contents from the given config array and constants.
 *
 * @param array $config  The configuration array to return from the file.
 * @param array $defines Map of constant name => value to define if not already set.
 */
function layout_build_config_file(array $config, array $defines = []): string
{
    $safeDefines = [];
    foreach ($defines as $name => $value) {
        if (!preg_match('/^[A-Z0-9_]+$/', $name)) {
            continue;
        }
        $safeDefines[$name] = $value;
    }

    $export = var_export($config, true);

    $content = "<?php\n";
    $content .= "/**\n";
    $content .= " * Auto-generated config for KitGrab.\n";
    $content .= " * Update via the staff settings page or the installer script.\n";
    $content .= " */\n\n";

    foreach ($safeDefines as $name => $value) {
        $valueExport = var_export($value, true);
        $content    .= "if (!defined('{$name}')) {\n";
        $content    .= "    define('{$name}', {$valueExport});\n";
        $content    .= "}\n\n";
    }

    $content .= "return {$export};\n";

    return $content;
}
