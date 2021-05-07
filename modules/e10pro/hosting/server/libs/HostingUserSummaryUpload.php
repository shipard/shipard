<?php

namespace e10pro\hosting\server\libs;

use e10\Utility, \e10\json;


/**
 * Class HostingUserSummaryUpload
 * @package e10pro\hosting\server\libs
 */
class HostingUserSummaryUpload extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data)
			return;

		if (!isset($data['dsId']) || !isset($data['loginHash']))
			return;

		$dsId = intval($data['dsId']);
		$loginHash = $data['loginHash'];

		if (!$dsId || $loginHash === '')
			return;

		$userRecData = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE loginHash = %s', $loginHash,
			' AND [docState] IN %in', [4000, 8000])->fetch();
		if (!$userRecData)
		{
			$this->result ['msg'] = "Invalid loginHash `$loginHash`";
			return;
		}

		$dsRecData = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_datasources] WHERE [gid] = %i', $dsId,
			' AND [docState] IN %in', [4000, 8000])->fetch();
		if (!$dsRecData)
		{
			$this->result ['msg'] = "Invalid dsId `$dsId`";
			return;
		}

		$exist = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_udsSummary] WHERE [dataSource] = %i', $dsRecData['ndx'],
						' AND [user] = %i', $userRecData['ndx'])->fetch();
		if ($exist)
		{
			$update = [
				'cnt' => $data['cnt'],
				'cntUnread' => $data['data']['cntUnread'],
				'cntTodo' => $data['data']['cntTodo'],
				'checksum' => $data['checksum'],
				'data' => json::lint($data['data']),
				'updated' => new \DateTime(),
			];
			$this->db()->query('UPDATE [e10pro_hosting_server_udsSummary] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
		}
		else
		{
			$newItem = [
				'user' => $userRecData['ndx'], 'dataSource' => $dsRecData['ndx'], 'summaryType' => 0,
				'cnt' => $data['cnt'], 'checksum' => $data['checksum'],
				'data' => json::lint($data['data']),
				'updated' => new \DateTime(),
			];
			$this->db()->query('INSERT INTO [e10pro_hosting_server_udsSummary] ', $newItem);
		}

		$this->result ['success'] = 1;
	}
}
