<?php

namespace mac\lan;

use e10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class ReportSWLicenses
 * @package mac\lan
 */
class ReportSWLicenses extends \E10\GlobalReport
{
	var $list = [];
	var $pks = [];
	var $tableSWLicenses;
	var $applications;
	var $headerPropertyColumns = [];
	var $clsf;
	var $paramsValuesForHeader = [];

	function init()
	{
		$this->tableSWLicenses = $this->app->table('mac.lan.swLicenses');
		$this->addMyParams();
		parent::init();

		$this->setInfo('icon', 'report/SWLicenses');
		$this->setInfo('title', 'Seznam SW licencí');

		$this->paperOrientation = 'landscape';
	}

	public function createContent()
	{
		parent::createContent();
		$this->loadList();

		$h = ['#' => '#', 'id' => 'InvČ', 'name' => 'Název'];

		$qv = $this->queryValues();
		// -- columns
		if (isset ($qv['columns']))
		{
			if (isset ($qv['columns']['licenseNumber']))
				$h['licenseNumber'] = 'Licenční číslo';
			if (isset ($qv['columns']['device']))
				$h['device'] = 'Počítač';
			if (isset ($qv['columns']['person']))
				$h['person'] = 'Uživatel';
			if (isset ($qv['columns']['invoiceNumber']))
				$h['invoiceNumber'] = 'Doklad';
			if (isset ($qv['columns']['validFrom']))
				$h['validFrom'] = 'Platné od';
		}

		if (count($this->headerPropertyColumns))
			$h += $this->headerPropertyColumns;

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list]);
	}

	protected function loadList()
	{
		$q[] = 'SELECT licenses.*';
		array_push($q, ' FROM mac_lan_swLicenses AS licenses');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND licenses.docState = %i', 4000);

		$qv = $this->queryValues();
		// -- classification
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE licenses.ndx = recid AND tableId = %s', 'mac.lan.swLicenses');
			foreach ($qv['clsf'] as $grpId => $grpItems)
			{
				array_push($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');

				$pcid = 'clsf-'.$grpId;
				$cg = \E10\searchArray($this->clsf, 'id', $grpId);
				if (!isset($this->paramsValuesForHeader[$pcid]))
					$this->paramsValuesForHeader[$pcid] = ['title' => $cg['name'], 'values' => []];
				foreach ($grpItems as $itemNdx => $itemContent)
					$this->paramsValuesForHeader[$pcid]['values'][] = $cg['items'][$itemNdx]['title'];
			}
			array_push ($q, ')');
		}
		// -- applications
		if (isset ($qv['applications']))
		{
			$appNdx = array_keys($qv['applications']);
			array_push($q, " AND [application] IN %in", $appNdx);

			if (!isset($this->paramsValuesForHeader['applications']))
				$this->paramsValuesForHeader['applications'] = ['title' => 'Aplikace', 'values' => []];

			foreach ($appNdx as $ndx)
				$this->paramsValuesForHeader['applications']['values'][] = $this->applications[$ndx];
		}

		array_push($q, ' ORDER BY licenses.id');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->pks[] = $r['ndx'];
			$item = [
					'id' => $r['id'], 'name' => $r['fullName'], 'invoiceNumber' => $r['invoiceNumber'],
					'licenseNumber' => $r['licenseNumber'],
					'validFrom' => $r['validFrom'],
			];

			$this->list[$r['ndx']] = $item;
		}

		// -- devices
		if (isset ($qv['columns']['device']))
		{
			$linkedDevices = $this->linkedDevices($this->pks, 'block');
			foreach ($linkedDevices as $licenseNdx => $devices)
			{
				$this->list[$licenseNdx]['device'] = $devices;
			}
		}

		// -- users
		if (isset ($qv['columns']['person']))
		{
			$linkedPersons = \E10\Base\linkedPersons($this->app, $this->tableSWLicenses, $this->pks);
			foreach ($linkedPersons as $licenseNdx => $persons)
			{
				$this->list[$licenseNdx]['person'] = $persons;
			}
		}

		// -- add header params
		foreach($this->paramsValuesForHeader as $paramId => $paramContent)
			$this->setInfo('param', $paramContent['title'], implode(', ', $paramContent['values']));
	}

	protected function addMyParams()
	{
		// -- applications
		$qryApplications[] = 'SELECT ndx, shortName FROM mac_lan_swApplications AS apps WHERE docStateMain != 4';
		array_push ($qryApplications, ' AND EXISTS (SELECT ndx FROM mac_lan_swLicenses WHERE apps.ndx = application)');
		array_push ($qryApplications, ' ORDER BY shortName, fullName, ndx');
		$this->applications = $this->db()->query ($qryApplications)->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($this->applications, 'applications', 'Aplikace');

		// -- classification
		UtilsBase::addClassificationParamsToPanel($this->tableSWLicenses, NULL, $qry);

		// -- columns
		$columns = [
				'person' => 'Uživatel', 'device' => 'Počítač',
				'licenseNumber' => 'Licenční číslo', 'validFrom' => 'Platné od',
				'invoiceNumber' => 'Doklad pořízení',
		];
		$this->qryPanelAddCheckBoxes($columns, 'columns', 'Zobrazit sloupce');
	}

	function linkedDevices ($toRecsId, $elementClass = '')
	{
		$tableId = 'mac.lan.swLicenses';

		$ld = [];

		$q[] = 'SELECT links.ndx, links.linkId AS linkId, links.srcRecId AS srcRecId, links.dstRecId AS dstRecId, devices.fullName AS fullName, devices.id as deviceId';
		array_push($q, ' FROM e10_base_doclinks AS links');
		array_push($q, ' LEFT JOIN [mac_lan_devices] as [devices] ON links.dstRecId = [devices].ndx');
		array_push($q, ' WHERE srcTableId = %s', $tableId, ' AND dstTableId = %s', 'mac.lan.devices', ' AND links.srcRecId IN %in', $toRecsId);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$di = ['text' => $r ['fullName'], 'prefix' => $r ['deviceId'], 'class' => $elementClass];
			$ld[$r['srcRecId']][] = $di;
		}

		return $ld;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako kompletní PDF soubor včetně příloh', 'icon' => 'icon-download',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xpdf',
				'data-filename' => $this->saveAsFileName('xpdf')
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = 'seznam-sw-licenci-'.utils::today('Y-m-d');
		$fn .= '.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->loadList();

		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;

		foreach ($this->list as $ndx => $row)
		{
			$recData = $this->tableSWLicenses->loadItem ($ndx);
			$engine->addDocument($this->tableSWLicenses, $ndx, $recData, 'mac.lan.ReportCardSWLicense');
		}

		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
	}
}
