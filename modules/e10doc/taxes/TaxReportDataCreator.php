<?php

namespace e10doc\taxes;

use \e10\utils, \e10\json, \e10\Utility;


/**
 * Class TaxReportDataCreator
 * @package e10doc\taxes
 */
class TaxReportDataCreator extends Utility
{
	var $taxReportId = '';
	var $taxReportType = NULL;

	/** @var  \e10doc\taxes\TableReports */
	var $tableReports;
	/** @var  \e10doc\taxes\TableReportsParts */
	var $tableReportsParts;
	var $reportVersionId;
	var $reportVersion;
	var $reportRecData = NULL;

	var $computedPart = 'used';

	var $recData;
	var $data = [];

	function setItem ($itemId, $data)
	{
		$this->data[$this->computedPart][$itemId] = $data;
	}

	public function setReport ($reportRecData)
	{
		$this->reportRecData = $reportRecData;
		$this->reportVersionId = $this->tableReports->reportVersion($this->reportRecData);
		$this->reportVersion = $this->tableReports->reportVersion($this->reportRecData, TRUE);

		$this->loadData();
	}

	public function init ()
	{
		$this->tableReports = $this->app()->table('e10doc.taxes.reports');
		$this->tableReportsParts = $this->app()->table('e10doc.taxes.reportsParts');

		if ($this->taxReportId !== '')
			$this->taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->taxReportId, NULL);
	}

	function loadData()
	{
		$q[] = 'SELECT ndx FROM [e10doc_taxes_reportsData]';
		array_push ($q, ' WHERE [report] = %i', $this->reportRecData['ndx']);
		array_push ($q, ' AND [filing] = %i', 0);

		$existedData = $this->db()->query($q)->fetch();
		if (!$existedData)
		{
			$this->recData = ['report' => $this->reportRecData['ndx'], 'filing' => 0, 'data' => ''];
			$this->db()->query ('INSERT INTO [e10doc_taxes_reportsData] ', $this->recData);
			$newItemNdx = intval ($this->db()->getInsertId ());
			$this->recData['ndx'] = $newItemNdx;
		}
		else
		{
			$this->recData = $existedData->toArray();
		}

		if (!isset($this->recData['data']) || $this->recData['data'] == '')
			$this->data = [];
		else
			$this->data = json_decode($this->recData['data'], TRUE);
	}

	function saveData()
	{
		$item = ['data' => json::lint($this->data)];
		$this->db()->query ('UPDATE [e10doc_taxes_reportsData] SET ', $item, ' WHERE ndx = %i', $this->recData['ndx']);
	}

	public function rebuild()
	{
	}

	function resetParts()
	{
		$q = [];
		array_push ($q, 'SELECT * FROM [e10doc_taxes_reportsParts] AS parts ');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND parts.[report] = %i', $this->reportRecData['ndx']);
		array_push ($q, ' AND parts.[filing] = %i', 0);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pd = $this->tableReportsParts->partDefinition ($this->reportRecData, $this->reportVersionId, $r['partId']);
			if (isset($pd['resetAll']))
				$partData = [];
			else
				$partData = ($r['data'] != '') ? json_decode($r['data'], TRUE) : [];

			foreach ($pd['fields']['columns'] as $col)
			{
				$this->resetSrcValue ($col, $partData);
			}

			$this->app()->subColumnsCalc ($partData, $pd['fields']);

			$item = ['data' => json::lint($partData)];
			$this->db()->query ('UPDATE [e10doc_taxes_reportsParts] SET ', $item, ' WHERE ndx = %i', $r['ndx']);
		}
	}

	function resetSrcValue ($col, &$destData)
	{
		if (isset($col['defaultValue']))
		{
			$destData[$col['id']] = $col['defaultValue'];
		}
		
		if (!isset($col['src']))
			return;

		if ($col['src'][0] === '=')
		{
			$parts = explode (' ', substr($col['src'], 1));
			$total = 0;
			foreach ($parts as $part)
			{
				if ($part === '')
					continue;

				$partId = ($part[0] === '-') ? substr($part, 1) : $part;
				$colValue = utils::cfgItem($this->data['used'], $partId);
				if ($colValue !== NULL)
					$total += ($part[0] === '-') ? $colValue * -1 : $colValue;
			}
			$destData[$col['id']] = $total;
		}
		else
		{
			$colValue = utils::cfgItem($this->data['used'], $col['src']);
			if ($colValue !== NULL)
				$destData[$col['id']] = $colValue;
		}
	}
}
