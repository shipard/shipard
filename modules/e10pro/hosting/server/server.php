<?php

namespace e10pro\hosting\server;
require_once __SHPD_MODULES_DIR__.'hosting/core/libs/api/api.php';


function getDataSourceInfo ($app)
{
	return \hosting\core\libs\api\getDataSourceInfo($app);
}

function getHostingInfo ($app)
{
	return \hosting\core\libs\api\getHostingInfo($app);
}

function getUploadUrl ($app)
{
	return \hosting\core\libs\api\getUploadUrl($app);
}

function getNewDataSource ($app)
{
	return \hosting\core\libs\api\getNewDataSource($app);
}

function confirmNewDataSource ($app)
{
	return \hosting\core\libs\api\confirmNewDataSource($app);
}

