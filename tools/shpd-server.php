#!/usr/bin/env php
<?php

if (!defined ('__SHPD_ROOT_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__SHPD_ROOT_DIR__', '/'.implode('/', $parts).'/');
}
define ("__APP_DIR__", getcwd());


require_once __SHPD_ROOT_DIR__ . '/src/boot.php';

$app = new \Shipard\CLI\Server\ShpdServerApp ();
$app->run ($argv);
