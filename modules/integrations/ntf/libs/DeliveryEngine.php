<?php

namespace integrations\ntf\libs;
use \e10\json, \e10\Utility;


/**
 * Class DeliveryEngine
 * @package integrations\ntf
 */
class DeliveryEngine extends Utility
{
	var $now;

	/** @var \integrations\ntf\TableChannels */
	var $tableChannels;

	function init()
	{
		$this->now = new \DateTime();

		$this->tableChannels = $this->app()->table('integrations.ntf.channels');
	}

	function checkNew()
	{
		return TRUE;
	}

	function sendAll()
	{
		$q[] = 'SELECT * FROM [integrations_ntf_delivery]';

		array_push($q, ' WHERE 1');

		array_push($q, ' AND [doDelivery] = %i', 1);
		array_push($q, ' AND [dtNextTry] <= %t', $this->now);
		array_push($q, ' ORDER BY [ndx], [dtNextTry]');
		array_push($q, ' LIMIT 20');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$this->sendOne($r);
		}
	}

	function sendOne($recData)
	{
		$channelRecData =$this->tableChannels->loadItem($recData['channel']);
		if (!$channelRecData)
			return;

		$channelTypeCfg = $this->app()->cfgItem ('integration.ntf.channels.types.'.$channelRecData['channelType'], NULL);
		if (!$channelTypeCfg)
			return;

		/** @var \integrations\ntf\libs\ChannelCore $channelObject */
		$channelObject = $this->app()->createObject($channelTypeCfg['classId']);
		if (!$channelObject)
			return;

		$channelObject->setChannelInfo($channelRecData);
		$channelObject->setPayload(json_decode($recData['payload'], TRUE));
		if ($channelObject->delivery() !== FALSE)
		{
			//echo "--OK--";
			$update = [
				'lastStatus' => json::lint($channelObject->deliveryStatus),
				'dtDelivery' => new \DateTime(),
				'doDelivery' => 0,
			];
			$this->db()->query('UPDATE [integrations_ntf_delivery] SET ', $update, ' WHERE ndx = %i', $recData['ndx']);
		}
		else
		{
			//echo "--FAIL--";
			$update = [
				'lastStatus' => json::lint($channelObject->deliveryStatus),
				'dtNextTry' => new \DateTime('+ 5 minutes'),
				'failedCounter' => $recData['failedCounter'] + 1,
			];
			$this->db()->query('UPDATE [integrations_ntf_delivery] SET ', $update, ' WHERE ndx = %i', $recData['ndx']);
		}
	}

	public function run()
	{
		$this->init();

		if (!$this->checkNew())
			return;

		$this->sendAll();
	}
}
