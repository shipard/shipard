<?php

namespace demo\documents\libs;

use \e10\utils, \e10\Utility, wkf\core\TableIssues;



/**
 * Class BalSetOffReport
 * @package lib\demo
 */
class BalSetOffReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'reports.default.e10doc.cmnbkp.set-off';
		$this->reportTemplate = 'reports.default.e10doc.cmnbkp.set-off';
	}
}

/**
 * @param $app
 * @param $params
 */
function createInboxFromDoc ($app, $params)
{
	$engine = new DemoDocInbox($app);
	$engine->init ($params);
	$engine->createInbox();
	$params['dataPackageInstaller']->cntAttachments++;
}


/**
 * @param $app
 * @param $params
 */
function createInboxFromAllDocs ($app, $params)
{
	if (isset ($app->params['demoFastMode']))
		return;
	foreach ($params['dataPackageInstaller']->datasetPrimaryKeys as $docNdx)
	{
		$engine = new DemoDocInbox($app);
		$engine->init(['docNdx' => $docNdx]);
		$engine->createInbox();
		unset($engine);
		$params['dataPackageInstaller']->cntAttachments++;
	}
}


/**
 * Class DemoDocOutbox
 * @package lib\demo
 */

/**
 * @param $app
 * @param $params
 */
function createOutboxFromDoc ($app, $params)
{
	$engine = new DemoDocOutbox($app);
	$engine->init ($params);
	$engine->createOutbox();
	$params['dataPackageInstaller']->cntAttachments++;
}

/**
 * @param $app
 * @param $params
 */
function createOutboxFromAllDocs ($app, $params)
{
	//if (isset ($app->params['demoFastMode']))
	//	return;
	foreach ($params['dataPackageInstaller']->datasetPrimaryKeys as $docNdx)
	{
		$engine = new DemoDocOutbox($app);
		$engine->init(['docNdx' => $docNdx]);
		$engine->createOutbox();
		unset($engine);
		$params['dataPackageInstaller']->cntAttachments++;
	}
}
