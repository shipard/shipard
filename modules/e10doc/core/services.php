<?php

namespace E10Doc\Core;

use E10\utils;

class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2022-11-15', 'sql' => "UPDATE e10_witems_itemCodes SET systemOrder = 99 where systemOrder = 0"];

		$s [] = ['end' => '2021-03-30', 'sql' => "update e10_witems_items set itemKind2 = itemKind where isSet = 0"];

		$s [] = ['end' => '2020-03-30', 'sql' => "update e10doc_base_dockinds set docState = 4000, docStateMain = 2 where docState = 0"];
		$s [] = ['end' => '2020-03-30', 'sql' => "update e10doc_base_docnumbers set docState = 4000, docStateMain = 2 where docState = 0"];

		$s [] = ['end' => '2018-12-31', 'sql' => "UPDATE e10doc_debs_journal SET accRing = 20 WHERE accRing = 0"];

		$s [] = ['end' => '2018-04-28', 'sql' => "UPDATE e10doc_base_fiscalyears SET propertyDepsMethod = 'propDepsH' WHERE propertyDepsMethod = ''"];
		$s [] = ['end' => '2018-02-28', 'sql' => "UPDATE e10doc_base_fiscalyears SET stockAccMethod = 'stockB' WHERE stockAccMethod = ''"];

		$s [] = ['end' => '2017-11-30', 'sql' => "UPDATE e10doc_base_bankaccounts SET docState = 4000, docStateMain = 2 WHERE docState = 0"];

		$s [] = ['end' => '2018-05-28', 'sql' => "update e10doc_core_heads set personBalance = person where personBalance = 0 AND person != 0"];

		$s [] = ['end' => '2017-08-30', 'sql' => "update e10doc_base_docnumbers set [order] = (ndx * 1000) WHERE [order] = 0"];

		$s [] = ['end' => '2017-02-28', 'sql' => "update e10doc_core_heads set [activateDateFirst] = DATE([activateTimeFirst]) WHERE [activateDateFirst] IS NULL AND [activateTimeFirst] IS NOT NULL"];


		$s [] = ['end' => '2015-09-30', 'sql' => "update e10doc_core_rows set invPrice = taxBase WHERE invPrice = 0 AND invDirection != 0"];

		$s [] = ['end' => '2015-09-30', 'sql' => "update e10doc_core_rows set rowOrder = (ndx * 100) WHERE rowOrder = 0"];

		$s [] = ['end' => '2015-06-30', 'sql' => "update e10doc_core_heads set personType = (select personType FROM e10_persons_persons where ndx = e10doc_core_heads.person) WHERE e10doc_core_heads.personType = 0 and e10doc_core_heads.person != 0"];

		$s [] = ['end' => '2015-06-30', 'sql' => "update e10doc_core_heads set personType = (select personType FROM e10_persons_persons where ndx = e10doc_core_heads.person) WHERE e10doc_core_heads.personType = 0 and e10doc_core_heads.person != 0"];

		$s [] = ['end' => '2015-06-30', 'sql' => "update e10doc_base_centres set docState = 4000, docStateMain = 2 where docState = 0"];
		$s [] = ['end' => '2015-06-30', 'sql' => "update e10doc_base_cashboxes set docState = 4000, docStateMain = 2 where docState = 0"];
		$s [] = ['end' => '2015-06-30', 'sql' => "update e10doc_base_warehouses set docState = 4000, docStateMain = 2 where docState = 0"];

		$s [] = ['end' => '2015-01-30', 'sql' => "update e10_witems_items set vatRate = 4 where vatRate = 1"];

		$s [] = ['end' => '2021-02-15', 'sql' => "UPDATE e10doc_base_docnumbers SET [fullName] = 'Otevření účetního období', ".
			"[shortName] = 'Otevření období'	WHERE [docType] = 'cmnbkp' AND [docKeyId] = '9' AND [shortName] = 'Otevření / uzavření období'"];

		//$s [] = ['version' => 0, 'sql' => "update e10_persons_persons set id = ndx where id = ''"];
		//$s [] = ['version' => 57, 'sql' => "update e10_witems_items set id = ndx where id = ''"];

		/*
		$s [] = array ('version' => 48, 'sql' => "update e10doc_core_heads set paymentMethod = 1 where docType = 'cash'");
		$s [] = array ('version' => 48, 'sql' => "update e10doc_core_heads set totalCash = toPay where docType = 'cash' AND cashBoxDir = 1");
		$s [] = array ('version' => 48, 'sql' => "update e10doc_core_heads set totalCash = -toPay where docType = 'cash' AND cashBoxDir = 2");
		$s [] = array ('version' => 48, 'sql' => "update e10doc_core_heads set totalCash = -totalCash where docType IN ('purchase', 'invni') AND totalCash > 0");
		*/

		$this->doSqlScripts ($s);

		$this->checkPersonGroupAccountants();
		$this->checkVATAccountsDuplicity();
		//$this->newBalance ();
	}

	public function newBalance ()
	{
		$q [] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, 'WHERE [docType] = %s', 'bank');
		$docs = $this->app->db()->query ($q);
		foreach ($docs as $r)
		{
			$this->app->db()->query ('UPDATE [e10doc_core_rows] SET [bankRequestCurrency] = %s', $r['currency'], ',
				[bankRequestAmount] = [taxBase]',
				' WHERE [bankRequestCurrency] = %s', '', ' AND [document] = %i', $r['ndx']);
		}
	}

	function checkPersonGroupAccountants ()
	{
		$groupId = 'e10doc-accounting';
		$allGroups = $this->app->cfgItem ('e10.persons.systemGroups', []);
		$groupDef = \E10\searchArray($allGroups, 'id', $groupId);
		if (!$groupDef)
			return;

		$group = $this->app->db()->query('SELECT * FROM [e10_persons_groups] WHERE [systemGroup] = %s', $groupId, ' AND [docState] != %i', 9800)->fetch();
		if ($group)
			return;

		$group = $this->app->db()->query('SELECT * FROM [e10_persons_groups] WHERE [systemGroup] IN %in', ['-', ''],
			' AND [docState] != %i', 9800, ' AND [name] = %s', 'Účtárna')->fetch();
		if ($group)
		{
			$this->app->db()->query('UPDATE [e10_persons_groups] SET [systemGroup] = %s', $groupId, ' WHERE ndx = %i', $group['ndx']);
			return;
		}

		$g = ['name' => $groupDef['name'], 'systemGroup' => $groupId, 'docState' => 4000, 'docStateMain' => 2];
		$this->app->db()->query('INSERT INTO [e10_persons_groups]', $g);
		$group = $this->app->db()->query('SELECT * FROM [e10_persons_groups] WHERE [systemGroup] = %s', $groupId, ' AND [docState] != %i', 9800)->fetch();
	}

	public function resetStatsPersonDocType ()
	{
		$minDate = new \DateTime();
		$minDate = $minDate->sub(date_interval_create_from_date_string('90 days'));

		$q = 'DELETE FROM [e10doc_base_statsPersonDocType]';
		$this->app->db()->query ($q);

		$q = 'INSERT INTO [e10doc_base_statsPersonDocType] (cnt, [docType], person)
					SELECT count(*), docType, person from e10doc_core_heads where docState = 4000 AND dateAccounting > %d group by docType, person';
		$this->app->db()->query ($q, $minDate);
	}

	function checkVATAccountsDuplicity ()
	{
		$q [] = 'SELECT * FROM [e10doc_debs_accounts]';
		array_push($q, ' WHERE [id] LIKE %s', '343%');
		array_push($q, ' ORDER BY [id], [ndx]');

		$existedAccounts = [];
		$recsToRemove = [];

		$docs = $this->app->db()->query ($q);
		foreach ($docs as $r)
		{
			$id = $r['id'];
			if (!isset($existedAccounts[$id]))
				$existedAccounts[$id] = 1;
			else
				$existedAccounts[$id]++;

			if ($existedAccounts[$id] > 1)
				$recsToRemove[] = $r['ndx'];
		}

		foreach ($recsToRemove as $ndx)
		{
			$this->db()->query('DELETE FROM [e10doc_debs_accounts] WHERE [ndx] = %i', $ndx);
		}
	}

	public function resetStatsItemDocType ()
	{
		$minDate = new \DateTime();
		$minDate = $minDate->sub(date_interval_create_from_date_string('90 days'));

		$q = 'DELETE FROM [e10doc_base_statsItemDocType]';
		$this->app->db()->query ($q);

		$q = 'INSERT INTO [e10doc_base_statsItemDocType] (cnt, [docType], item)
					SELECT count(*), heads.docType, [rows].item FROM e10doc_core_rows AS [rows] LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx
					WHERE heads.docState = 4000 AND heads.dateAccounting > %d GROUP BY heads.docType, item';
		$this->app->db()->query ($q, $minDate);
	}

	public function resetStatsPersonItemDocType ()
	{
		$minDate = new \DateTime();
		$minDate = $minDate->sub(date_interval_create_from_date_string('90 days'));

		$q = 'DELETE FROM [e10doc_base_statsPersonItemDocType]';
		$this->app->db()->query ($q);

		$q = 'INSERT INTO [e10doc_base_statsPersonItemDocType] (cnt, [docType], person, item)
					SELECT count(*), heads.docType, heads.person, [rows].item FROM e10doc_core_rows AS [rows] LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx
					WHERE heads.docState = 4000 AND heads.dateAccounting > %d GROUP BY heads.docType, heads.person, item';
		$this->app->db()->query ($q, $minDate);
	}

	public function resetStatsDocsCounts ()
	{
		// -- e10-docs-all
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-docs-all');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s2, cnt, updated) ',
				'SELECT %s', 'e10-docs-all', ', docType, count(*), NOW() FROM e10doc_core_heads WHERE docStateMain < 4 GROUP BY docType'
		);

		// -- e10-docs-monthly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-docs-monthly');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s1, s2, cnt, updated) ',
				'SELECT %s', 'e10-docs-monthly', ', DATE_FORMAT(dateAccounting, %s', '%Y-%m', ') AS dateKey, docType, count(*), NOW() FROM e10doc_core_heads WHERE docStateMain < 4 GROUP BY docType, dateKey'
		);

		// -- e10-docs-yearly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-docs-yearly');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s1, s2, cnt, updated) ',
				'SELECT %s', 'e10-docs-yearly', ', DATE_FORMAT(dateAccounting, %s', '%Y', ') AS dateKey, docType, count(*), NOW() FROM e10doc_core_heads WHERE docStateMain < 4 GROUP BY docType, dateKey'
		);
	}

	public function onStats()
	{
		$this->resetStatsPersonDocType ();
		$this->resetStatsItemDocType ();
		$this->resetStatsPersonItemDocType ();
		$this->resetStatsDocsCounts();

		$this->dataSourceStatsCreate();
	}

	public function dataSourceStatsCreate()
	{
		$hostingCfg = utils::hostingCfg(['hostingGid', 'serverGid']);
		if ($hostingCfg === FALSE)
			return FALSE;

		$minDate = new \DateTime();
		$minDate = $minDate->sub(date_interval_create_from_date_string('12 months'));
		$minDate = $minDate->sub(date_interval_create_from_date_string('1 day'));

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		$dsStats->data['docs']['created'] = new \DateTime();

		// -- last document dateAccounting
		$ldc = $this->app->db()->query ('SELECT MAX(dateAccounting) AS lastDocDateAccounting FROM e10doc_core_heads WHERE docState = 4000')->fetch();
		if ($ldc && $ldc['lastDocDateAccounting'])
			$dsStats->data['docs']['lastDateAccounting'] = $ldc['lastDocDateAccounting']->format('Y-m-d H:i:s');

		// -- count documents
		$cnt12m = $this->app->db()->query ('select count(*) as c from e10doc_core_heads WHERE docState = 4000 AND dateAccounting > %d', $minDate)->fetch();
		$dsStats->data['docs']['last12m']['cnt'] = $cnt12m['c'];

		$cntAll = $this->app->db()->query ('select count(*) as c from e10doc_core_heads WHERE docState = 4000')->fetch();
		$dsStats->data['docs']['all']['cnt'] = $cntAll['c'];

		// -- count documents - cashregs only
		$cnt12m = $this->app->db()->query ('select count(*) as c from e10doc_core_heads WHERE docState = 4000 AND dateAccounting > %d', $minDate, ' AND [docType] = %s', 'cashreg')->fetch();
		$dsStats->data['cashreg']['last12m']['cnt'] = $cnt12m['c'];

		$cntAll = $this->app->db()->query ('select count(*) as c from e10doc_core_heads WHERE docState = 4000', ' AND [docType] = %s', 'cashreg')->fetch();
		$dsStats->data['cashreg']['all']['cnt'] = $cntAll['c'];

		// -- active users on documents
		$minDateMonth = new \DateTime('1 month ago');
		$users = $this->app->db()->query (
				'SELECT [user], COUNT(*) AS cnt FROM e10_base_docslog', ' WHERE [user] != 0 AND created > %d', $minDateMonth,
				' AND eventType = 0', ' AND tableid = %s', 'e10doc.core.heads', ' GROUP by user');
		$cntUsers = 0;
		$cntOps = 0;
		foreach ($users as $u)
		{
			$cntUsers++;
			$cntOps += $u['cnt'];
		}
		$dsStats->data['users']['lastMonth']['docs'] = ['users' => $cntUsers, 'ops' => $cntOps];

		// -- tax/vat/ros
		$vatReg = $this->app->cfgItem('e10doc.base.taxRegs', NULL);
		if ($vatReg)
			$dsStats->data['flags']['vat'] = 1;

		$rosReg = $this->app->cfgItem('terminals.ros.regs', NULL);
		if ($rosReg)
			$dsStats->data['flags']['ros'] = 1;

		// -- debs/sebs
		$accMethods = $this->app->cfgItem('e10doc.acc.usedMethods', NULL);
		if ($accMethods)
			$dsStats->data['accMethods'] = $accMethods;

		$dsStats->saveToFile();
	}

	public function setUsersGroups ()
	{
		\lib\docs\PersonsGroupsSetter::runAll ($this->app);
	}

	function cliRecalcDocs()
	{
		$e = new \e10doc\core\libs\RecalcDocuments($this->app);

		$dateFrom = $this->app->arg('date-from');
		if ($dateFrom)
			$e->setDateFrom($dateFrom);
		else
		{
			echo "ERROR: missing param `--date-from`\n";
			return;
		}

		$dateTo = $this->app->arg('date-to');
		if ($dateTo)
			$e->setDateTo($dateTo);
		else
		{
			echo "ERROR: missing param `--date-to`\n";
			return;
		}

		$docTypes = $this->app->arg('doc-types');
		if ($docTypes)
			$e->setDocTypes($docTypes);
		else
		{
			echo "ERROR: missing param `--doc-types`\n";
			return;
		}

		$mode = $this->app->arg('mode');
		if ($mode && in_array($mode, ['all', 'successors']))
			$e->mode = $mode;
		else
		{
			echo "ERROR: missing or bad param `--mode=all|successors`\n";
			return;
		}

		$e->run();
	}

	function cliDocsChecks()
	{
		$e = new \e10doc\core\libs\DocsChecks($this->app);
		if (!$e->detectArgs())
			return;

		$e->run();
	}

	function cliDocsChecksWrongJournal()
	{
		$e = new \e10doc\core\libs\DocsChecksWrongJournal($this->app);
		if (!$e->detectArgs())
			return;

		$e->run();
	}

	function cliCopyWitems()
	{
		$paramsFileName = $this->app->arg('params');
		if (!$paramsFileName)
		{
			echo "ERROR: missing param `--params=file-with-params.json`\n";
			return FALSE;
		}

		if (!is_file($paramsFileName))
		{
			echo "ERROR: file `$paramsFileName` not found...\n";
			return FALSE;
		}

		$params = utils::loadCfgFile($paramsFileName);
		if (!$params)
		{
			echo "ERROR: invalid file content - bad json syntax...\n";
			return FALSE;
		}

		$e = new \e10doc\core\libs\CopyWitems($this->app);
		$e->setRequestParams($params);

		$doFinalize = $this->app->arg('do-finalize');
		if ($doFinalize)
			$e->doFinalize = 1;

		$e->run();

		return TRUE;
	}

	protected function cliValidatePersons()
	{
		$maxCount = intval($this->app->arg('maxCount'));
		$debug = intval($this->app->arg('debug'));

		$e = new \e10doc\core\libs\PersonValidator($this->app);
		if ($maxCount)
			$e->maxCount = $maxCount;
		if ($debug)
			$e->debug = $debug;
		$e->batchCheck();
	}

	protected function cliRepairPersons()
	{
		$maxCount = intval($this->app->arg('maxCount'));
		$debug = intval($this->app->arg('debug'));

		$e = new \e10doc\core\libs\PersonValidator($this->app);
		if ($maxCount)
			$e->maxCount = $maxCount;
		if ($debug)
			$e->debug = $debug;
		$e->batchRepair();
	}

	public function cliNewDocFromAtt()
	{
		$paramAttNdx = intval($this->app->arg('attNdx'));
		if (!$paramAttNdx)
		{
			echo "ERROR: missing param `--attNdx`\n";
			return FALSE;
		}

		$e = new \e10doc\core\libs\DocFromAttachment($this->app());
		$e->init();
		$e->setAttNdx($paramAttNdx);
		$e->import();
	}

	public function cliResetDocFromAtt()
	{
		$paramAttNdx = intval($this->app->arg('attNdx'));
		if (!$paramAttNdx)
		{
			echo "ERROR: missing param `--attNdx`\n";
			return FALSE;
		}

		$paramDocNdx = intval($this->app->arg('docNdx'));
		if (!$paramDocNdx)
		{
			echo "ERROR: missing param `--docNdx`\n";
			return FALSE;
		}

		$e = new \e10doc\core\libs\DocFromAttachment($this->app());
		$e->init();
		$e->setAttNdx($paramAttNdx);
		$e->replaceDocumentNdx = $paramDocNdx;
		$e->reset($paramDocNdx);
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'recalc-docs': return $this->cliRecalcDocs();
			case 'docs-checks': return $this->cliDocsChecks();
			case 'docs-checks-wrong-journal': return $this->cliDocsChecksWrongJournal();
			case 'copy-witems': return $this->cliCopyWitems();
			case 'validate-persons': return $this->cliValidatePersons();
			case 'repair-persons': return $this->cliRepairPersons();
			case 'new-doc-from-att': return $this->cliNewDocFromAtt();
			case 'reset-doc-from-att': return $this->cliResetDocFromAtt();
		}

		parent::onCliAction($actionId);
	}

	public function onCronMorning ()
	{
		$this->setUsersGroups();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->onCronMorning(); break;
			case 'stats': $this->onStats(); break;
		}
		return TRUE;
	}
}
