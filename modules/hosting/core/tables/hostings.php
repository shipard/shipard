<?php

namespace hosting\core;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;
use \Shipard\Utils\Utils;

/**
 * Class TableHostings
 */
class TableHostings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.hostings', 'hosting_core_hostings', 'Hostingy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		//$hdr ['info'][] = ['class' => 'info', 'value' => [['text'=>$recData ['domain']]]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']]]];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if ($recData['gid'] == '')
		{
			$recData ['gid'] = Utils::createRecId($recData, '!05z');;
		}
	}

	public function saveConfig ()
	{
		$portalsList = [];
		$portalsDomains = [];
		$hostingGid = '';
		$hostingDomain = '';

		$rows = $this->app()->db->query ('SELECT * FROM [hosting_core_hostings] WHERE docState = 4000 ORDER BY [ndx]');

		foreach ($rows as $r)
		{
			$domainKey = str_replace('.', '-', $r ['domain']);
			$portalsDomains[$domainKey] = $r ['ndx'];

			$item = [
				'ndx' => $r ['ndx'],
				'supportEmail' => $r ['supportEmail'],
				'supportPhone' => $r ['supportPhone'],
				'supportWeb' => $r ['supportWeb'],
				'supportWebTitle' => substr($r ['supportWeb'], 8),

				'portalTitle' => $r ['portalTitle'],
				'portalLoginTabTitle' => $r['loginTabTitle'],
				'portalDomain' => $r ['domain'],
				'emailDomain' => $r ['emailDomain'],
			];

			if ($item['cssStyle'] === '')
				$item['cssStyle'] = 'shipard';

			$item['logoPortal'] = $this->saveConfigLogo('logo', $r['logoPortal']);
			$item['logoIcon'] = $this->saveConfigLogo('icon', $r['logoIcon']);
			$item['logoIconPortalHome'] = $this->saveConfigLogo('icon', $r['logoIconPortalHome']);
			$item['logoFooter'] = $this->saveConfigLogo('footer', $r['logoFooter']);

			$portalsList [$r['ndx']] = $item;

			if ($r['portalType'] == 0 || $r['portalType'] == 3)
			{
				$hostingGid = $r['gid'];
				$hostingDomain = $r['portalDomain'];
			}
		}

		// -- save to file
		$cfg ['e10pro']['hosting']['gid'] = $hostingGid;
		$cfg ['e10pro']['hosting']['domain'] = $hostingDomain;
		$cfg ['e10pro']['hosting']['portals']['portalsDomains'] = $portalsDomains;
		$cfg ['e10pro']['hosting']['portals']['portals'] = $portalsList;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.hosting.portals.json', utils::json_lint (json_encode ($cfg)));
	}

	function saveConfigLogo ($type, $attNdx)
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
}


/**
 * Class ViewHostings
 */
class ViewHostings extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [hostings].*';
		array_push($q, ' FROM [hosting_core_hostings] AS [hostings]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([hostings].[name] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '[hostings].', ['[hostings].[name]', '[hostings].[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = [['text' => $item['name'], 'class' => ''], ];

		$pt = $this->table->columnInfoEnum ('portalType', 'cfgText');
		$listItem ['i1'] = ['text' => $pt [$item ['portalType']], 'class' => 'id'];
		if ($item ['portalType'] === 0)
			$listItem ['i1']['suffix'] = '#'.$item ['gid'];

		$listItem ['t2'] = [];
		//$listItem ['t2'][] = ['text' => $item['domain'], 'class' => '', 'icon' => 'icon-globe'];
		//$listItem ['t2'][] = ['text' => $item['emailDomain'], 'class' => '', 'icon' => 'system/iconEmail'];

		$listItem ['t3'] = ['text' => $item['supportEmail'].', '.$item['supportPhone'], 'class' => '', 'icon' => 'icon-life-ring'];

		return $listItem;
	}
}


/**
 * Class ViewDetailHosting
 */
class ViewDetailHosting extends TableViewDetail
{
	function portalPartners()
	{
		$list = [];

		$rows = $this->db()->query ('SELECT * FROM [hosting_core_partners] WHERE [portal] = %i', $this->item['ndx'], ' ORDER BY name');
		foreach ($rows as $r)
		{
			$item = ['text' => $r['name'], 'class' => 'label label-default'];
			$list[] = $item;
		}

		if (count($list))
			return $list;

		return '';
	}
	
	public function createDetailContent ()
	{
		$i = $this->item;

		$info = [];
		//$info[] = ['p1' => 'Doména', 't1' => $i['domain']];
		//$info[] = ['p1' => 'Doména pro email', 't1' => $i['emailDomain']];
		$info[] = ['p1' => 'Email na podporu', 't1' => $i['supportEmail']];
		$info[] = ['p1' => 'Telefon na podporu', 't1' => $i['supportPhone']];
		//$info[] = ['p1' => 'Partneři', 't1' => $this->portalPartners()];

		$this->addLogo ('Logo portálu', $i['logoPortal'], $info);
		$this->addLogo ('Logo - ikona prohlížeče', $i['logoIcon'], $info);
		$this->addLogo ('Logo - ikona pro levý horní roh', $i['logoIconPortalHome'], $info);
		$this->addLogo ('Logo - zápatí', $i['logoFooter'], $info);

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$title = [['icon' => 'icon-umbrella', 'text' => $i['name'], 'class' => 'h2']];

		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => $title,
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	function addLogo ($title, $ndx, &$dstTable)
	{
		if (!$ndx)
		{
			$dstTable[] = [
				'p1' => $title,
			];
			return;
		}

		$att = $this->db()->query ('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $ndx)->fetch();
		$fn = $this->app()->dsRoot.'/att/'.$att['path'].$att['filename'];

		$dstTable[] = [
			'p1' => $title,
			't1' => [
				['text' => '#'.$ndx], ['code' => "<img src='$fn' class='pull-right' style='max-height: 3em; padding: .1ex; '>"]
				]
		];
	}

	function addPageLogo ($ndx, &$dstItem)
	{
		if (!$ndx)
			return;

		$att = $this->db()->query ('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $ndx)->fetch();
		$fn = $this->app()->dsRoot.'/att/'.$att['path'].$att['filename'];

		$dstItem['t1'][] = ['code' => "<img src='$fn' class='pull-right' style='max-height: 3em; padding: .5ex; '>"];
	}
}


/**
 * Class FormHosting
 */
class FormHosting extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Hosting', 'icon' => 'icon-umbrella'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('portalTitle');
					$this->addColumnInput ('supportEmail');
					$this->addColumnInput ('supportPhone');
					$this->addColumnInput ('supportWeb');
					$this->addColumnInput ('cssStyle');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('logoPortal');
					$this->addColumnInput ('logoIcon');
					$this->addColumnInput ('logoIconPortalHome');
					$this->addColumnInput ('logoFooter');

					$this->addColumnInput('gid');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

