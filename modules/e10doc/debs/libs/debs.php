<?php

namespace pkgs\accounting\debs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/cmnbkp/cmnbkp.php';

use \e10\utils, \e10\Utility, e10doc\core\e10utils;


function createOpenPeriodOthers ($app, $params)
{
	if (!isset($params['fiscalYear']))
		return;

	$eng = new \e10doc\cmnbkp\libs\OpenClosePeriodEngine ($app);
	$eng->closeDocs = TRUE;
	$eng->setParams($params['fiscalYear'], TRUE);
	$eng->run();
}


function createClosePeriod ($app, $params)
{
	if (!isset($params['fiscalYear']))
		return;

	$eng = new \e10doc\cmnbkp\libs\OpenClosePeriodEngine ($app);
	$eng->closeDocs = TRUE;
	$eng->setParams($params['fiscalYear'], FALSE);
	$eng->run();
}

//CloseAccPeriodWizard
/**
 * Class CloseAccPeriodWizard
 * @package Pkgs\Accounting\Debs
 */


