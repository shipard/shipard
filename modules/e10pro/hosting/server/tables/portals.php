<?php

namespace e10pro\hosting\server;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \e10\utils, \E10\DbTable;


/**
 * Class TablePortals
 * @package e10pro\hosting\server
 */
class TablePortals extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.portals', 'e10pro_hosting_server_portals', 'Portály');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);
		$hdr ['info'][] = ['class' => 'info', 'value' => [['text'=>$recData ['domain']]]];
		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['name']]]];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['portalType']) && $recData['gid'] == '')
		{
			$recData ['gid'] = mt_rand (10000, 999999).'0'.mt_rand (100000, 9999999);
		}
	}

	public function saveConfig ()
	{
		$portalsList = [];
		$portalsDomains = [];
		$hostingGid = '';
		$hostingDomain = '';

		$rows = $this->app()->db->query ('SELECT * FROM [e10pro_hosting_server_portals] WHERE docState = 4000 ORDER BY [ndx]');

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

				//'brandTextPlain' => $r ['brandTextPlain'],
				'cssStyle' => $r ['cssStyle'],
			];

			if ($item['cssStyle'] === '')
				$item['cssStyle'] = 'shipard';

			$item['logoPortal'] = $this->saveConfigLogo('logo', $r['logoPortal']);
			$item['logoIcon'] = $this->saveConfigLogo('icon', $r['logoIcon']);
			$item['logoIconPortalHome'] = $this->saveConfigLogo('icon', $r['logoIconPortalHome']);
			$item['logoFooter'] = $this->saveConfigLogo('footer', $r['logoFooter']);

			$loginUsers = $this->saveConfigLoginUsers($r['ndx']);
			if (count($loginUsers))
			{
				$item['loginUsers'] = $loginUsers;
				$item['hasLoginUsers'] = 1;
			}

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

	function saveConfigLoginUsers ($siteNdx)
	{
		$loginUsers = [];

		$ql[] = 'SELECT docLinks.*, ';
		array_push ($ql, ' persons.fullName as personFullName, persons.login as personLogin');
		array_push ($ql, ' FROM [e10_base_doclinks] AS docLinks');
		array_push ($ql, ' LEFT JOIN e10_persons_persons AS persons ON docLinks.dstRecId = persons.ndx');
		array_push ($ql, ' WHERE srcTableId = %s', 'e10pro.hosting.server.portals', ' AND dstTableId = %s', 'e10.persons.persons');
		array_push ($ql, ' AND docLinks.linkId = %s', 'e10pro-hosting-portal-login-users');
		array_push ($ql, ' AND srcRecId = %i', $siteNdx);

		$rows = $this->db()->query($ql);

		foreach ($rows as $r)
		{
			$item = ['fullName' => $r['personFullName'], 'login' => $r['personLogin']];

			// -- user image
			$img = \e10\base\getAttachmentDefaultImage ($this->app(), 'e10.persons.persons', $r['dstRecId']);
			if (count($img))
				$item['img'] = $img;

			// -- user properties
			$props = \e10\base\getPropertiesTable ($this->app(), 'e10.persons.persons', $r['dstRecId']);
			$item['properties'] = $props;

			$loginUsers[] = $item;
		}

		return $loginUsers;
	}
}


/**
 * Class ViewPortals
 * @package e10pro\hosting\server
 */
class ViewPortals extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT [portals].*';
		array_push($q, ' FROM [e10pro_hosting_server_portals] AS [portals]');
		array_push($q, ' WHERE 1');

		if ($fts != '')
			array_push ($q, ' AND ([portals].[name] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '[portals].', ['[portals].[name]', '[portals].[ndx]']);
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
		$listItem ['t2'][] = ['text' => $item['domain'], 'class' => '', 'icon' => 'icon-globe'];
		$listItem ['t2'][] = ['text' => $item['emailDomain'], 'class' => '', 'icon' => 'system/iconEmail'];

		$listItem ['t3'] = ['text' => $item['supportEmail'].', '.$item['supportPhone'], 'class' => '', 'icon' => 'icon-life-ring'];

		return $listItem;
	}
}


/**
 * Class ViewDetailPortal
 * @package e10pro\hosting\server
 */
class ViewDetailPortal extends TableViewDetail
{
	function portalPartners()
	{
		$list = [];

		$rows = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_partners] WHERE [portal] = %i', $this->item['ndx'], ' ORDER BY name');
		foreach ($rows as $r)
		{
			$item = ['text' => $r['name'], 'class' => 'label label-default'];
			$list[] = $item;
		}

		if (count($list))
			return $list;

		return '';
	}

	function addPortalPages ()
	{
		$tablePortslsPages = $this->app()->table ('e10pro.hosting.server.portalsPages');
		$enumPageType = $tablePortslsPages->columnInfoEnum ('pageType');;

		$pages = [];
		$rows = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_portalsPages] WHERE [portal] = %i', $this->item['ndx'], ' ORDER BY rowOrder');
		foreach ($rows as $r)
		{
			$item = [
				'p1' => $enumPageType[$r['pageType']],
				't1' => [
					['text' => $r['title']],
					['text' => $r['url'], 'class' => 'break']
				]
			];

			$this->addPageLogo ($r['logoIcon'], $item);

			$pages[] = $item;
		}

		$pages[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$title = [['icon' => 'icon-globe', 'text' => 'Stránky', 'class' => 'h2']];

		$this->addContent (['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => $title,
			'header' => $h, 'table' => $pages, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	public function createDetailContent ()
	{
		$i = $this->item;

		$info = [];
		$info[] = ['p1' => 'Doména', 't1' => $i['domain']];
		$info[] = ['p1' => 'Doména pro email', 't1' => $i['emailDomain']];
		$info[] = ['p1' => 'Email na podporu', 't1' => $i['supportEmail']];
		$info[] = ['p1' => 'Telefon na podporu', 't1' => $i['supportPhone']];
		$info[] = ['p1' => 'Partneři', 't1' => $this->portalPartners()];

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


		// -- portalsPages
		$this->addPortalPages();
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
 * Class FormPortal
 * @package e10pro\hosting\server
 */
class FormPortal extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Portál', 'icon' => 'icon-umbrella'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Stránky', 'icon' => 'icon-globe'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('domain');
					$this->addColumnInput ('emailDomain');
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

					if ($this->recData['portalType'] == 3)
					{ // demo
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					}
					if ($this->recData['portalType'] == 0)
					{ // primary
						$this->addColumnInput('gid');
					}
					$this->addColumnInput ('portalType');
					$this->addColumnInput('loginTabTitle');
				$this->closeTab ();
				$this->openTab ();
					$this->addList ('pages');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

