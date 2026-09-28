<?php

declare(strict_types=1);

/**
 * Constants PrestaShop defines at runtime, declared here so static analysis
 * does not report every use of them as undefined.
 *
 * Analysis-only: this file is never loaded by the module. PrestaShop defines
 * these in config/defines.inc.php and config/settings.inc.php, which cannot be
 * included from a static-analysis bootstrap because they expect a booted shop.
 */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', '/var/www/html/modules/');
}

if (!defined('_PS_THEME_DIR_')) {
    define('_PS_THEME_DIR_', '/var/www/html/themes/classic/');
}

if (!defined('_PS_IMG_DIR_')) {
    define('_PS_IMG_DIR_', '/var/www/html/img/');
}

if (!defined('_PS_IMG_')) {
    define('_PS_IMG_', '/img/');
}
