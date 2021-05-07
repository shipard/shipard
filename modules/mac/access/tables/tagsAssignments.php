<?php

namespace mac\access;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableTagsAssignments
 * @package mac\access
 */
class TableTagsAssignments extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.tagsAssignments', 'mac_access_tagsAssignments', 'Přiřazení Přístupových klíčů');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (utils::dateIsBlank($recData['validTo']))
		{
			$recData['validTo'] = NULL;
		}

		$useTagsCautionMoney = intval($this->app()->cfgItem ('options.macAccess.useTagsCautionMoney', 0));


		if (isset($recData['useCautionMoney']) && !$recData['useCautionMoney'])
			$recData['cautionMoneyAmount'] = 0.0;

		if ($useTagsCautionMoney && isset($recData['useCautionMoney']) && $recData['useCautionMoney'] && $recData['cautionMoneyAmount'] == 0.0)
			$recData['cautionMoneyAmount'] = intval($this->app()->cfgItem ('options.macAccess.tagsCautionMoneyAmount', 0));;

		if ($recData['useCautionMoney'] && $recData['person'] && !$recData['cautionMoneyPayer'])
			$recData['cautionMoneyPayer'] = $recData['person'];

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}
}


/**
 * Class ViewTagsAssignments
 * @package mac\access
 */
class ViewTagsAssignments extends TableView
{
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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];
		$listItem ['i2'] = ['text' => $item['tagId'], 'icon' => 'icon-key'];

		$listItem ['t2'] = utils::datef($item['validFrom'], '%d, %T').' → ';
		if ($item['validTo'])
			$listItem ['t2'] .= utils::datef($item['validTo'], '%d, %T');

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT assignments.*, persons.fullName as personName, persons.id AS personId, tags.id AS tagId ';
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON assignments.person = persons.ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS tags ON assignments.tag = tags.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' persons.[fullName] LIKE %s', '%'.$fts.'%');

			$keyValue = str::scannerString($fts);
			$keyHash = sha1($keyValue);
			array_push ($q, ' OR tags.[keyHash] = %s', $keyHash);

			array_push ($q, ')');
		}

		$this->queryMain ($q, 'assignments.', ['[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailTagAssignment
 * @package mac\access
 */
class ViewDetailTagAssignment extends TableViewDetail
{
}


/**
 * Class FormTagAssignment
 * @package mac\access
 */
class FormTagAssignment extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$useTagsCautionMoney = intval($this->app()->cfgItem ('options.macAccess.useTagsCautionMoney', 0));
		$cautionMoneyDocsMode = intval($this->app()->cfgItem ('options.macAccess.cautionMoneyDocsMode', 0));
		$assignTagsToRooms = intval($this->app()->cfgItem ('options.macAccess.useAssignTagsToRooms', 0));

		if ($this->recData['tag'])
		{
			$tagRecData = $this->app()->db()->query('SELECT [tagType], [ownTag] FROM [mac_access_tags] WHERE [ndx] = %i', $this->recData['tag'])->fetch();
			if ($tagRecData && ($tagRecData['tagType'] != 1 || $tagRecData['ownTag']))
				$useTagsCautionMoney = 0;
		}

		$tabs ['tabs'][] = ['text' => 'Klíč', 'icon' => 'icon-key'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('tag');
					if ($assignTagsToRooms)
						$this->addColumnInput ('assignType');

					if ($this->recData['assignType'] == 0)
					{
						$co = 0;
						if ((!isset($this->recData['ndx']) || !$this->recData['ndx']) && $this->recData['tag'])
							$co = self::coFocus;

						$this->addColumnInput('person', $co);

						if ($useTagsCautionMoney)
						{
							$this->addColumnInput('useCautionMoney');
							if ($this->recData['useCautionMoney'])
								$this->addColumnInput('cautionMoneyAmount');

							$this->addColumnInput('cautionMoneyPayer');

							if ($cautionMoneyDocsMode === 2)
							{
								$docsCO = $this->app()->hasRole('finance') ? 0 : self::coReadOnly;
								$this->addColumnInput('docCautionMoneyPay', $docsCO);
								$this->addColumnInput('docCautionMoneyRefund', $docsCO);
							}
						}
					}
					elseif ($this->recData['assignType'] == 1)
					{
						$this->addColumnInput('place');
					}

					$this->addSeparator(self::coH2);
					$this->addColumnInput('validFrom');
					$this->addColumnInput('validTo');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
