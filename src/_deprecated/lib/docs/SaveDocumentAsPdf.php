<?php

namespace lib\docs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\utils, \E10\Utility;


/**
 * Class SaveDocumentAsPdf
 * @package lib\docs
 */
class SaveDocumentAsPdf extends Utility
{
	var $tableHeads;
	var $documentNdx = 0;
	var $documentRecData;
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
		array_push($q, ' ORDER BY ndx');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$fn = __APP_DIR__.'/att/'.$r['path'] . $r['filename'];
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

	public function addE10Document ($recNdx, $recData)
	{
		$docType = $recData['docType'];
		$cntAttachments = 0;

		$tableId = 'e10doc.core.heads';
		$tableNdx = 1078;

		if ($docType === 'cmnbkp')
		{
			$report = $this->tableHeads->getReportData ('e10doc.cmnbkp.libs.CmnBkp_Acc_Report', $recNdx);
			$report->data['disableSigns'] = 1;
			$this->addItemReport($report);
		}

		// -- outbox
		if ($docType === 'invno')
		{
			$q[] = 'SELECT * FROM wkf_core_issues  WHERE 1';
			array_push($q, 'AND tableNdx = %i', $tableNdx, ' AND recNdx = %i', $recNdx);
			array_push($q, 'AND [docStateMain] = 2');
			array_push($q, ' ORDER BY ndx DESC');
			$rows = $this->db()->query ($q);
			$cntAdded = 0;
			foreach ($rows as $r)
			{
				$cntAdded = $this->addDocumentAttachments ('wkf.core.issues', $r['ndx']);
				$cntAttachments += $cntAdded;
				break;
			}

			if (!$cntAdded)
			{ // generate document report
				$report = $this->tableHeads->getReportData ('e10doc.invoicesOut.libs.InvoiceOutReport', $recNdx);
				$this->addItemReport($report);
				$cntAttachments++;
			}

			unset($q);
		}

		// -- inbox
		if ($docType === 'invno' || $docType === 'invni' || $docType === 'cash' || $docType === 'bank' || $docType === 'cmnbkp')
		{
			// -- attachments from inbox messages
			$q[] = 'SELECT * FROM e10_base_doclinks WHERE 1';
			array_push($q, ' AND srcTableId = %s', 'e10doc.core.heads', ' AND srcRecId = %i', $recNdx);
			array_push($q, ' AND dstTableId = %s', 'wkf.core.issues', 'AND [linkId] = %s', 'e10docs-inbox');
			array_push($q, ' ORDER BY ndx');
			$rows = $this->db()->query ($q);
			$cntAdded = 0;
			foreach ($rows as $r)
			{
				$cntAdded += $this->addDocumentAttachments ('wkf.core.issues', $r['dstRecId']);
				$cntAttachments += $cntAdded;
			}
		}

		// -- documents attachments
		$this->addDocumentAttachments ($tableId, $recNdx);

		// -- accounting report
		if ($docType !== 'cmnbkp')
		{
			$report = $this->tableHeads->getReportData ('e10doc.cmnbkp.libs.CmnBkp_Acc_Report', $recNdx);
			$report->data['disableSigns'] = 1;
			$this->addItemReport($report);
		}
	}

	public function setDocument ($ndx)
	{
		$this->tableHeads = $this->app->table ('e10doc.core.heads');

		$this->documentNdx = $ndx;
		$this->documentRecData = $this->tableHeads->loadItem ($ndx);
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
		$this->addE10Document ($this->documentNdx, $this->documentRecData);
		$this->createPdf();
	}
}
