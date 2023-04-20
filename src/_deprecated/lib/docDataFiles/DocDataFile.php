<?php

namespace lib\docDataFiles;

use E10\Utility;


/**
 * Class DocDataFile
 * @package lib\docDataFiles
 */
class DocDataFile extends Utility
{
	var $fileContent = NULL;
	var $ddfId = 0;
	var $ddfNdx = 0;
	var $ddfRecData = NULL;
	var $attachmentNdx = 0;

	var $impData = NULL;
	var $docRecData = NULL;
	var $srcImpData = NULL;

	var $inboxNdx = 0;
	var $automaticImport = 0;

	public function init()
	{
	}

	public function setFileContent($fileContent, $attachmentNdx = 0)
	{
		$this->fileContent = $fileContent;
		$this->attachmentNdx = $attachmentNdx;
	}

	public function setRecData($ddfRecData)
	{
		$this->ddfRecData = $ddfRecData;
		$this->ddfId = $ddfRecData['ddfId'];
		$this->attachmentNdx = $ddfRecData['srcAttachment'];
	}

	public function createContents()
	{
		return [];
	}

	public function checkFileContent()
	{
	}

	function addFirstContent()
	{
		$item = [
			'ddfId' => $this->ddfId,
			'srcAttachment' => $this->attachmentNdx,
			'srcData' => $this->fileContent,
			'impData' => '',
			'visualization' => '',
			'docState' => 1200, 'docStateMain' => 1,
		];

		if ($this->ddfNdx)
			$this->db()->query('UPDATE [e10_base_docDataFiles] SET ', $item, ' WHERE [ndx] = %i', $this->ddfNdx);
		else
		{
			$this->db()->query('INSERT INTO [e10_base_docDataFiles] ', $item);
			$this->ddfNdx = intval($this->db()->getInsertId());
		}
	}

	public function createImport()
	{
	}

	public function createDocument($fromRecData, $checkNewRec = FALSE)
	{
	}
}
