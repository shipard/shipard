<?php

namespace E10\Base;


use E10\utils;

class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppPublish ()
	{
		if ($this->initConfig)
		{
			/*
			$hostingCfg = utils::hostingCfg(['hostingServerUrl']);

			$siteName = $this->initConfig ['request']['name'];

			$hostingName = $hostingCfg ['hostingName'];
			$hostingEmail = $hostingCfg ['hostingEmail'];
			$hostingPhone = $hostingCfg ['hostingPhone'];
			$hostingWeb = $hostingCfg ['hostingWeb'];

			$message = "Dobrý den, \n\nVaše nová databáze '{$siteName}' byla vytvořena.\n\n" .
				"Přihlásit se můžete na adrese {$hostingCfg['hostingServerUrl']}{$this->app->cfgItem('dsid')}\n\n" .
				"\nDěkujeme.\n\n-- \n email: $hostingEmail | hotline: $hostingPhone | $hostingWeb \n";

			$msg = new \E10\MailMessage($this->app);
			$msg->setFrom ('Technická podpora '.$hostingName, $hostingEmail);
			$msg->setTo($this->initConfig ['admin']['recData']['login']);
			$msg->setSubject('Nová databáze - '.$siteName);
			$msg->setBody($message);

			$msg->sendMail();
			*/	
			return TRUE;
		}
	}

	public function onAppUpgrade ()
	{
		$s [] = ['version' => 0, 'sql' => "update e10_persons_groups set docState = 4000, docStateMain = 2 where docState = 0"];

		$s [] = ['end' => '2019-08-30', 'sql' => "update e10_persons_address set tableid='e10.base.places' WHERE tableid='e10doc.base.places'"];

		$this->doSqlScripts ($s);

		$this->checkNewPlaces();

		$this->upgradeTemplateLooks();
	}

	function checkNewPlaces()
	{
		if ($this->app->model()->module('e10doc.base') === FALSE)
			return;

		$oldTableExist = $this->app->db()->query('SELECT table_name FROM information_schema.tables ',
			'WHERE table_schema = %s AND table_name = %s', $this->app->cfgItem('db.database'), 'e10doc_base_places')->fetch();
		if (!$oldTableExist)
			return;

		$cnt = $this->app->db()->query ('SELECT COUNT(*) AS [cnt] FROM [e10doc_base_places]')->fetch();
		if (!$cnt || !$cnt['cnt'])
			return;

		$this->moveTable('e10doc.base.places', 'e10doc_base_places', 'e10.base.places');
	}

	function upgradeTemplateLooks()
	{
		$rows = $this->app->db()->query ('SELECT * FROM [e10_base_templatesLooks] WHERE docState != 9800');
		foreach ($rows as $r)
		{
			$wtl = new \lib\web\WebTemplateLook($this->app);
			$wtl->check($r, TRUE);
		}
	}

	public function loadModule ($moduleId)
	{
		$moduleFileName = __SHPD_MODULES_DIR__.str_replace('.', '/', $moduleId).'/module.json';
		return \E10\utils::loadCfgFile ($moduleFileName);
	}

	public function installDataPackage ($packageId)
	{
		$pkgFileName = __SHPD_MODULES_DIR__.$packageId.'.json';

		if (!is_readable($pkgFileName))
		{
			echo ("ERROR: file `$pkgFileName` not found...");
			return;
		}

		$installer = new \lib\DataPackageInstaller ($this->app);
		$installer->setFileName($pkgFileName);
		$installer->run();
	}

	public function installDataPackages ()
	{
		$dataPackages = array ();

		foreach ($this->app->dataModel->model ['modules'] as $moduleId => $moduleName)
		{
			$module = $this->loadModule($moduleId);
			if ($module === FALSE)
			{
				echo ("ERROR: module `$moduleId` not exist\n");
				continue;
			}

			if (!isset ($module['data']))
				continue;

			forEach ($module['data'] as $dataPkg)
			{
				if (!in_array($dataPkg, $dataPackages))
					$dataPackages[] = $dataPkg;
			}
		}

		forEach ($dataPackages as $dataPkg)
			$this->installDataPackage ($dataPkg);
	}

	public function onCreateDataSource ()
	{
		$this->checkCoreOptions();
		$this->installDataPackages();
		return TRUE;
	}

	public function dataSourceStatsCreate()
	{
		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		// -- active users
		$dsStats->data['users']['created'] = new \DateTime();
		$minDateMonth = new \DateTime('1 month ago');
		$users = $this->app->db()->query (
				'SELECT [user], COUNT(*) AS cnt FROM e10_base_docslog', ' WHERE [user] != 0 AND created > %d', $minDateMonth,
				' AND eventType = 0', ' AND tableid != %s', 'e10doc.core.heads', ' GROUP by user');
		$cntUsers = 0;
		$cntOps = 0;
		foreach ($users as $u)
		{
			$cntUsers++;
			$cntOps += $u['cnt'];
		}
		$dsStats->data['users']['lastMonth']['active'] = ['users' => $cntUsers, 'ops' => $cntOps];

		// -- all users
		$users = $this->app->db()->query (
				'SELECT [user], COUNT(*) AS cnt FROM e10_base_docslog', ' WHERE [user] != 0 AND created > %d', $minDateMonth, ' GROUP by user');
		$cntUsers = 0;
		$cntOps = 0;
		foreach ($users as $u)
		{
			$cntUsers++;
			$cntOps += $u['cnt'];
		}
		$dsStats->data['users']['lastMonth']['all'] = ['users' => $cntUsers, 'ops' => $cntOps];

		// -- modules
		$dsStats->data['modules'] = utils::loadCfgFile(__APP_DIR__.'/config/modules.json');

		$dsStats->saveToFile();
	}

	public function onStats()
	{
		$this->dataSourceStatsCreate();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'stats': $this->onStats(); break;
			case 'services': $this->createUsersSummary(); break;
		}
		return TRUE;
	}

	function attMetaData()
	{
		$ndx = intval($this->app->arg('ndx'));

		$attTableId = $this->app->arg('tableId');
		$attRecId = $this->app->arg('recId');

		$e = new \lib\core\attachments\Extract($this->app);

		if ($ndx)
			$e->setAttNdx($ndx);
		elseif ($attTableId !== FALSE)
			$e->setAttTableDocument ($attTableId, $attRecId);

		$e->run();
	}

	function attDocDataFiles()
	{
		$attTableId = $this->app->arg('tableId');
		$attRecId = $this->app->arg('recId');

		$e = new \lib\docDataFiles\AttachmentsUpdater($this->app);
		$e->init();
		$e->doTableDocument($attTableId, $attRecId);
	}


	function attGeoTags()
	{
		$ndx = intval($this->app->arg('ndx'));

		$attTableId = $this->app->arg('tableId');
		$attRecId = $this->app->arg('recId');

		$e = new \lib\core\attachments\GeoTags($this->app);

		if ($ndx)
			$e->setAttNdx($ndx);
		elseif ($attTableId !== FALSE)
			$e->setAttTableDocument ($attTableId, $attRecId);

		$e->run();
	}

	function createUsersSummary()
	{
		$e = new \e10\base\libs\UsersSummaryCreator($this->app);
		$e->run();
	}

	function installNomenclature()
	{
		$nomencId = $this->app->arg('id');
		if (!$nomencId)
		{
			echo "Param `id` not found";
			return FALSE;
		}

		$e = new \lib\nomenclature\InstallNomenclature($this->app);
		$e->nomencId = $nomencId;
		$e->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'att-geo-tags': return $this->attGeoTags();
			case 'att-meta-data': return $this->attMetaData();
			case 'att-doc-data-files': return $this->attDocDataFiles();
			case 'create-users-summary': return $this->createUsersSummary();
			case 'install-nomenclature': return $this->installNomenclature();
		}

		parent::onCliAction($actionId);
	}

	function moveTable ($srcTableId, $srcTableSqlName, $dstTableId, $disabledCols = [])
	{ // TODO: remove
		/** @var \e10\DbTable */
		$dstTable = $this->app->table ($dstTableId);

		$colsList = [];
		foreach ($dstTable->columns() as $colDef)
		{
			if (in_array($colDef['sql'], $disabledCols))
				continue;
			$colsList[] = '[' . $colDef['sql'] . ']';
		}

		$sqlCommand = 'INSERT INTO ['.$dstTable->sqlName().']';
		$sqlCommand .= ' ('.implode(', ', $colsList).')';
		$sqlCommand .= ' SELECT '.implode(', ', $colsList).' FROM ['.$srcTableSqlName.'] ORDER BY [ndx]';

		//echo $sqlCommand."\n";
		$this->app->db()->query($sqlCommand);
		$this->app->db()->query('DELETE FROM ['.$srcTableSqlName.']');

		$this->app->db()->query ('UPDATE [e10_base_docslog] SET tableId = %s', $dstTableId, ' WHERE tableid = %s', $srcTableId);
	}

	public function checkCoreOptions ()
	{
		if (isset($this->initConfig ['createRequest']['name']))
		{
			$o ['ownerFullName'] = $this->initConfig ['createRequest']['name'];
			$o ['ownerShortName'] = $this->initConfig ['createRequest']['name'];
			$o ['ownerPerson'] = 2;
		}
		else
		{
			$o ['ownerFullName'] = 'Test s.r.o.';
			$o ['ownerShortName'] = 'Test';
		}

		$o ['country'] = $this->initConfig ['createRequest']['country'];

		file_put_contents (__APP_DIR__ . '/config/appOptions.core.json', json_encode ($o));
	}
}
