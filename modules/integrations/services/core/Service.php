<?php

namespace integrations\services\core;

use \e10\Utility, \e10\utils;


/**
 * Class Service
 * @package integrations\services\core
 */
class Service extends Utility
{
	var $taskRecData = NULL;
	var $taskTypeId = '';
	var $taskTypeCfg = NULL;

	var $serviceRecData = NULL;

	var $downloadFilesInfoFileName = '';
	var $downloadFilesInfo = NULL;

	var $downloadFilesBeginDateTime = NULL;
	var $startDateTime = NULL;
	var $downloadFilesLimitDateTime = NULL;

	var $authKeyFileName ='';


	function init()
	{
		$this->taskTypeId = $this->taskRecData['taskType'];
		$this->taskTypeCfg = $this->app()->cfgItem ('integration.tasks.types.'.$this->taskTypeId, NULL);

		$this->serviceRecData = $this->app()->loadItem($this->taskRecData['service'], 'integrations.core.services');


		$this->downloadFilesInfoFileName = __APP_DIR__.'/tmp/int-df-info-'.$this->taskRecData['service'].'-'.$this->taskRecData['ndx'].'.json';
		if (is_readable($this->downloadFilesInfoFileName))
			$this->downloadFilesInfo = utils::loadCfgFile($this->downloadFilesInfoFileName);


		$this->startDateTime = new \DateTime();

		if ($this->downloadFilesInfo)
		{
			$this->downloadFilesBeginDateTime = $this->downloadFilesInfo['lastDownload'];
		}
		else
		{
			$this->downloadFilesBeginDateTime = $this->startDateTime->format(DATE_ISO8601);
			$this->downloadFilesInfo = ['lastDownload' => $this->downloadFilesBeginDateTime];

			$this->saveDownloadFilesInfo();
		}

		$downloadFilesLimitDateTime = new \DateTime($this->downloadFilesBeginDateTime);
		$downloadFilesLimitDateTime->sub (new \DateInterval('P1D'));

		$this->downloadFilesLimitDateTime = $downloadFilesLimitDateTime->format(DATE_ISO8601);
	}

	function runTask ($taskRecData)
	{
		$this->taskRecData = $taskRecData;

		$this->init();
	}

	protected function saveDownloadFilesInfo()
	{
		$this->downloadFilesInfo['lastDownload'] = $this->startDateTime->format(DATE_ISO8601);
		file_put_contents($this->downloadFilesInfoFileName, json_encode($this->downloadFilesInfo));
	}

	protected function saveAuthKey()
	{
		$this->authKeyFileName = utils::tmpFileName('dta', utils::createToken(16));
		file_put_contents($this->authKeyFileName, $this->serviceRecData['authKey']);
	}

	protected function deleteAuthKey()
	{
		if ($this->authKeyFileName !== '')
			unlink($this->authKeyFileName);
	}

	function downloadFilesQueue($cloudService)
	{
		$q[] = 'SELECT * FROM [integrations_core_downloadFiles]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [downloadState] = %i', 0);
		array_push ($q, ' AND [task] = %i', $this->taskRecData['ndx']);
		array_push ($q, ' ORDER BY [ndx]');
		array_push ($q, ' LIMIT 20');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->db()->query ('UPDATE [integrations_core_downloadFiles] SET downloadState = 1 WHERE [ndx] = %i', $r['ndx']);

			$fullFileName = $this->downloadOneFile($cloudService, $r->toArray());

			if ($fullFileName === '')
			{ // download not enabled
				$this->db()->query ('UPDATE [integrations_core_downloadFiles] SET downloadState = 9 WHERE [ndx] = %i', $r['ndx']);
				continue;
			}

			$update = ['downloadState' => 2];

			if ($this->taskRecData['dstTray'])
			{
				$attNdx = \E10\Base\addAttachments ($this->app(), 'wkf.base.trays', $this->taskRecData['dstTray'], $fullFileName, '', TRUE, 0, $r['fileName']);
			}
			else
			{
				$attNdx = \E10\Base\addAttachments ($this->app(), 'integrations.core.downloadFiles', $r['ndx'], $fullFileName, '', TRUE, 0, $r['fileName']);
			}

			if ($attNdx)
			{
				$this->db()->query ('UPDATE [integrations_core_downloadFiles] SET ', $update, ' WHERE [ndx] = %i', $r['ndx']);

				// -- extract metadata
				$emd = new \lib\core\attachments\Extract($this->app());
				$emd->setAttNdx($attNdx);
				$emd->run();

				// -- search geo tags
				$egt = new \lib\core\attachments\GeoTags($this->app());
				$egt->setAttNdx($attNdx);
				$egt->run();
			}
		}
	}
}

