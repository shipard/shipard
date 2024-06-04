<?php

namespace Shipard\Base;


class Utility
{
	/** @var \E10\Application */
	protected $app;
	protected $messagess = array ();

	var $errors = 0;

	public function __construct ($app)
	{
		$this->app = $app;
	}

	public function app() {return $this->app;}

	public function addMessage ($msgText)
	{
		$this->messagess [] = array ('text' => $msgText);
	}

	public function appendMessages ($messages)
	{
		if (!$messages)
			return;
		foreach ($messages as $m)
			$this->messagess[] = $m;
	}

	public function err ($msgText)
	{
		$this->messagess [] = array ('text' => $msgText);
		$this->errors += 1;
		error_log ($msgText);
		return FALSE;
	}

	protected function debug($msg)
	{
		error_log ($msg);
	}

	public function messagess ()
	{ // TODO: typo!
		if (count($this->messagess) === 0)
			return FALSE;
		return $this->messagess;
	}

	public function messages ()
	{
		if (count($this->messagess) === 0)
			return FALSE;
		return $this->messagess;
	}

	public function errorsHtml ()
	{
		$msgs = $this->messages();
		if (!$msgs)
			return '';


		$res = '';
		foreach ($msgs as $msg)
		{
			$res .= '* '.$msg['text']."\n";
		}

		return \Shipard\Utils\MiniMarkdown::render($res);
	}

	public function db () {return $this->app->db();}

	protected function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return $this->err ("read file failed: $fileName");
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return $this->err ("parsing file failed: $fileName");
			return $cfg;
		}
		return $this->err ("file not found: $fileName");
	}

	public function httpGet ($url, $json = TRUE, $timeout = 20)
	{
		$opts = array(
				'http'=>array(
						'timeout' => $timeout, 'method'=>"GET",
						'header'=>
								"Connection: close\r\n"
				)
		);
		$context = stream_context_create($opts);
		$resultCode = file_get_contents ($url, FALSE, $context);
		if ($json)
		{
			$resultData = json_decode($resultCode, TRUE);
			return $resultData;
		}

		return $resultCode;
	}
}
