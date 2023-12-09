<?php

namespace hosting\core;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \e10\utils, \E10\DbTable;


/**
 * Class TablePartners
 */
class TablePartners extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.partners', 'hosting_core_partners', 'Partneři');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		//$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $recData ['id'], 'class' => 'pull-right']]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']]]];

		return $hdr;
	}

	public function partnerInfo ($ndx)
	{
		$recData = $this->loadItem($ndx);
		if (!$recData)
			return FALSE;

		$info = [
			'name' => $recData['name'],
			'webUrl' => $recData['webUrl'],
			'supportEmail' => $recData['supportEmail'],
			'supportPhone' => $recData['supportPhone'],
			'logoPartner' => $this->partnerLogo('logo', $recData['logoPartner']),
			'logoIcon' => $this->partnerLogo('icon', $recData['logoIcon']),
		];

		return $info;
	}

	function partnerLogo ($type, $attNdx)
	{
		$logo = [];
		$r = [];

		if ($attNdx)
			$r = $this->db()->query('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $attNdx)->fetch();
		if ($r)
		{
			$logo['fileName'] = $r['path'] . $r['filename'];
		}

		return $logo;
	}

	function partnerDSStats ($partnerNdx)
	{
		$info = [
			'ALL' => ['usageTotal' => 0, 'cntDocuments12m' => 0, 'cnt' => 0],
			'NONPROD' => ['usageTotal' => 0, 'cntDocuments12m' => 0, 'cnt' => 0]
		];


		$q[] = 'SELECT ds.condition, COUNT(*) AS cnt, SUM(stats.usageDb) as usageDb, SUM(stats.usageFiles) as usageFiles, SUM(stats.usageTotal) as usageTotal,';
		array_push($q, ' SUM(stats.cntDocuments12m) as cntDocuments12m, stats.cntUsersAll1m as cntUsersAll1m,');
		array_push($q, ' stats.datasource, ds.name as dsName');
		array_push($q, ' FROM hosting_core_dsStats AS stats');
		array_push($q, ' LEFT JOIN hosting_core_dataSources AS ds ON stats.dataSource = ds.ndx');
		array_push($q, ' WHERE ds.docState = 4000');
		array_push($q, ' AND ds.partner = %i', $partnerNdx);
		array_push($q, ' GROUP BY 1');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$c = strval ($r['condition']);
			$info[$c] = $r->toArray();

			if ($c !== '2')
			{
				$info['NONPROD']['usageTotal'] += $r['usageTotal'];
				$info['NONPROD']['cntDocuments12m'] += $r['cntDocuments12m'];
				$info['NONPROD']['cnt'] += $r['cnt'];
			}

			$info['ALL']['usageTotal'] += $r['usageTotal'];
			$info['ALL']['cntDocuments12m'] += $r['cntDocuments12m'];
			$info['ALL']['cnt'] += $r['cnt'];
		}

		return $info;
	}

	public function usersPartners()
	{
		$list = [];

		$q[] = 'SELECT pp.*, partners.ndx AS partnerNdx, partners.name AS partnerName FROM [hosting_core_partnersPersons] AS pp';
		array_push ($q, ' LEFT JOIN [hosting_core_partners] AS partners ON pp.partner = partners.ndx');
		array_push ($q, ' WHERE person = %i', $this->app()->userNdx());
		array_push ($q, ' ORDER BY partners.name, partners.ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pndx = $r['partnerNdx'];
			if (isset ($list[$pndx]))
				continue;

			$list[$pndx] = ['ndx' => $pndx, 'name' => $r['partnerName']];
		}

		return $list;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$title = $recData['name'];
		$info = [
			'title' => $title, 'docID' => $recData['gid']
		];

		$info ['persons']['to'][] = $recData['owner'];
		$info ['persons']['from'][] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

		return $info;
	}
}


/**
 * Class ViewPartners
 */
class ViewPartners extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
		$this->linesWidth = 25;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [partners].*, [owners].[fullName] AS [ownerFullName]';
		array_push($q, ' FROM [hosting_core_partners] AS [partners]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [owners] ON [partners].[owner] = [owners].[ndx]');
		//array_push($q, ' LEFT JOIN [hosting_core_portals] AS [portals] ON [partners].[portal] = [portals].[ndx]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([partners].[name] LIKE %s OR [owners].[fullName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[partners].', ['[partners].[name]', '[partners].[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];

		$listItem ['t2'] = [['text' => $item['ownerFullName'], 'icon' => 'icon-building', 'class' => '']];

		if ($item['portalName'])
			$listItem ['t2'][] = ['text' => $item['portalName'], 'icon' => 'icon-umbrella', 'class' => 'pull-right'];

		$listItem ['i1'] = '#'.$item['ndx'];

		return $listItem;
	}
}


/**
 * Class ViewDetailPartner
 */
class ViewDetailPartner extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('hosting.core.libs.dc.DCPartner');
	}
}


/**
 * Class ViewDetailPartnerPersons
 */
class ViewDetailPartnerPersons extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('hosting.core.partnersPersons', 'hosting.core.ViewPartnersPersons',
			['partner' => $this->item ['ndx']]);
	}
}


/**
 * Class FormPartner
 */
class FormPartner extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('owner');

					$this->addColumnInput ('webUrl');
					$this->addColumnInput ('supportEmail');
					$this->addColumnInput ('supportPhone');

					$this->addColumnInput ('logoPartner');
					$this->addColumnInput ('logoIcon');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('gid');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

