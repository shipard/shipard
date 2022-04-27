#!/usr/bin/env php
<?php

function createNewDataSource ()
{
	$cmd = 'shpd-server server-create-hosting-ds';
	passthru($cmd);
}


createNewDataSource ();
