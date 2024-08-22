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

		if (isset($recData['docNumber']) && $recData['docNumber'] === '')
		{
			$entryKind = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$recData['entryKind'], NULL);
			if ($entryKind && $entryKind['docNumberType'] === 0)
				$recData['docNumber'] = Utils::today('y').mt_rand(1000, 9999);
			elseif ($entryKind && $entryKind['docNumberType'] === 1)
			{
				if (!Utils::dateIsBlank($recData['birthday']))
				{
					$bd = Utils::createDateTime($recData['birthday']);
					if ($bd)
						$recData['docNumber'] = $bd->format('dmY');
				}
			}
		}
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if (isset($recData['docNumber']) && $recData['docNumber'] === '')
		{
			$entryKind = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$recData['entryKind'], NULL);
			if ($entryKind && $entryKind['docNumberType'] === 0)
				$recData['docNumber'] = Utils::today('y').mt_rand(1000, 9999);
			elseif ($entryKind && $entryKind['docNumberType'] === 1)
			{
				if (!Utils::dateIsBlank($recData['birthday']))
				{
					$bd = Utils::createDateTime($recData['birthday']);
					if ($bd)
						$recData['docNumber'] = $bd->format('dmY');
				}
			}

			$this->app()->db()->query ('UPDATE [e10pro_soci_entries] SET [docNumber] = %s', $recData['docNumber'], ' WHERE [ndx] = %i', $recData['ndx']);
		}

		if ($recData['docState'] === 4000)
		{ // valid - add to persons on workOrder
			$this->checkEntryToPersonExist($recData);
		}
	}

	protected function checkEntryToPersonExist($entryRecData)
	{
		if (!$entryRecData['dstPerson'])
			return;

		$entryToRecData = $this->app()->loadItem($entryRecData['entryTo'], 'e10mnf.core.workOrders');
		if (!$entryToRecData)
			return;

		$woKind = $this->app()->cfgItem('e10mnf.workOrders.kinds.'.$entryToRecData['docKind'], NULL);
		if (!$woKind)
			return;

		if (!intval($woKind['usePersonsList'] ?? 0))
			return;

		$existedPerson = $this->db()->query('SELECT * FROM [e10mnf_core_workOrdersPersons]',
										' WHERE [workOrder] = %i', $entryRecData['entryTo'],
										' AND [person] = %i', $entryRecData['dstPerson'])->fetch();
		if ($existedPerson)
			return;

		$maxRowOrderRec = $this->db()->query('SELECT * FROM [e10mnf_core_workOrdersPersons]',
			' WHERE [workOrder] = %i', $entryRecData['entryTo'],
			' ORDER BY rowOrder DESC LIMIT 1')->fetch();

		$maxRowOrder = $maxRowOrderRec['rowOrder'] ?? 0;
		$maxRowOrder += 100;

		$np = [
			'workOrder' => $entryRecData['entryTo'], 'person' => $entryRecData['dstPerson'],
			'rowOrder' => $maxRowOrder,
		];
		$this->db()->query('INSERT INTO [e10mnf_core_workOrdersPersons]', $np);
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

	public function tableIcon ($recData, $options = NULL)
	{
		$entryState = $this->app()->cfgItem ('e10pro.soci.entryStates.'.$recData['entryState'], NULL);

		if ($entryState)
			return $entryState['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = [
			'title' => '',
			'docID' => $recData['docNumber'],
		];

		if (isset($recData['dstPerson']) && $recData['dstPerson'])
			$info ['persons']['to'][] = $recData['dstPerson'];

		return $info;
	}
}


/**
 * class ViewEntries
 */
class ViewEntries extends TableView
{
  var $entryKinds = NULL;
  var $fixedEntryKind = 0;
	var $entryKindCfg = NULL;
  var $periods = NULL;

	public function init ()
	{
    $this->periods = $this->app()->cfgItem('e10pro.soci.periods', NULL);
		$this->entryKinds = $this->table->app()->cfgItem ('e10pro.soci.entriesKinds', FALSE);

		parent::init();

		$this->setPanels (TableView::sptQuery);
    $this->createBottomTabs ();


		$mq = [];

		if ($this->periods)
		{
			$today = Utils::today();
			$todayMonth = intval($today->format('m'));

			if ($todayMonth < 7)
				$periods = \e10\sortByOneKey ($this->periods, 'dateBegin', TRUE, TRUE);
			else
				$periods = \e10\sortByOneKey ($this->periods, 'dateBegin', TRUE, FALSE);

			foreach ($periods as $periodId => $period)
			{
				$mq[] = ['id' => 'AY_'.$periodId, 'title' => $period['sn'], 'icon' => 'system/filterActive', 'side' => 'left'];
			}
		}
		else
		{
			$mq[] = ['id' => 'active', 'title' => 'Aktivní', 'icon' => 'system/filterActive'];
		}

		$mq[] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
		$mq[] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
		$mq[] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];


		$this->setMainQueries ($mq);
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
					$this->entryKindCfg = $this->entryKinds[$this->fixedEntryKind];
				}
			}
			else
			{
				$this->fixedEntryKind = intval(key($this->entryKinds));
				$this->addAddParam ('entryKind', $this->fixedEntryKind);
				$this->entryKindCfg = $this->entryKinds[$this->fixedEntryKind];
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = intval($this->bottomTabId());
		$mainQuery = $this->mainQueryId ();

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
			array_push ($q, ' OR entries.[docNumber] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR entries.[note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR persons.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR DATE_FORMAT([birthday], \'%m%d%Y\') LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$qv = $this->queryValues();

		if (isset ($qv['paymentPeriods']))
			array_push ($q, ' AND entries.paymentPeriod IN %in', array_keys($qv['paymentPeriods']));
		if (isset ($qv['saleTypes']))
			array_push ($q, ' AND entries.saleType IN %in', array_keys($qv['saleTypes']));
		if (isset ($qv['wo']))
			array_push ($q, ' AND entries.entryTo IN %in', array_keys($qv['wo']));
		if (isset ($qv['places']))
			array_push ($q, ' AND workOrders.place IN %in', array_keys($qv['places']));

		if (isset ($qv['persons']))
		{
			array_push ($q,
				' AND EXISTS (SELECT ndx FROM e10_base_doclinks AS l WHERE linkId = %s', 'e10mnf-workRecs-admins',
				' AND srcTableId = %s', 'e10mnf.core.workOrders', ' AND l.srcRecId = entries.entryTo',
				' AND l.dstRecId IN %in', array_keys($qv['persons']), ')'
				);
		}

		if (substr($mainQuery, 0, 3) === 'AY_')
		{
			//$userPeriodId = substr($mainQuery, 3);
			//array_push ($q, ' AND entries.[entryPeriod] = %s', $userPeriodId);
			$userPeriodId = 'AY'.substr($mainQuery, 3);
			array_push ($q, ' AND workOrders.[usersPeriod] = %s', $userPeriodId);

			array_push ($q, ' AND [entries].[docStateMain] != %i', 4);
		}

		if ($mainQuery === 'active' || $mainQuery === '')
		{
			if ($fts !== 0)
				array_push($q, ' AND [entries].[docStateMain] != 4');
			else
				array_push($q, ' AND [entries].[docStateMain] < 4');
		}
		if ($mainQuery === 'archive')
			array_push ($q, ' AND [entries].[docStateMain] = %i', 5);
		if ($mainQuery === 'trash')
			array_push ($q, ' AND [entries].[docStateMain] = 4');


		array_push ($q, ' ORDER BY [entries].[docStateMain], [entries].[dateIssue] DESC, entries.lastName, entries.firstName ');
		array_push ($q, $this->sqlLimit ());

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

		$paramsPaymentPeriods = new \Shipard\UI\Core\Params ($this->app());
		$paramsPaymentPeriods->addParam ('checkboxes', 'query.paymentPeriods', ['cfg' => 'e10pro.soci.paymentPeriods', 'cfgTitleId' => 'fn']);
		$qry[] = ['id' => 'paymentPeriods', 'style' => 'params', 'title' => 'Platba na období', 'params' => $paramsPaymentPeriods];

		$paramsSaleTypes = new \Shipard\UI\Core\Params ($this->app());
		$paramsSaleTypes->addParam ('checkboxes', 'query.saleTypes', ['cfg' => 'e10pro.soci.saleTypes', 'cfgTitleId' => 'fn']);
		$qry[] = ['id' => 'saleTypes', 'style' => 'params', 'title' => 'Sleva', 'params' => $paramsSaleTypes];

		$placesNdxs = [];
		$paramsWO = new \Shipard\UI\Core\Params ($this->app());
		$chbxWO = [];
		foreach ($this->entryKinds as $ekId => $ekCfg)
		{
			if (intval($ekCfg['workOrderKind'] ?? 0))
			{
				$woKind = $this->app()->cfgItem('e10mnf.workOrders.kinds.'.$ekCfg['workOrderKind'], NULL);
				if ($woKind)
				{
					$label = $woKind['fullName'];
					$woRows = $this->db()->query(
											'SELECT * FROM [e10mnf_core_workOrders] WHERE [docKind] = %i', $ekCfg['workOrderKind'],
											' AND docState = %i', 1200,
											' ORDER BY title, docNumber');
					foreach ($woRows as $wor)
					{
						$chbxWO[$wor['ndx']] = ['title' => $wor['title'], 'id' => $wor['ndx']];
						if ($label)
						{
							$chbxWO[$wor['ndx']]['label'] = $label;
							$label = NULL;
						}

						if (!in_array($wor['place'], $placesNdxs))
							$placesNdxs[] = $wor['place'];
					}
				}
			}
		}
		if (count($chbxWO))
		{
			$paramsWO->addParam ('checkboxes', 'query.wo', ['items' => $chbxWO]);
			$qry[] = ['id' => 'wo', 'style' => 'params', 'title' => 'Přihláška do', 'params' => $paramsWO];
		}

		// -- wo persons
		$qp = [];
		array_push($qp, 'SELECT DISTINCT persons.ndx, persons.fullName');
		array_push($qp, ' FROM e10_base_doclinks AS [links]');
		array_push($qp, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [links].dstRecId = [persons].ndx');
		array_push($qp, ' WHERE linkId = %s', 'e10mnf-workRecs-admins');
		array_push($qp, ' AND srcTableId = %s', 'e10mnf.core.workOrders');
		array_push($qp, ' ORDER BY persons.fullName');
		$personsRows = $this->db()->query($qp);
		$chbxPersons = [];
		foreach ($personsRows as $pr)
			$chbxPersons[$pr['ndx']] = ['title' => $pr['fullName'], 'id' => $pr['ndx']];

		if (count($chbxPersons))
		{
			$paramsPersons = new \Shipard\UI\Core\Params ($this->app());
			$paramsPersons->addParam ('checkboxes', 'query.persons', ['items' => $chbxPersons]);
			$qry[] = ['id' => 'persons', 'style' => 'params', 'title' => 'Ke komu', 'params' => $paramsPersons];
		}

		// -- places
		if (count($placesNdxs))
		{
			$paramsPlaces = new \Shipard\UI\Core\Params ($this->app());
			$chbxPlaces = [];

			$placesRows = $this->db()->query('SELECT * FROM [e10_base_places] WHERE [ndx] IN %in', $placesNdxs);
			foreach ($placesRows as $pr)
			{
				$chbxPlaces[$pr['ndx']] = ['title' => $pr['shortName'], 'id' => $pr['ndx']];
			}

			$paramsPlaces->addParam ('checkboxes', 'query.places', ['items' => $chbxPlaces]);
			$qry[] = ['id' => 'places', 'style' => 'params', 'title' => 'Místo', 'params' => $paramsPlaces];
		}

		$panel->addContent(['type' => 'query', 'query' => $qry]);
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
        if ($entryKind['useTestDrive'] ?? 0)
					$this->addColumnInput('testDriveWanted');
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

				if (intval($entryKind['useItem'] ?? 0))
				{
					$this->addColumnInput('item1');
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
				$this->addSeparator(self::coH2);
				$this->addColumnInput('entryState');
        $this->addSeparator(self::coH3);
				$this->addColumnInput('datePeriodBegin');
				$this->addColumnInput('datePeriodEnd');
	  	$this->closeTab ();

      $this->openTab ();
			if ($entryKind['usePeriods'] ?? 0)
			{
				$this->addColumnInput('nextYearContinue');
				$this->addColumnInput('nextYearPayment');
				$this->addSeparator(self::coH3);
			}
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
