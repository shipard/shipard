<?php

namespace lib\core;


use \E10\utils, \E10\Utility;


/**
 * Class SaveDocumentAsPdf
 * @package lib\core
 */
class SaveDocumentAsPdf extends Utility
{
	var $table;
	var $documentNdx = 0;
	var $documentRecData = NULL;
	var $mainReportClass;
	var $attachmentsPdfOnly = FALSE;
	var $files = [];

	public $fullFileName;

	public function addFile ($fileName, $fileType)
	{
		static $imgFileTypes = array ('pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg');
		if (!in_array(strtolower($fileType), $imgFileTypes))
			return;

		if (!is_file($fileName))
			return;

		if ($fileType !== 'pdf')
		{
			$fn = utils::tmpFileName('pdf');
			exec ("convert \"{$fileName}\" \"{$fn}\"");
		}
		else
		{
			$fn = $fileName;
		}
		$this->files[] = $fn;
	}

	public function addDocumentAttachments ($tableId, $recId)
	{
		$cntAdded = 0;
		$q [] = 'SELECT * FROM e10_attachments_files WHERE 1';
		array_push($q, 'AND tableid = %s', $tableId, ' AND recid = %i', $recId);
		array_push($q, 'AND [deleted] = 0');
		array_push($q, ' ORDER BY [defaultImage] DESC, [order] ASC');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$fn = __APP_DIR__.'/att/'.$r['path'] . $r['filename'];
			if ($this->attachmentsPdfOnly  && $r['filetype'] !== 'pdf')
				continue;
			$this->addFile($fn, $r['filetype']);
			$cntAdded++;
		}
		return $cntAdded;
	}

	protected function addItemReport ($report)
	{
		$report->saveAs = FALSE;
		$report->renderReport ();
		$report->createReport ();

		$this->addFile($report->fullFileName, 'pdf');
	}

	public function addDocument ($table, $recNdx, $recData, $reportClass)
	{
		$tableId = $table->tableId();

		// -- document report
		$report = $table->getReportData ($reportClass, $recNdx);
		$this->addItemReport($report);

		// -- documents attachments
		$this->addDocumentAttachments ($tableId, $recNdx);
	}

	public function setDocument ($table, $ndx, $mainReportClass)
	{
		$this->table = $table;

		$this->documentNdx = $ndx;
		$this->documentRecData = $this->table->loadItem ($ndx);
		$this->mainReportClass = $mainReportClass;
	}

	public function createPdf ()
	{
		$this->fullFileName = utils::tmpFileName ('pdf');

		$cmd = 'pdfunite ';
		foreach ($this->files as $oneFileName)
		{
			$cmd .= "\"{$oneFileName}\" ";
		}
		$cmd .= "\"{$this->fullFileName}\"";
		exec ($cmd);
	}

	public function run ()
	{
		if ($this->documentNdx)
			$this->addDocument ($this->table, $this->documentNdx, $this->documentRecData, $this->mainReportClass);
		$this->createPdf();
	}
}
