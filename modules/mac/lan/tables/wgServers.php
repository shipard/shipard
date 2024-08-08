<?php
namespace mac\lan;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Report\FormReport;


/**
 * class TableWGServers
 */
class TableWGServers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.wgServers', 'mac_lan_wgServers', 'Wireguard servery');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if ($recData['keyPrivate'] === '')
		{
			$privKey = '';
			$pubKey = '';

			$wge = new \mac\lan\libs3\WireguardEngine($this->app());
			$wge->generateKeyPair($privKey, $pubKey);

			$recData['keyPrivate'] = $privKey;
			$recData['keyPublic'] = $pubKey;
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewWGServers
 */
class ViewWGServers extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT wgServers.*, lans.shortName as lanShortName';
		array_push ($q, ' FROM [mac_lan_wgServers] AS wgServers');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON wgServers.lan = lans.ndx');
		array_push ($q, ' WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
				'wgServers.[id] LIKE %s', '%'.$fts.'%',
				' OR wgServers.[fullName] LIKE %s', '%'.$fts.'%',
				')');
		}

		$this->queryMain ($q, 'wgServers.', ['[id]', '[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailWGServer
 */
class ViewDetailWGServer extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.WGServerOverview');
	}
}


/**
 * class FormWGServer
 */
class FormWGServer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs,TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('lan');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('placement');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('listenPort');
					$this->addColumnInput ('endpoint');
					$this->addColumnInput ('ifaceAddr4');
					$this->addColumnInput ('ifaceAddr6');
					$this->addColumnInput ('dns');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('keyPrivate');
					$this->addColumnInput ('keyPublic');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
