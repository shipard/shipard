#!/usr/bin/env php
<?php

function doIt ($redis, $channel, $itemId)
{
	$parts = explode ('-', $itemId);
	$dsId = $parts[0];

	$cmd = 'cd /var/www/data-sources/'.$dsId.' && e10-app cache-invalidate-item --itemId='.$itemId.' > /dev/null 2>&1 &';
	exec($cmd);
}

ini_set('default_socket_timeout', -1);

$redis = new Redis();
$redis->pconnect('/var/run/redis/redis.sock');
$redis->subscribe(['invalidate-cache-item'], 'doIt');
