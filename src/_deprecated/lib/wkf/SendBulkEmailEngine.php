<?php

namespace lib\wkf;

use e10\Utility;


/**
 * Class SendBulkEmailEngine
 * @package lib\wkf
 */
class SendBulkEmailEngine extends Utility
{
	public function sendAllBulkEmails ()
	{
		$now = new \DateTime();

		$q[] = 'SELECT * FROM [e10pro_wkf_bulkEmails]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 1200, ' AND [sendingState] = %i', 2);
		array_push ($q, ' AND [dateReadyToSend] < %t', $now);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$recData = $r->toArray();
			$this->addTask($recData);
		}
	}

	public function addTask ($bulkEmailRecData)
	{
		$update = ['sendingState' => 3, 'docState' => 4000, 'docStateMain' => 2];
		$this->app()->db()->query ('UPDATE [e10pro_wkf_bulkEmails] SET ', $update, ' WHERE ndx = %i', $bulkEmailRecData['ndx']);

		$tableTasks = $this->app()->table('e10.base.tasks');

		$taskRec = [
			'title' => 'Rozeslat hromadný email',
			'classId' => 'lib.wkf.SendBulkEmailAction',
			'tableId' => 'e10pro.bume.bulkEmails', 'recId' => intval($bulkEmailRecData['ndx']),
			'params' => json_encode(['actionPK' => $bulkEmailRecData ['ndx']]),
			'timeCreate' => new \DateTime(),
			'docState' => 1000, 'docStateMain' => 0
		];

		$tableTasks->addTask($taskRec);
	}
}
