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

		$cmd = __SHPD_ROOT_DIR__.'src/_deprecated/lib/pdf/pdfExtractor.py '.$this->fileName.' > '.$this->logFileName.' 2>&1';
		exec($cmd);

		$this->pdfInfo = utils::loadCfgFile($this->fileName.'.json');
		if (!$this->pdfInfo)
			$this->pdfInfo = NULL;

		if (!$this->pdfInfo || !count($this->pdfInfo['attachments']))
			$this->pdfDetach();
	}

	public function pdfDetach()
	{
		$cmd = "/usr/bin/pdfdetach -list ".$this->fileName;
		$output = [];
		exec($cmd, $output);
		error_log("__O: ".json_encode($output));
		if (count($output) < 2)
			return;

		$firstLine = array_shift($output);
		$flParts = explode(' ', $firstLine);
		$cntFiles = intval($flParts);
		$this->pdfInfo = ['attachments' => []];

		foreach ($output as $row)
		{
			$rp = explode(' ', $row);
			if (count($rp) < 2)
				continue;

			$fileIndex = intval(substr($rp[0], 0, -1));
			if (!$fileIndex)
				continue;

			$baseFileName = $rp[1];
			$fullFileName = 'tmp/'.time().mt_rand(1000, 9999).'_'.$baseFileName;

			$cmd = "/usr/bin/pdfdetach -save ".$fileIndex." -o ".$fullFileName.' '.$this->fileName;
			exec($cmd);

			$attItem = ['baseFileName', $baseFileName, 'fullFileName' => $fullFileName];
			$this->pdfInfo['attachments'][] = $attItem;
		}
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

		if (is_readable($this->fileName.'.json'))
			unlink($this->fileName.'.json');

		if (!filesize ($this->logFileName))
			unlink($this->logFileName);
	}
}

