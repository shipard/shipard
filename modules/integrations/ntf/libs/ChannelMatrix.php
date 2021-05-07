<?php

namespace integrations\ntf\libs;
use \e10\utils, \e10\json;


/**
 * Class ChannelMatrix
 * @package integrations\ntf\libs
 */
class ChannelMatrix extends \integrations\ntf\libs\ChannelCore
{
	public function delivery()
	{
		if (!$this->payload)
			return FALSE;

		if (isset($this->payload['bodyTextHtml']))
		{
			$sendData = [
				'msgtype' => 'm.text',
				'body' => $this->payload['subject'],
				"format" => "org.matrix.custom.html",
				"formatted_body" => $this->payload['bodyTextHtml'],
			];
		}
		else
		{
			$sendData = [
				'msgtype' => 'm.text',
				'body' => $this->payload['bodyTextPlain'],
			];
		}

		$sendDataText = json_encode($sendData);

		$url = 'https://'.$this->channelCfg['homeServer'].'/_matrix/client/r0/rooms/'.$this->channelCfg['roomID'].'/send/m.room.message?access_token='.$this->channelCfg['accessToken'];
		$this->deliveryStatus = utils::http_post($url, $sendDataText);

		//echo json::lint($this->deliveryStatus)."\n";
		sleep(1);

		$matrixResult = NULL;
		if (isset($this->deliveryStatus['content']))
			$matrixResult = json_decode($this->deliveryStatus['content'], TRUE);

		if ($matrixResult && isset($matrixResult['event_id']))
			return 1;

		return FALSE;
	}
}
