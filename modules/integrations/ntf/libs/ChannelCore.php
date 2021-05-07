<?php

namespace integrations\ntf\libs;
use \e10\utils, \e10\Utility;


/**
 * Class ChannelCore
 * @package integrations\ntf\libs
 */
class ChannelCore extends Utility
{
	var $channelRecData = NULL;
	var $channelCfg = NULL;
	var $payload =  NULL;
	var $deliveryStatus = NULL;

	public function setChannelInfo($channelRecRata)
	{
		$this->channelRecData = $channelRecRata;
		$this->channelCfg = json_decode($channelRecRata['channelCfg'], TRUE);
	}

	public function setPayload($payload)
	{
		$this->payload = $payload;
	}

	public function delivery()
	{
		return FALSE;
	}
}
