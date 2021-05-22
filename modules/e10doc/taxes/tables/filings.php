<?php

namespace e10doc\taxes;

use \e10\utils, \e10\TableForm, \e10\DbTable;


/**
 * Class TableFilings
 * @package e10doc\taxes
 */
class TableFilings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.filings', 'e10doc_taxes_filings', 'Podání daňových přiznání a přehledů');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);
		if (!isset($recData ['dateIssue']))
			$recData ['dateIssue'] = utils::today();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if ($recData['ndx'])
		{
			$reportTypeDef = $this->app()->cfgItem('e10doc.taxes.reportTypes.' . $recData['reportType'], NULL);
			if ($reportTypeDef)
			{
				$propertiesEngine = $this->app()->createObject($reportTypeDef['propertiesEngine']);
				$propertiesEngine->load($recData['report'], $recData['ndx']);

				$recData ['title'] = $propertiesEngine->name();
			}
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['title'] === '')
		{
			$this->checkBeforeSave ($recData);
			$this->db()->query('UPDATE [e10doc_taxes_filings] SET [title] = %s', $recData['title'], ' WHERE ndx = %i', $recData['ndx']);
		}

		if ($recData['docState'] === 4000)
			$this->manageFiling($recData, 'create');
		elseif ($recData['docState'] === 8000)
			$this->manageFiling($recData, 'remove');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['title']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	function propertyEnabled ($recData, $groupId, $propertyId, $property, $loadedProperties)
	{
		if ($groupId === 'e10-CZ-TR-subjekt' && in_array($propertyId, ['e10-CZ-TR-prijmeni', 'e10-CZ-TR-jmeno', 'e10-CZ-TR-titul']))
		{
			if ($loadedProperties['e10-CZ-TR-subjekt']['e10-CZ-TR-typSubjektu'][0]['value'] === 'P')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-subjekt' && $propertyId === 'e10-CZ-TR-jmenoPrOsoby')
		{
			if ($loadedProperties['e10-CZ-TR-subjekt']['e10-CZ-TR-typSubjektu'][0]['value'] === 'F')
				return FALSE;
			return TRUE;
		}

		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-prijmeni', 'e10-CZ-TR-jmeno', 'e10-CZ-TR-datumNar', 'e10-CZ-TR-evidCislo']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] !== 'F')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-nazevPrOsoby', 'e10-CZ-TR-ICPrOsoby']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] !== 'P')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-kodPodOsoba']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] == '')
				return FALSE;
			return TRUE;
		}

		return parent::propertyEnabled ($recData, $groupId, $propertyId, $property, $loadedProperties);
	}

	public function manageFiling ($recData, $operation)
	{
		$taxReportDef = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$recData['reportType'], NULL);
		$trEngine = $this->app()->createObject($taxReportDef['filingEngine']);
		$trEngine->init();
		if ($operation === 'create')
			$trEngine->createFiling ($recData);
		elseif ($operation === 'remove')
			$trEngine->removeFiling ($recData);
	}
}


/**
 * Class FormFiling
 * @package e10doc\taxes
 */
class FormFiling extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Obsah', 'icon' => 'icon-list'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addList('properties', '', TableForm::loAddToFormLayout);
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput('dateIssue');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}

	function checkLoadedList ($list)
	{
		if ($list->listId === 'properties' && !count($list->data))
		{
			$rowNumber = 0;
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND recid = %i ORDER BY ndx";
			$query = $this->table->app()->db()->query ($sql, 'e10doc.taxes.reports', $this->recData ['report']);
			foreach ($query as $row)
			{
				$p = $list->allProperties [$row['property']];
				$item = [
						'rowNumber' => $rowNumber, 'ndx' => 0, 'property' => $row ['property'], 'name' => $p ['name'],
						'group' => $row ['group'], 'subtype' => $row ['subtype'], 'note' => $row ['note']
				];
				if ((isset ($p ['type'])) AND ($p ['type'] === 'memo'))
					$item['value'] = $row['valueMemo'];
				elseif ((isset ($p ['type'])) AND ($p ['type'] === 'date'))
					$item['value'] = $row['valueDate'];
				elseif ((isset ($p ['type'])) AND ($p ['type'] === 'reference'))
					$item['value'] = $row['valueNum'];
				else
					$item['value'] = $row['valueString'];
				$list->dataGrouped [$row ['group']][$row ['property']][] = $item;
				$list->data [] = $item;
				$rowNumber++;
			}
		}
	}
}
