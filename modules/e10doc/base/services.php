<?php

namespace E10Doc\base;

class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkDocNumbers ();

		if ($this->checkClosePeriodsDocs1())
			$this->checkClosePeriodsDocs2();
	}

	public function onBeforeAppUpgrade ()
	{
		$this->upgradeAppOption ('options.experimental.docReportsType', 'options.appearanceDocs.docReportsType');
	}

	public function checkSystemSettings ($docType, $settings)
	{
		// -- dockinds
		foreach ($settings['dockinds'] as $dk)
		{
			$cnt = $this->app->db->query ('SELECT COUNT(*) as c FROM [e10doc_base_dockinds] '.
																		'WHERE docType = %s AND activity = %s', $docType, $dk['activity'])->fetch();
			if ($cnt['c'] != 0)
				continue;

			$newdk = [
					'docType' => $docType, 'activity' => $dk['activity'],
					'shortName' => $dk['fullName'], 'fullName' => $dk['fullName'],
					'docState' => 4000, 'docStateMain' => 2,
				];
			$this->app->db->query ("INSERT INTO [e10doc_base_dockinds]", $newdk);
		}

		// -- dbcounters
		foreach ($settings['docnumbers'] as $dc)
		{
			$useDocKinds = isset($dc['useDocKinds']) ? intval($dc['useDocKinds']) : 0;
			$docKind = isset($dc['docKind']) ? intval($dc['docKind']) : 0;
			$docKeyId = isset($dc['docKeyId']) ? $dc['docKeyId'] : '1';
			$activitiesGroup = isset($dc['activitiesGroup']) ? $dc['activitiesGroup'] : '';

			$cnt = $this->app->db->query ('SELECT COUNT(*) as c FROM [e10doc_base_docnumbers]',
				' WHERE docType = %s', $docType,
				//' AND docKind = %i', $docKind,
				' AND activitiesGroup = %s', $activitiesGroup
				//' AND docKeyId = %s', $docKeyId
				)->fetch();
			if ($cnt['c'] != 0)
				continue;

			$order = (isset($dc['order'])) ? $dc['order'] : 0;
			$newdn = [
				'docType' => $docType, 'docKeyId' => $docKeyId, 'useDocKinds' => $useDocKinds,
				'activitiesGroup' => $activitiesGroup,
				'shortName' => $dc['shortName'], 'fullName' => $dc['fullName'],
				'order' => $order,
				'docState' => 4000, 'docStateMain' => 2,
			];
			$this->app->db->query ("INSERT INTO [e10doc_base_docnumbers]", $newdn);
			$newDbNdx = $this->app->db->getInsertId ();

			// -- change dbCounterId in documents without dbCounter
			//$this->app->db->query ("update e10doc_core_heads set dbCounter = %i where dbCounter = 0 and docType = %s;", $newDbNdx, $docType);

			// -- change dbCounterId in documents without old id
			//if (isset($dc['oldndx']))
			//	$this->app->db->query ("update e10doc_core_heads set dbCounter = %i where dbCounter = %i", $newDbNdx, $dc['oldndx']);
		}
	}


	public function checkDocNumbers ()
	{
		$docTypes = $this->app->cfgItem ('e10.docs.types');
		forEach ($docTypes as $dtid => $dt)
		{
			if (!isset ($dt['docNumbers']))
				continue;

			$systemSettings = $this->app->cfgItem ('e10.docs.systemSettings.'.$dtid, FALSE);
			if ($systemSettings !== FALSE)
			{
				$this->checkSystemSettings($dtid, $systemSettings);
				continue;
			}

			$cnt = $this->app->db->query ("SELECT COUNT(*) as c FROM [e10doc_base_docnumbers] WHERE docType = %s", $dtid)->fetch();
			if ($cnt['c'] != 0)
				continue;

			$newdn = [
					'docType' => $dtid, 'docKeyId' => '1',
					'shortName' => $dt['shortName'], 'fullName' => $dt['fullName'],
					'docState' => 4000, 'docStateMain' => 1,
				];
			$this->app->db->query ("INSERT INTO [e10doc_base_docnumbers]", $newdn);
			$newDbNdx = $this->app->db->getInsertId ();

			// -- change dbCounterId in documents
			//$this->app->db->query ("update e10doc_core_heads set dbCounter = %i where dbCounter = 0 and docType = %s;", $newDbNdx, $dtid);
		}
	}

	function checkClosePeriodsDocs1()
	{
		$dbCounter = $this->app->db->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
			' AND [docKeyId] = %s', 'X', ' AND docState != %i', 9800)->fetch();
		if (!isset ($dbCounter['ndx']))
			return FALSE;

		$linkIdMask = "CLOSEACCPER;%";

		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_core_heads]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docType] = %s', 'cmnbkp');
		array_push($q, ' AND [linkId] LIKE %s', $linkIdMask);
		array_push($q, ' AND [dbCounter] != %i', $dbCounter['ndx']);
		array_push($q, ' ORDER BY [dateAccounting]');

		$cnt = 0;
		$rows = $this->app->db->query($q);
		foreach ($rows as $r)
		{
			$update = [
				'dbCounter' => $dbCounter['ndx'],
				'docNumber' => '!!'.sprintf('%07d', $r['ndx']),
			];

			$this->app->db->query('UPDATE [e10doc_core_heads] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
			$cnt++;
		}

		if (!$cnt)
			return TRUE;

		passthru('shpd-server app-fullupgrade');
		return FALSE;
	}

	function checkClosePeriodsDocs2()
	{
		$dbCounter = $this->app->db->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
			' AND [docKeyId] = %s', 'X', ' AND docState != %i', 9800)->fetch();
		if (!isset ($dbCounter['ndx']))
			return;

		$linkIdMask = "CLOSEACCPER;%";

		/** @var \e10doc\core\TableHeads $tableHeads */
		$tableHeads = $this->app->table('e10doc.core.heads');

		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_core_heads]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docType] = %s', 'cmnbkp');
		array_push($q, ' AND [linkId] LIKE %s', $linkIdMask);
		array_push($q, ' AND [dbCounter] = %i', $dbCounter['ndx']);
		array_push($q, ' AND [docNumber] LIKE %s', '!!%');
		array_push($q, ' ORDER BY [dateAccounting]');

		$rows = $this->app->db->query($q);
		foreach ($rows as $r)
		{
			$recData = $tableHeads->loadItem($r['ndx']);
			$docNumber = $tableHeads->makeDocNumber($recData);
			$update = ['docNumber' => $docNumber];
			$this->app->db->query('UPDATE [e10doc_core_heads] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
		}
	}

	public function ExchangeRateListsDownload ()
	{
		$e = new \e10doc\base\libs\ExchangeRateListsDownloader($this->app);

		$dateFrom = $this->app->arg('date-from');
		if ($dateFrom)
			$e->setDateFrom($dateFrom);

		$dateTo = $this->app->arg('date-to');
		if ($dateTo)
			$e->setDateTo($dateTo);

		if ($dateTo || $dateFrom)
			$e->interactive = 1;

		$e->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'exchange-rate-lists-download': return $this->ExchangeRateListsDownload();
		}

		return parent::onCliAction($actionId);
	}

	public function onCronEver ()
	{
		$this->ExchangeRateListsDownload();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case	'ever':   $this->onCronEver (); break;
		}
		return TRUE;
	}

	public function onCheckSystemData ()
	{
		$this->checkDocNumbers ();
		return TRUE;
	}
}
