<?php

namespace e10pro\soci;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewPanel;
use \Shipard\Viewer\TableView;


/**
 * class TableEntries
 */
class TableEntries extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.soci.entries', 'e10pro_soci_entries', 'Přihlášky');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

    $recData ['fullName'] = str_replace('  ', ' ', trim ($recData ['lastName'].' '.$recData ['firstName']));

		if (!isset($recData['docNumber']) || $recData['docNumber'] === '')
			$recData['docNumber'] = Utils::today('y').Utils::createToken(4, FALSE, TRUE);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
		{
			$hdr ['icon'] = 'icon-asterisk';

			$hdr ['info'][] = ['class' => 'title', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			return $hdr;
		}

		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = [
			'class' => 'title',
			'value' => [
				['text' => $recData['fullName'], 'class' => ''],
				['text' => $recData['docNumber'], 'class' => 'pull-right id'],
			]
		];

		return $hdr;
	}
}


/**
 * class ViewEntries
 */
class ViewEntries extends TableView
{
  var $entryKinds = NULL;
  var $fixedEntryKind = 0;
  var $periods = NULL;

	public function init ()
	{
    $this->periods = $this->app()->cfgItem('e10pro.soci.periods', NULL);

		parent::init();

		$this->setPanels (TableView::sptQuery);
    $this->createBottomTabs ();

		$this->setMainQueries ();
	}

  public function createBottomTabs ()
	{
		$enabledEntryKinds = NULL;
		$enabledEntryKindsStr = $this->queryParam('enabledEntryKinds');
		if ($enabledEntryKindsStr !== FALSE)
		{
			$enabledEntryKinds = [];
			$enabledEntryKindsParts = explode(',', $enabledEntryKindsStr);
			foreach ($enabledEntryKindsParts as $edbc)
			{
				$enabledEntryKinds[] = intval(trim($edbc));
			}
			if (!count($enabledEntryKinds))
				$enabledEntryKinds = NULL;
		}

		// -- entryKinds
		$this->entryKinds = $this->table->app()->cfgItem ('e10pro.soci.entriesKinds', FALSE);
		if ($this->entryKinds !== FALSE)
		{
			$activeEntryKind = 0;
			if (count ($this->entryKinds) > 1)
			{
				forEach ($this->entryKinds as $cid => $c)
				{
					if ($enabledEntryKinds && !in_array($cid, $enabledEntryKinds))
						continue;
					if (!$activeEntryKind)
						$activeEntryKind = $cid;
					$addParams = ['entryKind' => intval($cid)];
					$nbt = [
							'id' => $cid, 'title' => $c['sn'],
							'active' => ($activeEntryKind == $cid),
							'addParams' => $addParams
					];
					$bt [] = $nbt;
				}
				if (count($bt) > 1)
					$this->setBottomTabs ($bt);
				else
				{
					$this->addAddParam ('entryKind', $activeEntryKind);
					$this->fixedEntryKind = intval($activeEntryKind);
				}
			}
			else
				$this->addAddParam ('entryKind', key($this->entryKinds));
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId());

		$q = [];

    array_push ($q, 'SELECT entries.*,');
    array_push ($q, ' workOrders.docNumber AS woDocNumber, workOrders.title AS woTitle,');
    array_push ($q, ' places.fullName AS placeFullName, places.shortName AS placeShortName,');
    array_push ($q, ' persons.fullName AS personFullName');
    array_push ($q, ' FROM [e10pro_soci_entries] AS entries ');
    array_push ($q, ' LEFT JOIN e10mnf_core_workOrders AS workOrders ON entries.entryTo = workOrders.ndx');
    array_push ($q, ' LEFT JOIN e10_base_places AS places ON workOrders.place = places.ndx ');
    array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON entries.dstPerson = persons.ndx ');
		array_push ($q, ' WHERE 1');


		// -- bottom tabs
		if ($this->fixedEntryKind)
			array_push ($q, ' AND entries.entryKind = %i', $this->fixedEntryKind);
		elseif ($bottomTabId != 0)
			array_push ($q, ' AND entries.entryKind = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' entries.[firstName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR entries.[lastName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR entries.[email] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR entries.[phone] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR persons.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'entries.', ['[dateIssue] DESC', '[fullName]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
    $period = $this->periods[$item['entryPeriod']] ?? NULL;

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['i1'] = ['text' => $item['docNumber'], 'class' => 'id'];

    if ($item['fullName'] !== '')
		  $listItem ['t1'] = $item['fullName'];
    elseif ($item['personFullName'])
      $listItem ['t1'] = $item['personFullName'];

		$listItem ['i2'] = Utils::datef($item['dateIssue']);

    /*
    $listItem ['t2'] = [];
    $listItem ['t2'][] = ['text' => $item['email'], 'class' => 'label label-default', 'icon' => 'system/iconEnvelope'];
    $listItem ['t2'][] = ['text' => $item['email'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];
    */

    $listItem ['t2'] = [];
    if ($period)
      $listItem ['t2'][] = ['text' => $period['sn'], 'class' => 'label label-default', 'icon' => 'tables/e10pro.soci.periods'];

		$listItem ['t2'][] = ['text' => $item['woTitle'], 'class' => 'label label-default'];

		if ($item['placeShortName'] && $item['placeShortName'] !== '')
      $listItem ['t2'][] = ['icon' => 'tables/e10.base.places', 'text' => $item ['placeShortName'], 'class' => 'label label-default'];
		elseif ($item['placeFullName'])
      $listItem ['t2'][] = ['icon' => 'tables/e10.base.places', 'text' => $item ['placeFullName'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];
	}
}


/**
 * class ViewDetailEntry
 */
class ViewDetailEntry extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.soci.libs.dc.DCEntry');
	}
}


/**
 * class FormEntry
 */
class FormEntry extends TableForm
{
	public function renderForm ()
	{
    $entryKind = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$this->recData['entryKind']);

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/iconSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
        if (($entryKind['useInbox'] ?? 0))
        {
          $this->addList ('inbox', '', TableForm::loAddToFormLayout/*|TableForm::coColW12*/);
          $this->addSeparator(self::coH4);
        }
        $this->addColumnInput('entryTo');
        if ($entryKind['usePeriods'] ?? 0)
          $this->addColumnInput('entryPeriod');
        $this->addSeparator(self::coH4);
        if (($entryKind['inputPerson'] ?? 0) === 0)
        {
          $this->addColumnInput('firstName');
          $this->addColumnInput('lastName');
          $this->addColumnInput('birthday');
          $this->addColumnInput('email');
          $this->addColumnInput('phone');
        }
        else
        {
          $this->addColumnInput('dstPerson');
        }

				$this->addSeparator(self::coH3);
				if ($entryKind['useSaleType'] ?? 0)
					$this->addColumnInput('saleType');
				if ($entryKind['usePaymentPeriod'] ?? 0)
					$this->addColumnInput('paymentPeriod');
				if (($entryKind['useSaleType'] ?? 0) || ($entryKind['usePaymentPeriod'] ?? 0))
        	$this->addSeparator(self::coH3);

        $this->addColumnInput('dateIssue');
        $this->addSeparator(self::coH3);
        $this->addColumnInput ('note');
	  	$this->closeTab ();

      $this->openTab ();
        $this->addColumnInput('source');
        if (($entryKind['inputPerson'] ?? 0) === 0)
          $this->addColumnInput('dstPerson');
        $this->addColumnInput('entryKind');
      $this->closeTab ();

      $this->openTab (TableForm::ltNone);
        $this->addAttachmentsViewer ();
      $this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}

	public function listParams ($srcTableId, $listId, $listGroup, $recData)
	{
		if ($srcTableId === 'e10pro.soci.entries')
		{
			$cp = [
				'entryKind' => strval ($recData['entryKind']),
			];
			return $cp;
		}

		return [];
	}
}
