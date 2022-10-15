<?php

namespace e10\web;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * class TableServers
 */
class TableServers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.servers', 'e10_web_servers', 'Webové servery');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		$serverMode = $this->app()->cfgItem ('e10.web.serverModes.'.$recData['serverMode'], FALSE);
		if ($serverMode !== FALSE)
			return $serverMode['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);
		$this->saveConfig ();
		\E10\compileConfig ();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData['serverMode']) || $recData['serverMode'] === '')
			$recData['serverMode'] = 'web';

		switch ($recData['serverMode'])
		{
			case 'web':
				$recData['homePageFunction'] = '';
				$recData['wiki'] = 0;
				break;
			case 'wiki':
				$recData['homePageFunction'] = 'wiki';
				break;
		}

		if ($recData['templateNdx'] == 0)
		{
			$recData['template'] = '';
			$recData['templateLookNdx'] = 0;
			$recData['templateLook'] = '';
			$recData['templateStylePath'] = '';
		}


		if ($recData['templateNdx'] !== 0)
		{
			$tableTemplates = $this->app()->table('e10.base.templates');
			$recData['template'] = $tableTemplates->templateId(0, intval($recData['templateNdx']));

			$tableTemplatesLooks = $this->app()->table('e10.base.templatesLooks');
			$allTemplateLooks = $tableTemplatesLooks->templateLooks (0, intval($recData['templateNdx']));

			if (!isset($allTemplateLooks[$recData['templateLookNdx']]))
			{
				$recData['templateLookNdx'] = 0;
				$recData['templateLook'] = '';
				$recData['templateStylePath'] = '';
			}

			if ($recData['templateLookNdx'])
			{
				$templateLookInfo = $tableTemplatesLooks->templateLookInfo($recData['templateLookNdx']);

				$templateIdParts = explode('.', $recData['template']);
				$ti = array_pop($templateIdParts);
				$recData['templateLook'] = $templateLookInfo['id'];

				if ($recData['templateLookNdx'] < 100000)
				{
					$recData['templateStylePath'] = '/templates/' . $templateLookInfo['id'];
				} else
				{
					$recData['templateStylePath'] = __SHPD_TEMPLATE_SUBDIR__.'/web/' . $ti . '/styles/';
				}
			}
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [e10_web_servers] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$servers = [];
		$hosts = [];
		$nonAppsHosts = [];
		$webs = [];
		foreach ($rows as $r)
		{
			if ($r['domain'] === '')
				continue;
			$host = $r['domain'];
			$urlStart = $host;

			if (!in_array($host, $hosts))
				$hosts[] = $host;
			if ($r['authType'] !== 2 && !in_array($host, $nonAppsHosts))
				$nonAppsHosts[] = $host;

			$webs[$host][] = $r->toArray();

			if ($r['urlBegin'] !== '')
				$urlStart .= $r['urlBegin'];

			$templateParams = ($r['templateParams'] == '') ? [] : json_decode($r['templateParams'], TRUE);
			if (!is_array($templateParams))
				$templateParams = [];

			$templateParams['defaultTemplateType'] = 'page-'.$r['serverMode'];

			$s = [
				'ndx' => $r['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'title' => $r ['title'],
				'domain' => $r['domain'],
				'mode' => $r['serverMode'], 'wiki' => $r['wiki'], 'hpFunction' => $r['homePageFunction'], 'templateMainScript' => $r['templateMainScript'],
				'urlStart' => $urlStart, 'urlStartSec' => str_replace('/', '-', $urlStart),
				'template' => $r['template'], 'look' => $r['templateLook'], 'lookNdx' => $r['templateLookNdx'], 'templateStylePath' => $r['templateStylePath'],
				'templateParams' => $templateParams, 'themeColor' => '#00508a',
				'gaid' => $r['gaid'], 'mtmSiteId' => $r['mtmSiteId'], 'mtmUrl' => $r['mtmUrl'], 'gmApiKey' => $r['gmApiKey'],
				'authType' => $r['authType'],
			];

			if ($r['domainsRedirectHere'] !== '')
			{
				$rh = explode (' ', $r['domainsRedirectHere']);
				foreach ($rh as $oneDomain)
				{
					$s['drh'][] = trim($oneDomain);
				}
			}

			if ($r['templateLookNdx'])
			{
				$tableTemplatesLooks = $this->app()->table('e10.base.templatesLooks');
				$templateLookInfo = $tableTemplatesLooks->templateLookInfo($r['templateLookNdx']);
				if ($templateLookInfo && isset($templateLookInfo['themeColor']))
					$s['themeColor'] = $templateLookInfo['themeColor'];
			}

			if ($r['excludeFromDashboard'])
				$s['excludeFromDashboard'] = 1;

			if ($r['authType'] != 0)
			{
				$s['loginRequired'] = $r['loginRequired'];
				if ($r['authType'] === 1)
				{ // web
					$s['authTypePassword'] = $r['authTypePassword'];
					$s['authTypeUrlHash'] = $r['authTypeUrlHash'];
					$s['authTypeKeyId'] = $r['authTypeKeyId'];
				}
			}

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($s, 'admins', 'e10.persons.persons', 'e10-web-servers-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($s, 'adminsGroups', 'e10.persons.groups', 'e10-web-servers-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($s, 'pageEditors', 'e10.persons.persons', 'e10-web-servers-pageEdit', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($s, 'pageEditorGroups', 'e10.persons.groups', 'e10-web-servers-pageEdit', $r ['ndx']);

			$s['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$s['images'] = $this->serverImagesData ($r);

			$servers [$r['ndx']] = $s;
		}

		$cfg ['e10']['web']['servers']['list'] = $servers;
		$cfg ['e10']['web']['servers']['hosts'] = $hosts;
		$cfg ['e10']['web']['servers']['nonAppsHosts'] = $nonAppsHosts;

		file_put_contents(__APP_DIR__ . '/config/_e10.web.servers.json', Utils::json_lint (json_encode ($cfg)));

		$this->createNginxConfigs();
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	function createNginxConfigs()
	{
		$webServers = Utils::loadCfgFile(__APP_DIR__ . '/config/_e10.web.servers.json');
		if (!$webServers)
			return;

		$webs = [];
		$rows = $this->app()->db->query ("SELECT * FROM [e10_web_servers] WHERE docState != 9800 ORDER BY [order], [fullName]");
		foreach ($rows as $r)
		{
			if (!isset($webServers['e10']['web']['servers']['list'][$r['ndx']]))
				continue;
			$host = $r['domain'];
			$webs[$host][] = $r->toArray();
		}

		$systemDomainsCerts = ['shipard.app', 'shipard.pro', 'shipard.cz'];
		$dsid = $this->app()->cfgItem('dsid');

		array_map ("unlink", glob (__APP_DIR__.'/config/nginx/'.$dsid.'-web*'));

		foreach ($webs as $domain => $servers)
		{
			$redirectsHosts = [];
			$cfg = '';

			$cfg .= '# '.$domain;

			foreach ($servers as $server)
			{
				$rh = explode (' ', $server['domainsRedirectHere']);
				foreach ($rh as $oneHost)
				{
					$oneHost = trim($oneHost);
					if ($oneHost === '')
						continue;
					if (!in_array($oneHost, $redirectsHosts))
						$redirectsHosts[] = $oneHost;
				}
			}

			if (count ($redirectsHosts))
				$cfg .= ' < '.implode (' ', $redirectsHosts);
			$cfg .= '; cfg ver 0.5'."\n\n";

			$domainParts = explode('.', $domain);
			$cntAllDomainParts = count($domainParts);
			while(count($domainParts) > 2)
				array_shift($domainParts);
			$coreDomain = implode('.', $domainParts);

			$isSystemCert = (in_array($coreDomain, $systemDomainsCerts) && $cntAllDomainParts > 2);
			$certId = $isSystemCert ? 'all.'.$coreDomain : $domain;
			$certPath = $isSystemCert ? '/var/lib/shipard/certs' : __APP_DIR__.'/config/nginx/certs';

			// -- web via https
			$cfg .= "server {\n";
			$cfg .= "\tlisten 443 ssl http2;\n";
			$cfg .= "\tserver_name $domain;\n";
			$cfg .= "\troot /var/lib/shipard/data-sources/$dsid;\n";
			$cfg .= "\tindex index.php;\n";

			$cfg .= "\tssl_certificate $certPath/$certId/chain.pem;\n";
			$cfg .= "\tssl_certificate_key $certPath/$certId/privkey.pem;\n";
			if (is_readable('/etc/ssl/dhparam.pem'))
				$cfg .= "\tssl_dhparam /etc/ssl/dhparam.pem;\n";
			$cfg .= "\tinclude /usr/lib/shipard/etc/nginx/shpd-one-app.conf;\n";
			$cfg .= "\tinclude /usr/lib/shipard/etc/nginx/shpd-https.conf;\n";
			$cfg .= "}\n\n";

			// -- https redirects
			if (count($redirectsHosts))
			{
				$cfg .= "\n";
				$cfg .= "server {\n";
				$cfg .= "\tlisten 443 ssl http2;\n";
				$cfg .= "\tserver_name ";
				$cfg .= ' '.implode(' ', $redirectsHosts);
				$cfg .= ";\n";
				$cfg .= "\troot /var/www;\n";

				$cfg .= "\tssl_certificate $certPath/$certId/chain.pem;\n";
				$cfg .= "\tssl_certificate_key $certPath/$certId/privkey.pem;\n";
				if (is_readable('/etc/ssl/dhparam.pem'))
					$cfg .= "\tssl_dhparam /etc/ssl/dhparam.pem;\n";
				$cfg .= "\tinclude /usr/lib/shipard/etc/nginx/shpd-https.conf;\n";

				$cfg .= "\tlocation / {\n";
				$cfg .= "\t\treturn 301 https://$domain".'$request_uri'.";\n";
				$cfg .= "\t}\n";
				$cfg .= "}\n\n";
			}

			// -- http redirects
			$cfg .= "server {\n";
			$cfg .= "\tlisten 80;\n";
			$cfg .= "\tserver_name $domain";
			if (count($redirectsHosts))
				$cfg .= ' '.implode(' ', $redirectsHosts);
			$cfg .= ";\n";

			$cfg .= "\troot /var/www;\n";

			$cfg .= "\tlocation / {\n";
			$cfg .= "\t\treturn 301 https://$domain".'$request_uri'.";\n";
			$cfg .= "\t}\n";
			$cfg .= "}\n\n";

			// -- save
			$configFileName = __APP_DIR__.'/config/nginx/'.$dsid.'-web-'.$domain.'.conf';
			file_put_contents($configFileName, $cfg);
		}
	}

	function serverImagesData ($recData)
	{
		$data = ['web' => [], 'template' => []];

		// -- web icons
		$this->serverImage('icon', $data['web'], $recData['iconCore']);
		$this->serverImage('iconApp', $data['web'], $recData['iconApp'], [$recData['iconCore']]);
		$this->serverImage('iconAppIos', $data['web'], $recData['iconAppIos'], [$recData['iconApp'], $recData['iconCore']]);

		// -- template images
		$sci = $this->subColumnsInfo ($recData, '');
		$templateParams = Json::decode($recData['templateParams']);
		if ($sci && isset($sci['columns']) && $templateParams)
		{

			foreach ($sci['columns'] as $oneCol)
			{
				if (!isset ($oneCol['reference']) || $oneCol['reference'] !== 'e10.base.attachments')
					continue;
				$colId = $oneCol['id'];
				$attNdx = intval($templateParams[$colId]);
				$this->serverImage($colId, $data['template'], $attNdx);
			}
		}

		return $data;
	}

	function serverImage ($key, &$dst, $attNdxPrimary, $attNdxFallBacks = NULL)
	{
		$attNdx = $attNdxPrimary;
		if (!$attNdx && $attNdxFallBacks !== NULL)
		{
			foreach ($attNdxFallBacks as $attNdxFallBack)
			{
				if ($attNdxFallBack)
				{
					$attNdx = $attNdxFallBack;
					break;
				}
			}
		}

		if (!$attNdx)
			return;

		$image = $this->db()->query ("SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i", $attNdx)->fetch();
		if (!$image)
			return;

		$dst[$key] = '/att/'.$image['path'].$image ['filename'];
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		$template = new \e10\TemplateCore ($this->app());
		$template->loadTemplate($recData['template'], FALSE);
		if (!$template || $template->options === FALSE || !isset($template->options['templateParams']))
			return FALSE;

		return $template->options['templateParams'];
	}

	function copyServer ($srcServerNdx)
	{
		$serverItem = $this->loadItem($srcServerNdx);
		if (!$serverItem)
			return;

		// -- create new server
		unset ($serverItem['ndx']);

		$serverItem['fullName'] .= ' KOPIE';
		$serverItem['shortName'] .= ' KOPIE';
		$serverItem['domain'] = 'new-'.$serverItem['domain'];

		$serverItem['docState'] = 1000;
		$serverItem['docStateMain'] = 0;

		$newServerNdx = $this->dbInsertRec($serverItem);

		// -- copy server pages
		$tablePages = $this->app()->table ('e10.web.pages');
		$rows = $this->db()->query ('SELECT * FROM [e10_web_pages] WHERE [server] = %i AND docState != 9800', $srcServerNdx);

		foreach ($rows as $r)
		{
			$newPage = $r->toArray ();
			unset($newPage['ndx']);
			$newPage['server'] = $newServerNdx;
			$newPage['docState'] = 1000;
			$newPage['docStateMain'] = 0;
			$tablePages->dbInsertRec($newPage);
		}
	}
}


/**
 * class ViewServers
 */
class ViewServers extends TableView
{
	public $propgroups;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['t2'] = [];
		$loginRequired = ($item['loginRequired']) ? 'Vyžadováno' : 'Volitelně';
		switch ($item['authType'])
		{
			case 0: $listItem ['t2'][] = ['text' => 'Ne', 'icon' => 'system/actionLogIn', 'class' => 'label label-default']; break;
			case 1: $listItem ['t2'][] = ['text' => 'Web', 'icon' => 'system/actionLogIn', 'suffix' => $loginRequired, 'class' => 'label label-success']; break;
			case 2: $listItem ['t2'][] = ['text' => 'Aplikace', 'icon' => 'system/actionLogIn', 'suffix' => $loginRequired, 'class' => 'label label-danger']; break;
		}

		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$props = [];

		$i2 = $item['domain'];
		if ($item['urlBegin'] !== '')
			$i2 .= $item['urlBegin'];
		$props[] = ['text' => $i2, 'icon' => 'icon-globe', 'class' => ''];

		if ($item['gaid'])
			$props[] = ['icon' => 'icon-google', 'text' => $item['gaid'], 'class' => ''];
		if ($item['order'])
			$props[] = ['icon' => 'system/iconOrder', 'text' => strval ($item['order']), 'class' => ''];

		$listItem ['i2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$itemType = intval($this->bottomTabId ());

		$q [] = "SELECT * from [e10_web_servers] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		array_push ($q, ' ORDER BY [order], [fullName], [ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
} // class ViewServers


/**
 * class ViewDetailServer
 */
class ViewDetailServer extends TableViewDetail
{
}


/**
 * Class FormServer
 * @package e10\web
 */
class FormServer extends TableForm
{
	public function renderForm ()
	{
		$serverMode = $this->app()->cfgItem ('e10.web.serverModes.'.$this->recData['serverMode']);

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formTemplate'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('title');
					$this->addColumnInput ('domain');
					$this->addColumnInput ('urlBegin');

					$this->addSeparator(self::coH2);
					$this->addColumnInput ('serverMode');
					if (isset($serverMode['askWiki']))
						$this->addColumnInput ('wiki');
					if (isset($serverMode['askHpFunction']))
						$this->addColumnInput ('homePageFunction');

					$this->addColumnInput('authType');
					if ($this->recData['authType'] != 0)
					{
						$this->addColumnInput ('loginRequired');
						if ($this->recData['authType'] == 1)
						{
							$this->addColumnInput('authTypePassword');
							$this->addColumnInput('authTypeUrlHash');
							$this->addColumnInput('authTypeKeyId');
						}
					}
					$this->addSeparator(self::coH2);
					$this->addColumnInput('domainsRedirectHere');
					$this->addSeparator(self::coH2);
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('templateNdx');
					$this->addColumnInput ('templateLookNdx');

					$this->addColumnInput ('iconCore');
					$this->addColumnInput ('iconApp');
					$this->addColumnInput ('iconAppIos');

					$this->addSubColumns ('templateParams');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('excludeFromDashboard');
					$this->addColumnInput ('order');

					$this->addColumnInput ('gaid');
					$this->addColumnInput ('mtmSiteId');
					$this->addColumnInput ('mtmUrl');

					$this->addColumnInput ('gmApiKey');

					$this->addColumnInput ('templateMainScript');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10.web.servers')
		{
			$cp = [];
			if ($srcColumnId === 'templateLookNdx')
				$cp = ['templateNdx' => $recData ['templateNdx']];
			if (count($cp))
				return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}

