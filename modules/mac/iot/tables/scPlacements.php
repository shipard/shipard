<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableSCPlacements
 * @package mac\iot
 */
class TableSCPlacements extends DbTable
{
	CONST ptWorkplace = 0;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scPlacements', 'mac_iot_scPlacements', 'Zařazení senzorů a ovládácích prvků do aplikace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
	//	$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//return $this->app()->cfgItem ('mac.control.controlsKinds.'.$recData['controlKind'].'.icon', 'x-cog');

		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewSCPlacements
 * @package mac\iot
 */
class ViewSCPlacements extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		//$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['placementTo'] == TableSCPlacements::ptWorkplace)
		{
			$listItem ['t2'][] = ['text' => $item['workplaceName'], 'class' => 'label label-default', 'icon' => 'icon-sun-o'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [placements].*, ';
		array_push ($q, ' workplaces.name AS [workplaceName]');
		array_push ($q, ' FROM [mac_iot_scPlacements] AS [placements]');
		array_push ($q, ' LEFT JOIN [terminals_base_workplaces] AS [workplaces] ON [placements].workplace = [workplaces].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [placements].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [workplaces].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'workplaces.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSCPlacement
 * @package mac\iot
 */
class FormSCPlacement  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Zařazení', 'icon' => 'icon-hand-pointer-o'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('placementTo');
					$this->addSeparator(self::coH2);

					if ($this->recData['placementTo'] == TableSCPlacements::ptWorkplace)
					{
						$this->addColumnInput('workplace');
					}

					$this->addSeparator(self::coH2);
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

					$this->addColumnInput('mainMenu');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSCPlacement
 * @package mac\iot
 */
class ViewDetailSCPlacement extends TableViewDetail
{
}

