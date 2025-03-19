<?php

namespace e10doc\accBal;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Json;
use \Shipard\Utils\Utils;


/**
 * class TableBalances
 */
class TableBalances extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.accBal.balances', 'e10doc_accBal_balances', 'Saldokonta');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $hdr;
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if ($recData['globalId'] == '')
			$recData['globalId'] = Utils::createToken(6, FALSE, TRUE);
	}
}


/**
 * class ViewBalances
 */
class ViewBalances extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i1'] = ['text' => '#'.$item['globalId'], 'class' => 'id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [balances].* ');
		array_push ($q, ' FROM [e10doc_accBal_balances] AS [balances]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [balances].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [balances].[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[balances].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormBalance
 */
class FormBalance extends TableForm
{
	public function renderForm ()
	{
		$cfgString = '';
		if ($this->recData['ndx'])
		{
			$balacesCfg = new \e10doc\accBal\libs\AccBalanceCfg($this->app());
			$balacesCfg->loadBalancesCfg($this->recData['ndx']);
			$cfgString = Json::lint($balacesCfg->balancesCfg[$this->recData['ndx']]);
		}

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Druh', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Konfigrace', 'icon' => 'user/code'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('order');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('globalId');
				$this->closeTab();
				$this->openTab (self::ltNone);
          $this->addContent([['pane' => 'padd5', 'type' => 'text', 'subtype' => 'code', 'paneTitle' => '', 'text' => $cfgString]], self::coFullSizeY);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailBalance
 */
class ViewDetailBalance extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.accBal.libs.dc.DCAccBalance');
	}
}
