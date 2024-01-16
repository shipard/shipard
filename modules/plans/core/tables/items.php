<?php

namespace plans\core;

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail;
use Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableItems
 */
class TableItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('plans.core.items', 'plans_core_items', 'Položky plánu', 9876);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['subject']];

		$ndx = $recData['ndx'] ?? 0;
		if ($ndx)
		{
			$linkedPersons = UtilsBase::linkedPersons ($this->app(), $this, $ndx);
			if (isset($linkedPersons[$ndx]) && count($linkedPersons[$ndx]))
			{
				$hdr ['info'][] = ['class' => 'info', 'value' => $linkedPersons[$ndx]];
			}
		}
		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData['iid']) || $recData['iid'] == '')
		{
			$recData['iid'] = Utils::createToken(5, FALSE, TRUE);
		}

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * Class ViewItems
 */
class ViewItems extends TableView
{
	public function init ()
	{
		parent::init();

//		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;
    $this->type = 'form';
    $this->objectSubType = TableView::vsMain;
    //$this->enableDetailSearch = FALSE;
    $this->fullWidthToolbar = TRUE;


		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['subject'];
		//$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		//$listItem ['t2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [plans_core_items]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [subject] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[subject]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormItem
 */
class FormItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$planCfg = $this->app()->cfgItem('plans.plans.'.$this->recData['plan'], NULL);
		$useWorkOrders = $planCfg['useWorkOrders'] ?? 0;
		$useCustomer = $planCfg['useCustomer'] ?? 0;
		$useProjectId = $planCfg['useProjectId'] ?? 0;
		$usePrice = $planCfg['usePrice'] ?? 0;
		$useAnnots = $planCfg['useAnnots'] ?? 0;
		$useText = $planCfg['useText'] ?? 0;
		$useTeams = $planCfg['useTeams'] ?? 0;
		$plansWorkOrdersRows = $planCfg['plansWorkOrdersRows'] ?? 0;

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		if ($useText)
			$tabs ['tabs'][] = ['text' => 'Popis', 'icon' => 'formText'];
		if ($plansWorkOrdersRows)
			$tabs ['tabs'][] = ['text' => 'Položky', 'icon' => 'system/formSettings'];
		if ($useAnnots)
			$tabs ['tabs'][] = ['text' => 'Anotace', 'icon' => 'table/e10pro.kb.annots'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('subject');
          $this->addColumnInput ('note');

					if ($useWorkOrders)
					{
						$this->addColumnInput ('workOrder');
						$this->addColumnInput ('workOrderParent');
					}
					if ($useProjectId)
          	$this->addColumnInput ('projectId');

					if ($useCustomer)
          	$this->addColumnInput ('personCustomer');

					if ($usePrice)
					{
						$this->addColumnInput ('price');
						$this->addColumnInput ('currency');
					}

					if ($useTeams)
          	$this->addColumnInput ('team');

					$this->addColumnInput ('datePlanBegin');
					$this->addColumnInput ('dateDeadline');

					$this->addColumnInput ('itemState');
					$this->addList ('doclinksPersons', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('isPrivate');
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				if ($useText)
				{
					$this->openTab (TableForm::ltNone);
						$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
					$this->closeTab ();
				}
				if ($plansWorkOrdersRows)
				{
					$this->openTab ();
						$this->addList ('itemsParts');
					$this->closeTab ();
				}
				if ($useAnnots)
				{
					$this->openTab (TableForm::ltNone);
						$this->addViewerWidget ('e10pro.kb.annots', 'default', ['docTableNdx' => $this->table->ndx, 'docRecNdx' => $this->recData['ndx']], TRUE);
					$this->closeTab ();
				}

        $this->openTab ();
					$this->addColumnInput ('ownerItem');
					$this->addColumnInput ('author');
					$this->addColumnInput ('plan');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'plans.core.itemsParts' && $srcColumnId === 'refId3')
		{
			$cp = [
				'workOrderNdx' => strval ($recData['workOrder']),
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewDetailItem
 */
class ViewDetailItem extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('plans.core.libs.dc.PlanItemCore');
	}
}

