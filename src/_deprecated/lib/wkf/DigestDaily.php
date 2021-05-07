<?php

namespace lib\Wkf;

require_once __APP_DIR__ . '/e10-modules/e10/base/tables/notifications.php';
require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';

use \E10\utils;


/**
 * Class ReportDaily
 * @package lib\Wkf
 */
class ReportDaily extends \E10\GlobalReport
{
	var $userId = 0;
	var $send = 0;

	function init ()
	{
		$this->reportId = 'lib.wkf.reportDaily';
		parent::init();
	}

	function createContent ()
	{
		$this->app->authenticator->setUser ($this->app, $this->userId);

		$viewer = $this->app->viewer ('e10.base.notifications', 'nc');
		$viewer->init ();
		$viewer->selectRows ();
		$viewer->renderViewerData('html');

		if (!$viewer->countRows)
			return;

		foreach ($viewer->objectData ['dataItems'] as $msg)
		{
			if (!$msg['notified'])
			{
				$newNtf = $msg;
				$newNtf ['dateTime'] = utils::datef ($msg['created'], '%D, %T');
				$this->data['notifications'][] = $newNtf;
				$this->send++;
			}
		}
	}
}


/**
 * Class DigestDaily
 * @package lib\Wkf
 */
class DigestDaily extends \E10\Digest
{
	public function run ()
	{
		$send = $this->app->cfgItem ('options.workflow.sendDailyDigest', 1);
		if ($send == 2)
			return;

		$today = new \DateTime();

		$q = 'SELECT * FROM [e10_persons_persons] WHERE [roles] != %s AND docState = 4000';
		$users = $this->app->db()->query ($q, '');
		foreach ($users as $r)
		{
			$report = new reportDaily($this->app);
			$report->userId = $r['ndx'];
			$report->init ();
			$report->data['userName'] = $r['fullName'];

			$srvUrl = $this->app->cfgItem ('hostingServerUrl', '');
			$report->data['intranetUrl'] = $srvUrl.$this->app->cfgItem ('dsid', '');

			$report->createContent();

			if (!$report->send)
				continue;

			$body = $report->createReportContent ();

			$dsDomain = $this->app->cfgItem ('dsi.domain', '');
			if ($dsDomain === '')
				$dsDomain = $this->app->cfgItem ('dsid', '');
			$fromEmail = $dsDomain.'@shipard.email';
			$fromName = $this->app->cfgItem ('options.core.ownerShortName', 'Shipard');
			$subject = 'Denní přehled '.$today->format('d.m.Y')." ($fromName)";

			\E10\SendEmail ($subject, $body, $fromEmail, $r['login'], $fromName, $r['fullName'], TRUE);
		}
	}
}
