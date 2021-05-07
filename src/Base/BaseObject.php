<?php

namespace Shipard\Base;


abstract class BaseObject
{
	/** @var \Shipard\Application\Application */
	public $app;

	public function __construct (\Shipard\Application\Application $app)
	{
		$this->app = $app;
	}

	public function app() {return $this->app;}
	public function db() {return $this->app->db;}
	public function broadcast ($msgId, $sender) {}
}
