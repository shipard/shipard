<?php
namespace mac\lan;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableWGPeers
 */
class TableWGPeers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.wgPeers', 'mac_lan_wgPeers', 'Wireguard klienti');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if ($recData['peerKeyPrivate'] === '')
		{
			$privKey = '';
			$pubKey = '';

			$wge = new \mac\lan\libs3\WireguardEngine($this->app());
			$wge->generateKeyPair($privKey, $pubKey);

			$recData['peerKeyPrivate'] = $privKey;
			$recData['peerKeyPublic'] = $pubKey;
		}

		if ($recData['peerKeyPreshared'] === '')
		{
			$presharedKey = '';

			$wge = new \mac\lan\libs3\WireguardEngine($this->app());
			$wge->generateKeyPreshared($presharedKey);

			$recData['peerKeyPreshared'] = $presharedKey;
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
 * class ViewWGPeers
 */
class ViewWGPeers extends TableView
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

		if ($item['wgServerId'])
			$listItem ['i2'] = ['text' => $item['wgServerId'], '_icon' => 'system/iconSitemap', 'class' => 'label label-default'];
		else
			$listItem ['i2'] = ['text' => '!!!', '_icon' => 'system/iconSitemap', 'class' => 'label label-danger'];


		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => $item['peerAddr4'], '_icon' => 'system/iconSitemap', 'class' => 'label label-default'];
		if ($item['peerAddr6'] !== '')
			$listItem ['t2'][] = ['text' => $item['peerAddr6'], '_icon' => 'system/iconSitemap', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT wgPeers.*,');
		array_push ($q, ' wgServers.fullName AS wgServerFullName, wgServers.id AS wgServerId');
		array_push ($q, ' FROM [mac_lan_wgPeers] AS wgPeers');
		array_push ($q, ' LEFT JOIN mac_lan_wgServers AS wgServers ON wgPeers.wgServer = wgServers.ndx');
		array_push ($q, ' WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
				'wgPeers.[id] LIKE %s', '%'.$fts.'%',
				' OR wgPeers.[fullName] LIKE %s', '%'.$fts.'%',
				')');
		}

		$this->queryMain ($q, 'wgPeers.', ['[id]', '[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailWGPeer
 */
class ViewDetailWGPeer extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.WGPeerOverview');
	}
}


/**
 * Class FormWGPeer
 */
class FormWGPeer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs,TRUE);
				$this->openTab ();
					$this->addColumnInput ('wgServer');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('peerAddr4');
					$this->addColumnInput ('peerAddr6');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('peerKeyPrivate');
					$this->addColumnInput ('peerKeyPublic');
					$this->addColumnInput ('peerKeyPreshared');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
