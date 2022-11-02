<?php

namespace lib\core\attachments;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
use E10\Utility;


/**
 * Class FileArchiveExtractor
 * @package lib\core\attachments
 */
class FileArchiveExtractor extends Utility
{
	var $fileName = '';
	/** @var \ZipArchive */
	var $zipArchive;

	var $filesList = [];

	public function setFileName ($fileName)
	{
		$this->fileName = $fileName;
		$this->zipArchive = new \ZipArchive();
	}

	public function extractAsAttachments ($toTableId, $toRecId, ?array $filesToExtract = NULL)
	{
		$this->zipArchive->open($this->fileName);

		$dstPath = __APP_DIR__ . '/tmp/';

		for ($i = 0; $i < $this->zipArchive->numFiles; $i++)
		{
			if ($filesToExtract && !in_array($i, $filesToExtract))
				continue;

				$zipFileName = $this->zipArchive->getNameIndex($i);
			$srcFileInfo = pathinfo($zipFileName);

			$tmpFileName = $dstPath.$srcFileInfo['basename'];
			copy("zip://".$this->fileName."#".$zipFileName, $tmpFileName);

			\E10\Base\addAttachments ($this->app, $toTableId, $toRecId, $tmpFileName, '', true, 0, $srcFileInfo['basename']);
		}

		$this->zipArchive->close();
	}

	public function getFilesList()
	{
		$this->zipArchive->open($this->fileName);

		for($i = 0; $i < $this->zipArchive->numFiles; $i++)
		{
			$zipFileName = $this->zipArchive->getNameIndex($i);

			if (substr($zipFileName, -1) === '/')
				continue;

			$srcFileInfo = pathinfo($zipFileName);
			if ($srcFileInfo['dirname'] === '__MACOSX')
				continue;

			$this->filesList[] = ['fileName' => $zipFileName, 'ndx' => $i, 'info' => $srcFileInfo];
		}
	}
}
