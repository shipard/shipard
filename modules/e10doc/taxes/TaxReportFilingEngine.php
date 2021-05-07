<?php

namespace e10doc\taxes;

use \e10\utils, \e10\Utility;


/**
 * Class TaxReportFilingEngine
 * @package e10doc\taxes
 */
class TaxReportFilingEngine extends Utility
{
	var $taxReportId = '';
	var $taxReportType = NULL;
	var $taxReportRecData = NULL;

	/** @var  \e10\DbTable */
	var $tableReports;
	/** @var  \e10\DbTable */
	var $tableFilings;

	var $filingNdx = 0;
	var $filingRecData = NULL;


	public function init ()
	{
		$this->tableReports = $this->app()->table('e10doc.taxes.reports');
		$this->tableFilings = $this->app()->table('e10doc.taxes.filings');
	}

	public function setFiling ($filingRecData)
	{
		$this->taxReportRecData = $this->tableReports->loadItem ($filingRecData['report']);
		$this->taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->taxReportRecData['reportType'], NULL);

		$this->filingNdx = $filingRecData['ndx'];
		$this->filingRecData = $filingRecData;
	}

	public function createFiling ($filingRecData)
	{
		$this->setFiling($filingRecData);

		// -- copy properties
		/*
		$q[] = 'INSERT INTO [e10_base_properties]';
		array_push($q, ' ([property], [group], [subtype], ',
				'[tableid], [recid], [valueString], [valueNum], [valueMemo], ',
				'[valueDate], [note], [created])');
		array_push($q, ' SELECT src.[property], src.[group], src.[subtype], ',
				'%s, ', 'e10doc.taxes.filings', '%i, ', $this->filingNdx, 'src.[valueString], src.[valueNum], src.[valueMemo], ',
				'src.[valueDate], src.[note], NOW()');
		array_push($q, ' FROM [e10_base_properties] AS src WHERE');
		array_push($q, ' src.[tableid] = %s', 'e10doc.taxes.reports', ' AND src.[recid] = %i', $this->taxReportRecData['ndx']);

		$this->db()->query($q);
		*/

		// -- copy rows etc.
		$this->createFilingContent();

		// -- attach files
		$this->createFilingFiles();
	}

	function addFile ($fullFileName, $type, $name)
	{
		$attNdx = \e10\base\addAttachments ($this->app(), 'e10doc.taxes.filings', $this->filingNdx, $fullFileName, '', FALSE, 0, $name);

		$newFile = [
				'report' => $this->taxReportRecData['ndx'], 'reportType' => $this->taxReportRecData['reportType'],
				'filing' => $this->filingNdx, 'fileType' => $type, 'title' => $name, 'attachment' => $attNdx
		];

		$this->db()->query('INSERT INTO [e10doc_taxes_filingFiles] ', $newFile);
	}

	public function createFilingFiles ()
	{
	}

	public function removeFilingContent ()
	{
	}

	public function removeFilingFiles ()
	{
		$q[] = 'SELECT * FROM [e10doc_taxes_filingFiles]';
		array_push($q, ' WHERE [filing] = %i', $this->filingNdx);

		$pksFiles = [];
		$pksAtts = [];

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$pksFiles[] = $r['ndx'];
			$pksAtts[] = $r['attachment'];
		}

		if (count($pksAtts))
			$this->db()->query('UPDATE [e10_attachments_files] SET [deleted] = 1 WHERE ndx IN %in', $pksAtts);
		if (count($pksFiles))
			$this->db()->query('DELETE FROM [e10doc_taxes_filingFiles] WHERE ndx IN %in', $pksFiles);
	}

	public function removeFiling ($filingRecData)
	{
		$this->setFiling($filingRecData);

		$this->removeFilingContent();
		$this->removeFilingFiles();
	}
}
