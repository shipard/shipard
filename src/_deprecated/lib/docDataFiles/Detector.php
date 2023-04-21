<?php

namespace lib\docDataFiles;

use E10\Utility;


/**
 * Class Detector
 * @package lib\docDataFiles
 */
class Detector extends Utility
{
	var $attNdx = 0;
	var $attRecData = NULL;
	var $attFileName;

	var $fileContent = NULL;

	var $error = 1;

	var $ddfId = 0;
	var $ddfNdx = 0;

	/** @var \e10\base\TableAttachments */
	var $tableAttachments;

	public function init()
	{
		$this->tableAttachments = $this->app()->table('e10.base.attachments');
	}

	public function setAttachment($attNdx, $ddfNdx)
	{
		$this->attNdx = $attNdx;
		$this->ddfNdx = $ddfNdx;
	}

	function load()
	{
		$this->attRecData = $this->tableAttachments->loadItem($this->attNdx);
		$this->attFileName = __APP_DIR__.'/att/'.$this->attRecData['path'].$this->attRecData['filename'];

		if ($this->attRecData['fileSize'] > 1000000)
			return;

		$this->fileContent = file_get_contents($this->attFileName);
		if (!$this->fileContent)
		{
			return;
		}

		$this->error = 0;
	}

	public function detect()
	{
		$this->load();

		if ($this->error)
			return;

		$ddf = $this->app()->cfgItem('e10.ddf.formats');
		foreach ($ddf as $ddfId => $ddfCfg)
		{
			/** @var \lib\docDataFiles\DocDataFile $o */
			$o = $this->app()->createObject($ddfCfg['classId']);
			if (!$o)
			{
				continue;
			}

			if ($this->attRecData && $this->attRecData['tableid'] === 'wkf.core.issues')
				$o->inboxNdx = $this->attRecData['recid'];

			$o->init();
			$o->setFileContent($this->fileContent, $this->attNdx);
			$o->checkFileContent();

			if (!$o->ddfId)
				continue;

			$this->ddfId = $o->ddfId;
			$this->ddfNdx = $o->ddfNdx;
			return;
		}
	}
}


