<?php defined('MULTISITE') OR exit('No direct script access allowed');

// online demo: 
// - "sfm" for "*.sfm.github.mhs.web.id"
// local demo: 
// - "sf" for "*.sf.scm.dev.tut", 
// - "scm" for "*.scm.dev.tut"
$_MULTISITE_PREFIX = array('sfm','sf', 'scm');

//define multisite path
define('MULTISITE_PATH', __DIR__ . '/../multisite/');
$_SERVER['SYMFONY__MULTISITE_PATH'] = MULTISITE_PATH;
$_SERVER['SYMFONY__KERNEL__SESSION_PATH'] = __DIR__.'/../var/sessions/';

//init multisite variable
$_domain = _multisite_get_http_host();
_multisite_init_domain($_domain);

//multisite functions
function _multisite_init_domain($_domain) {
    global $_MULTISITE_PREFIX;
    
    $_tmp = explode('.', $_domain);
    $_site = $_tmp[0];
    if (in_array($_site, $_MULTISITE_PREFIX, true)) {
        $_site = 'default';
    }
    $_main_domain = str_replace($_site . '.', '', $_domain);

    if (!defined('MULTISITE_MAIN_DOMAIN')) {
        define('MULTISITE_MAIN_DOMAIN', $_main_domain);
    }
    if (!defined('MULTISITE_SITE_DOMAIN')) {
        define('MULTISITE_SITE_DOMAIN', $_site);
    }
    $_SERVER['SYMFONY__MULTISITE_MAIN_DOMAIN'] = MULTISITE_MAIN_DOMAIN;
    $_SERVER['SYMFONY__MULTISITE_SITE_DOMAIN'] = MULTISITE_SITE_DOMAIN;
}


function _multisite_get_http_host() {

    $_http_host = $_SERVER['HTTP_HOST'];
    if (strpos($_http_host, ':')) {
        $_tmp = explode(':', $_http_host);
        $_http_host = $_tmp[0];
    }

    $_domain = $_http_host;

    if (!defined('MULTISITE_DOMAIN')) {
        define('MULTISITE_DOMAIN', $_domain);
        $_SERVER['SYMFONY__MULTISITE_DOMAIN'] = $_domain;
    }

    return $_domain;
}


function _multisite_get_index() {
    global $_MULTISITE_PREFIX;

    $_domain = _multisite_get_http_host();

    $_tmp = explode('.', $_domain);
    $_site = $_tmp[0];
    if (in_array($_site, $_MULTISITE_PREFIX, true)) {
        $_site = 'default';
    }
    $_main_domain = str_replace($_site . '.', '', $_domain);

    $_index_file = MULTISITE_PATH . $_main_domain . '/' . $_site . '/index.php';

    return $_index_file;
}


function _multisite_get_config($config, $env = 'prod') {
    global $_MULTISITE_PREFIX;

    $_domain = _multisite_get_http_host();

    $_tmp = explode('.', $_domain);
    $_site = $_tmp[0];
    if (in_array($_site, $_MULTISITE_PREFIX, true)) {
        $_site = 'default';
    }
    $_main_domain = str_replace($_site . '.', '', $_domain);

    if (!defined('MULTISITE_MAIN_DOMAIN')) {
        define('MULTISITE_MAIN_DOMAIN', $_main_domain);
    }
    if (!defined('MULTISITE_SITE_DOMAIN')) {
        define('MULTISITE_SITE_DOMAIN', $_site);
    }

    $_config_file = MULTISITE_PATH . $_main_domain . '/' . $_site . '/config/' . $config . '_' . $env . '.yml';

    if (!file_exists($_config_file) and ( $config != 'config')) {
        $_config_file = MULTISITE_PATH . 'all/config/' . $config . '_' . $env.'.yml';
    }

    if (!file_exists($_config_file) and ( $config == 'config')) {
        die("Unknown domain/site: " . $_domain);
    }

    return $_config_file;
}