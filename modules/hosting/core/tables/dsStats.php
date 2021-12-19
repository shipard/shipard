<?php

namespace hosting\core;

use \e10\DbTable, \e10\utils;

/**
 * Class TableDatasourcesStats
 */
class TableDSStats extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('hosting.core.dsStats', 'hosting_core_dsStats', 'Statistiky zdrojů dat');
	}

	public function upload ()
	{
		$uploadString = $this->app()->testGetData ();
		$uploadData = json_decode($uploadString, TRUE);
		if ($uploadData === FALSE)
		{
			error_log ("TableDSStats::update parse data error: ".json_encode($uploadData));
			return 'FALSE';
		}

		$dsid = $uploadData['dsid'];
		$dataSource = $this->db()->query ('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid)->fetch();
		if (!$dataSource)
		{
			error_log ("TableDSStats::invalid dsid '{$dsid}': ".json_encode($uploadData));
			return 'FALSE';
		}

		$i = [
			'dateUpdate' => $uploadData['created'],

			'usageDb' => $uploadData['diskUsage']['db'], 'usageFiles' => $uploadData['diskUsage']['fs'],
			'usageTotal' => $uploadData['diskUsage']['db'] + $uploadData['diskUsage']['fs'],

			'cntDocuments12m' => isset($uploadData['docs']['last12m']['cnt']) ? $uploadData['docs']['last12m']['cnt'] : 0,
			'cntDocumentsAll' => isset($uploadData['docs']['all']['cnt']) ? $uploadData['docs']['all']['cnt'] : 0,
			'cntIssues12m' => isset($uploadData['issues']['last12m']['cnt']) ? $uploadData['issues']['last12m']['cnt'] : 0,
			'cntIssuesAll' => isset($uploadData['issues']['all']['cnt']) ? $uploadData['issues']['all']['cnt'] : 0,
			'cntCashRegs12m' => isset($uploadData['cashreg']['last12m']['cnt']) ? $uploadData['cashreg']['last12m']['cnt'] : 0,
			'cntCashRegsAll' => isset($uploadData['cashreg']['all']['cnt']) ? $uploadData['cashreg']['all']['cnt'] : 0,
			'cntUsersAll1m' => isset($uploadData['users']['lastMonth']['all']['users']) ? $uploadData['users']['lastMonth']['all']['users'] : 0,
			'cntUsersActive1m' => isset($uploadData['users']['lastMonth']['active']['users']) ? $uploadData['users']['lastMonth']['active']['users'] : 0,
			'cntUsersDocs1m' => isset($uploadData['users']['lastMonth']['docs']['users']) ? $uploadData['users']['lastMonth']['docs']['users'] : 0,

			'data' => json_encode($uploadData),
		];

		$existed = $this->db()->query ('SELECT * FROM hosting_core_dsStats WHERE [dataSource] = %i', $dataSource['ndx'])->fetch();
		if ($existed)
		{
			$this->db()->query ('UPDATE hosting_core_dsStats SET ', $i, ' WHERE ndx = %i', $existed['ndx']);
		}
		else
		{
			$i['dataSource'] = $dataSource['ndx'];
			$this->db()->query ('INSERT INTO hosting_core_dsStats ', $i);
		}

		return 'OK';
	}
}
