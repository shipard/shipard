<?php

namespace lib\pdf;
use e10\Utility, \e10\json, e10\utils;


/**
 * Class PdfExtractor
 * @package lib\pdf
 */
class PdfExtractor extends Utility
{
	var $fileName = '';
	var $logFileName = '';
	var $pdfInfo = NULL;

	public function setFileName ($fileName)
	{
		$this->fileName = $fileName;
		$this->logFileName = substr($this->fileName, 0, -5) . '.extract.log';
		$cmd = 'e10-modules/lib/pdf/pdfExtractor.py '.$this->fileName.' > '.$this->logFileName.' 2>&1';
		exec($cmd);

		$this->pdfInfo = utils::loadCfgFile($this->fileName.'.json');
		if (!$this->pdfInfo)
			$this->pdfInfo = NULL;
	}

	public function extractAsAttachments ($toTableId, $toRecId)
	{
		if ($this->pdfInfo === NULL || !isset($this->pdfInfo['attachments']) || !count($this->pdfInfo['attachments']))
			return;

		foreach ($this->pdfInfo['attachments'] as $a)
		{
			if (is_readable($a['fullFileName']))
				\E10\Base\addAttachments ($this->app, $toTableId, $toRecId, $a['fullFileName'], '', true, 0, $a['baseFileName']);
		}

		unlink($this->fileName.'.json');

		if (!filesize ($this->logFileName))
			unlink($this->logFileName);
	}
}

