<?php

namespace Shipard\Application;
use \Shipard\Utils\Utils;


class Response
{
	var $app;
	private $code = "";
	private $status = 200;
	private $mimeType = "text/html; charset=UTF-8";
	var $data = [];
	private $fileName = "";
	private $rawData = NULL;
	private $saveFileName = '';
	private $contentDisposition = '';

	private static $messages = array (
		// -- Informational 1xx
		100=>'100 Continue', 101=>'101 Switching Protocols',
		// -- Successful 2xx
		200=>'200 OK', 201=>'201 Created', 202=>'202 Accepted', 203=>'203 Non-Authoritative Information', 204=>'204 No Content',
		205=>'205 Reset Content', 206=>'206 Partial Content',
		// -- Redirection 3xx
		300=>'300 Multiple Choices', 301=>'301 Moved Permanently', 302=>'302 Found', 303=>'303 See Other', 304=>'304 Not Modified',
		305=>'305 Use Proxy', 306=>'306 (Unused)', 307=>'307 Temporary Redirect',
		// -- Client Error 4xx
		400=>'400 Bad Request', 401=>'401 Unauthorized', 402=>'402 Payment Required', 403=>'403 Forbidden', 404=>'404 Not Found',
		405=>'405 Method Not Allowed', 406=>'406 Not Acceptable', 407=>'407 Proxy Authentication Required', 408=>'408 Request Timeout',
		409=>'409 Conflict', 410=>'410 Gone', 411=>'411 Length Required', 412=>'412 Precondition Failed', 413=>'413 Request Entity Too Large',
		414=>'414 Request-URI Too Long', 415=>'415 Unsupported Media Type', 416=>'416 Requested Range Not Satisfiable', 417=>'417 Expectation Failed',
		// -- Server Error 5xx
		500=>'500 Internal Server Error', 501=>'501 Not Implemented', 502=>'502 Bad Gateway', 503=>'503 Service Unavailable',
		504=>'504 Gateway Timeout', 505=>'505 HTTP Version Not Supported');

	public function __construct (Application $app, $data="", $status=200)
	{
		$this->app = $app;
		$this->code = $data;
		$this->status = $status;
	}

	public function status () {return $this->status;}
	public function mimeType () {return $this->mimeType;}

	public function add ($key, $value)
	{
		if (count ($this->data) === 0)
			$this->init ();
		$this->data [$key] = $value;
	}

	public function addSubObject ($objectId, $value)
	{
		if (count ($this->data) === 0)
			$this->init ();
		$this->data ['subObjects'][] = ['id' => $objectId, 'type' => $value];
	}

	public function addSubObjectPart ($objectId, $partId, $value)
	{
		if (count ($this->data) === 0)
			$this->init ();
		$idx = count($this->data ['subObjects']) - 1;
		$this->data ['subObjects'][$idx][$partId] = $value;
	}

	public function data ()
	{
		if ($this->rawData)
			return $this->rawData;

		if (count ($this->data) != 0)
		{
			$cbk = $this->app->testGetParam ('callback');

			if ($cbk != "")
				return $cbk . "(" . json_encode ($this->data) . ')';
			return json_encode ($this->data);
		}
		return $this->code;
	}

	public function dataItem ($key)
	{
		if (isset ($this->data[$key]))
			return $this->data[$key];
		return FALSE;
	}

	protected function init ()
	{
		$this->data ["success"] = 1;
		$this->data ["responseType"] = 0;
		$this->data ["serverSoftware"] = 'e10/' . __E10_VERSION__;
		if (isset ($_SERVER['HTTP_HOST']))
			$this->data ["serverRoot"] = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->urlRoot . '/';
		$this->data ["user"] = $this->app->user ()->data ();
	}

	public function send ()
	{
		if ($this->fileName != "")
		{
			$httpServer = $this->app->cfgItem ('serverInfo.httpServer', 0);

			header ("Cache-control: no-cache, no-store");
			header ("Content-type: " . $this->mimeType ());
			header ("Content-Disposition: ".$this->contentDisposition."; filename*=UTF-8''" . rawurlencode(Utils::safeChars($this->saveFileName, TRUE)));
			if ($httpServer === 0)
				header ('X-SendFile: ' . $this->fileName);
			else
				header ('X-Accel-Redirect: ' . $this->app->urlRoot.substr($this->fileName, strlen(__APP_DIR__)));
			die();
		}

		header ('X-Frame-Options: SAMEORIGIN');
		header ("Content-Security-Policy: frame-ancestors 'self' *.shipard.cz *.shipard.pro *.shipard.app shipard.app;");

		if ($this->saveFileName !== '')
			header ("Content-Disposition: ".$this->contentDisposition."; filename*=UTF-8''" . rawurlencode(Utils::safeChars($this->saveFileName, TRUE)));

		header ("Content-type: " . $this->mimeType ());

		header("HTTP/1.1 " . self::$messages [$this->status]);
		echo $this->data();
	}

	public function setMimeType ($mimeType)
	{
		$this->mimeType = $mimeType;
	}

	public function setFile ($fileName, $mimeType, $saveFileName, $contentDisposition = 'inline')
	{
		$this->fileName = $fileName;
		$this->mimeType = $mimeType;
		$this->saveFileName = $saveFileName;
		$this->contentDisposition = $contentDisposition;

		if (!$this->saveFileName || $this->saveFileName === '')
			$this->saveFileName = basename($fileName);
	}

	public function setSaveFileName ($saveFileName, $contentDisposition = 'inline')
	{
		$this->saveFileName = $saveFileName;
		$this->contentDisposition = $contentDisposition;
	}

	public function setRawData($data)
	{
		$this->rawData = $data;
	}

	public function setStatus ($status)
	{
		$this->status = $status;
	}
}


