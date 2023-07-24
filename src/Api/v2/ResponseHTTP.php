<?php

namespace Shipard\Api\v2;


class ResponseHTTP extends \Shipard\Application\Response
{
	protected function init ()
	{
		$this->data ['success'] = 1;
		$this->data ["responseType"] = 0;
		$this->data ["serverSoftware"] = 'shipard/' . __E10_VERSION__;
		//if (isset ($_SERVER['HTTP_HOST']))
		//	$this->data ["serverRoot"] = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . '/';
	}

}
