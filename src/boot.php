<?php

define ('__E10_VERSION_ID__', 64);
define ('__E10_VERSION__', '203.1');

if (!defined ('__SHPD_MODULES_DIR__'))
	define ('__SHPD_MODULES_DIR__', __SHPD_ROOT_DIR__.'/modules/');

if (!defined ('__APP_DIR__'))
	define ('__APP_DIR__', __SHPD_APP_DIR__);

if (!defined ('__SHPD_VAR_DIR__'))
	define ('__SHPD_VAR_DIR__', '/var/lib/shipard/');

if (!defined('__SHPD_ETC_DIR__'))
	define('__SHPD_ETC_DIR__', '/etc/shipard');

if (!defined('__SHPD_SERVER_ROOT_DIR__'))
	define('__SHPD_SERVER_ROOT_DIR__', '/usr/lib/shipard/');

define ('__SHPD_TEMPLATE_SUBDIR__', '/www-root/templates/');

define ('DIR_VENDOR', __SHPD_ROOT_DIR__.'/extlibs/vendor');


function __autoload_shipard__ ($class_name)
{
	if (substr ($class_name, 0, 8) === "Shipard\\")
	{
		$fnparts = explode ("\\", substr ($class_name, 8));

		$cbn = array_pop($fnparts);
		$fn = __SHPD_ROOT_DIR__ . "src/" . implode('/', $fnparts). '/' . $cbn . '.php';

		if (is_file ($fn))
			include_once ($fn);
		else
			error_log ('file not found: ' . $fn . ' (required for class ' . $class_name . ')');

		return;
	}

	if (substr ($class_name, 0, 4) === "lib\\")
	{
		$fnparts = explode ("\\", substr ($class_name, 4));

		if (count($fnparts) === 1)
			$fn = __SHPD_ROOT_DIR__ . "/src/_deprecated/lib/" . $fnparts[0]. '/' . $fnparts[0] . '.php';
		else
		{
			$cbn = array_pop($fnparts);
			$fn = __SHPD_ROOT_DIR__ . "/src/_deprecated/lib/" . implode('/', $fnparts). '/' . $cbn . '.php';
		}

		if (is_file ($fn))
			include_once ($fn);
		else
			error_log ('file not found: ' . $fn . ' (required for class ' . $class_name . ')');

		return;
	}


	$cn = $class_name;
	$elements = explode ('\\', $cn);
	$fn = __SHPD_MODULES_DIR__;
	$ccn = array_pop ($elements);

	if (substr ($ccn, 0, 5) === 'Table')
	{
		$fn1 = __SHPD_MODULES_DIR__.strtolower (implode ('/', $elements)).'/tables/' . substr ($ccn, 5) . '.php';
		if (is_file ($fn1))
		{
			include_once($fn1);
			return;
		}
		else
		{
			$fn2 = __SHPD_MODULES_DIR__.strtolower (implode ('/', $elements)).'/tables/' . strtolower(substr ($ccn, 5)) . '.php';
			if (is_file ($fn2))
			{
				include_once($fn2);
				//error_log ('LOAD-TABLE-TO-LOWER: ' . $class_name);
				return;
			}
		}

		error_log ('file not found: ' . $fn1 . ' (required for table ' . $class_name . ')');
	}
	else
	{
		$fn .= implode ('/', $elements) . '/' . $ccn. '.php';
		if (is_file ($fn))
			include_once ($fn);
	}
}
spl_autoload_register('__autoload_shipard__');

require_once DIR_VENDOR . '/autoload.php';
include_once __SHPD_ROOT_DIR__ . 'src/_deprecated/Mustache.php';
include_once __SHPD_ROOT_DIR__ . 'src/_deprecated/e10.php';
include_once __SHPD_ROOT_DIR__ . 'src/_deprecated/e10cli.php';
include_once __SHPD_ROOT_DIR__ . 'src/_deprecated/e10cfg.php';
