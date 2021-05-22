<?php

namespace mac\access;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\str, \e10\DataModel;
use E10\TableViewDetail;


/**
 * Class TableTags
 * @package mac\access
 */
class TableTags extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.tags', 'mac_access_tags', 'Přístupové klíče');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['keyHash'] = sha1($recData['keyValue']);
		if ($recData['id'] === '')
		{
			$recData['id'] = strval(base_convert(mt_rand(10000000000, 999999999999), 10, 36));
		}

		if ($recData['tagType'] != 1)
			$recData['ownTag'] = 0;

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}

	public function tagPerson($keyValue)
	{
		$kv = $keyValue;
		$kv = str::scannerString($kv);
		$kvh = sha1($kv);

		$tagExist = $this->db()->query('SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $kv, ' AND [docState] = %i', 4000, ' AND [tagType] = %i', 1)->fetch();
		if (!$tagExist)
			return FALSE;

		$now = new \DateTime();
		$assignmentExist = $this->db()->query('SELECT * FROM [mac_access_tagsAssignments] WHERE [tag] = %i', $tagExist['ndx'],
			' AND [docState] = %i', 4000, ' AND [person] != %i', 0,
			' AND (',
			' [validFrom] <= %t', $now, ' AND ([validTo] IS NULL OR [validTo] >= %t)', $now,
			')'
			)->fetch();

		if (!$assignmentExist)
			return FALSE;

		$result = ['tag' => $tagExist['ndx'], 'person' => $assignmentExist['person']];

		return $result;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return $this->app()->cfgItem ('mac.access.tagTypes.'.$recData['tagType'].'.icon', 'x-cog');
	}

	public function unAssignTag($tagValue, $assignType, $assignNdx)
	{
		$kv = $tagValue;

		$tagExist = $this->db()->query('SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $kv, ' AND [docState] = %i', 4000, ' AND [tagType] = %i', 1)->fetch();
		if (!$tagExist)
			return FALSE;

		$q = [];
		array_push ($q, 'SELECT assignments.*');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' WHERE assignments.[tag] = %i', $tagExist['ndx']);
		array_push ($q, ' AND assignments.[docState] IN %in', [4000, 8000]);

		if ($assignType === 'unassignFromPerson')
			array_push ($q, ' AND assignments.[assignType] = %i', 0, ' AND assignments.[person] = %i', $assignNdx);
		elseif ($assignType === 'unassignFromPlace')
			array_push ($q, ' AND assignments.[assignType] = %i', 1, ' AND assignments.[place] = %i', $assignNdx);
		else return FALSE;

		array_push ($q, ' ORDER BY assignments.validFrom DESC');

		$now = new \DateTime('-1 minute');

		/** @var \mac\access\TableTagsAssignments $tableAssignment */
		$tableAssignment = $this->app()->table('mac.access.tagsAssignments');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$isValid = 1;

			if ($r['validFrom'] && $r['validFrom'] > $now)
				$isValid = 0;

			if ($r['validTo'] && $r['validTo'] < $now)
				$isValid = 0;

			if (!$isValid)
				continue;

			$update = ['ndx' => $r['ndx'], 'validTo' => $now->format('Y-m-d H:i:00')];
			$tableAssignment->dbUpdateRec($update);
			$tableAssignment->docsLog($r['ndx']);
		}

		return TRUE;
	}

	public function assignTag($tagValue, $assignType, $assignNdx)
	{
		$kv = $tagValue;

		$tagExist = $this->db()->query('SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $kv, ' AND [docState] = %i', 4000, ' AND [tagType] = %i', 1)->fetch();
		if (!$tagExist)
			return FALSE;

		$now = new \DateTime();

		$item = [
			'tag' => $tagExist['ndx'],
			'assignType' => 0, 'person' => 0, 'place' => 0,
			'validFrom' => $now->format('Y-m-d H:i:00'),
			'docState' => 4000, 'docStateMain' => 2,
		];

		if ($assignType === 'assignToPerson')
		{
			$item['assignType'] = 0;
			$item['person'] = $assignNdx;
		}
		elseif ($assignType === 'assignToPlace')
		{
			$item['assignType'] = 1;
			$item['place'] = $assignNdx;
		}
		else
			return FALSE;

		$tableAssignment = $this->app()->table('mac.access.tagsAssignments');
		$newNdx = $tableAssignment->dbInsertRec($item);
		$tableAssignment->docsLog($newNdx);

		return TRUE;
	}
}


/**
 * Class ViewTags
 * @package mac\access
 */
class ViewTags extends TableView
{
	var $tagsAssignments = [];

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['keyValue'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['i2'] = $item['note'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->tagsAssignments[$item['pk']]))
		{
			$item['t2'] = [];
			foreach ($this->tagsAssignments[$item['pk']] as $ta)
			{
				if (isset($ta['person']))
				{
					$item['t2'][] = [
						'suffix' => utils::dateFromTo($ta['validFrom'], $ta['validTo'], NULL),
						'text' => $ta['person']['fullName'],
						'icon' => $ta['person']['icon'], 'class' => 'label label-default'
					];
				}
				elseif (isset($ta['place']))
				{
					$item['t2'][] = [
						'suffix' => utils::dateFromTo($ta['validFrom'], $ta['validTo'], NULL),
						'text' => $ta['place']['fullName'],
						'icon' => 'icon-map-marker', 'class' => 'label label-default'
					];
				}
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [mac_access_tags] AS [tags]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [tags].[keyValue] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [tags].[id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [tags].[note] LIKE %s', '%'.$fts.'%');

			$keyValue = str::scannerString($fts);
			$keyHash = sha1($keyValue);
			array_push ($q, ' OR [tags].[keyHash] = %s', $keyHash);

			// -- person
			array_push($q, ' OR EXISTS (SELECT ta.ndx FROM mac_access_tagsAssignments AS ta ',
				' LEFT JOIN [e10_persons_persons] AS persons ON ta.person = persons.ndx AND [ta].assignType = %i', 0,
				' WHERE ta.tag = tags.ndx AND persons.fullName LIKE %s', '%'.$fts.'%',
				')');

			// -- place
			array_push($q, ' OR EXISTS (SELECT ta.ndx FROM mac_access_tagsAssignments AS ta ',
				' LEFT JOIN [e10_base_places] AS places ON ta.place = places.ndx AND [ta].assignType = %i', 1,
				' WHERE ta.tag = tags.ndx AND places.fullName LIKE %s', '%'.$fts.'%',
				')');

			array_push ($q, ')');
		}

		$this->queryMain ($q, 'tags.', ['[id]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$tablePersons = $this->app()->table('e10.persons.persons');

		// -- tags assignments
		$q [] = 'SELECT assignments.*, tags.id AS tagId,';
		array_push ($q, ' persons.fullName as personName, persons.id AS personId, persons.company AS personCompany, persons.personType,');
		array_push ($q, ' places.fullName as placeName');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON assignments.person = persons.ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS tags ON assignments.tag = tags.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON assignments.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND assignments.[tag] IN %in', $this->pks);
		array_push ($q, ' AND assignments.[docState] IN %in', [1000, 4000, 8000]);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], //'dsc' => $docStateClass,
				'assignType' => $r['assignType'],
				'validFrom' => $r['validFrom'], 'validTo' => $r['validTo'],
			];

			if ($r['assignType'] === 0)
			{ // person
				$item['person'] = [
					'fullName' => $r['personName'], 'ndx' => $r['person'],
					'personType' => $r['personType'], 'company' => $r['personCompany'],
				];
				$item['useCautionMoney'] = $r['useCautionMoney'];
				$item['cautionMoneyAmount'] = $r['cautionMoneyAmount'];
				$item['person']['icon'] = $tablePersons->tableIcon($item['person']);
			}
			elseif ($r['assignType'] === 1)
			{ // place
				$item['place'] = [
					'fullName' => $r['placeName']
				];
			}

			$this->tagsAssignments[$r['tag']][] = $item;
		}
	}
}


/**
 * Class FormTag
 * @package mac\access
 */
class FormTag extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Klíč', 'icon' => 'icon-key'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('tagType');

					$co = $this->recData['tagType'] == 1 ? DataModel::coScanner : 0;
					$this->addColumnInput ('keyValue', $co);

					if ($this->recData['tagType'] == 1)
						$this->addColumnInput ('ownTag');

					$this->addColumnInput ('note');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		if ($newDocState === 9800 || $newDocState === 8000)
			return parent::validNewDocumentState($newDocState, $saveData);
		if (isset ($saveData['recData']['keyValue']) && $saveData['recData']['keyValue'] !== '')
		{
			// -- duplicate key value
			$kh = sha1($saveData['recData']['keyValue']);
			$exist = $this->app()->db()->query('SELECT [ndx] FROM [mac_access_tags] WHERE [keyHash] = %s', $kh,
				' AND [ndx] != %i', isset($saveData['recData']['ndx']) ? $saveData['recData']['ndx'] : 0, ' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('keyValue', utils::es ('Tento klíč je už evidován.'));
				return FALSE;
			}
		}

		return parent::validNewDocumentState($newDocState, $saveData);
	}
}


/**
 * Class ViewDetailTag
 * @package mac\access
 */
class ViewDetailTag extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.access.dc.AccessTag');
	}
}
