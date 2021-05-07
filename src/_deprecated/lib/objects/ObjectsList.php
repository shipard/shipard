<?php

namespace lib\objects;
use E10\Service, E10\Response, E10\utils;


/**
 * @param array $data
 * @param bool $rootElement
 * @return string
 */
function arrayToXml (array $data, $rootElement = FALSE)
{
	$c = '';
	if ($rootElement)
		$c .= '<'.$rootElement.'>';
	foreach ($data as $key => $value)
	{
		$keyName = (is_int($key) ? 'item' : $key);
		$c .= '<'.$keyName.'>';
		if (is_array($value))
			$c .= arrayToXml($value);
		else
			$c .= htmlspecialchars ($value, ENT_XML1);
		$c .= '</'.$keyName.'>';
	}
	if ($rootElement)
		$c .= '</'.$rootElement.'>';

	return $c;
}


/**
 * Class ObjectsList
 * @package lib\objects
 */
class ObjectsList extends Service
{
	CONST JSON = 1, XML = 2;
	static $formats = ['json' => self::JSON, 'xml' => self::XML];

	var $syncSrc = 0;
	var $format = self::JSON;
	var $jsonEncodeOptions = 0;
	var $httpStatus = 200;

	var $tableId = '';
	var $table;

	/** @var \lib\objects\ObjectsListIterator */
	var $objectsIterator = NULL;

	protected $fileWriter;
	protected $fileName;

	protected $rowNumber = 1;

	protected function detectParams ()
	{
		$this->params = $this->app->detectParams();

		if (isset ($this->params['format']))
		{
			if (in_array($this->params['format'], ['json', 'xml']))
				$this->format = self::$formats[$this->params['format']];
			else
				$this->addMessage("Invalid 'format' param value");
		}

		if (isset ($this->params['syncSrc']))
		{
			if (utils::is_uint ($this->params['syncSrc']))
				$this->syncSrc = intval ($this->params['syncSrc']);
			else
				$this->addMessage("Invalid 'syncSrc' param value");
		}
	}

	protected function createIterator ()
	{
		$this->objectsIterator = new \lib\objects\ObjectsListIterator ($this->app);
		$this->objectsIterator->setTable($this->table);
		$this->objectsIterator->setParams($this->params);
	}

	protected function checkAccess ()
	{
		$accessLevel = $this->app->checkAccess(['object' => 'object', 'table' => $this->tableId]);
		if (!$accessLevel)
			FALSE;

		return TRUE;
	}

	public function checkErrors ()
	{
		$this->tableId = $this->app->requestPath(3);
		$this->table = $this->app->table($this->tableId);

		if ($this->table === NULL)
			return 'invalid table id';

		return FALSE;
	}

	public function run ()
	{
		$this->jsonEncodeOptions = JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;

		$this->detectParams();

		$error = $this->checkErrors();
		if ($error !== FALSE)
			return new Response ($this->app, $error, 404);

		if (!$this->checkAccess())
			return new Response ($this->app, 'forbidden', 403);

		$this->createIterator();
		if ($this->objectsIterator === NULL)
			return new Response ($this->app, $error, 404);

		$this->objectsIterator->select ();

		$this->fileOpen();

		$doIt = TRUE;
		if ($this->messagess() !== FALSE)
		{
			$errors = ['error' => ['messages' => $this->messagess()]];
			$this->fileAppend($errors, FALSE);
			$doIt = FALSE;
			$this->httpStatus = 400;
		}

		if ($doIt)
		{
			$doIt = ($this->objectsIterator->messagess() === FALSE);
			while ($doIt)
			{
				$object = $this->objectsIterator->nextObject();
				if ($object === FALSE)
				{
					break;
				}
				$this->fileAppend($object);
				$this->rowNumber++;
			}
			if ($this->objectsIterator->messagess() !== FALSE)
			{
				$errors = ['error' => ['messages' => $this->objectsIterator->messagess()]];
				$this->fileAppend($errors, FALSE);
				$this->httpStatus = 400;
			}
		}

		$this->fileClose();

		if ($this->httpStatus === 200)
		{
			$r = new Response($this->app, '');
			$r->setFile($this->fileName, $this->mimeType(), $this->saveFileName(), 'inline');
		}
		else
		{
			$r = new Response($this->app, file_get_contents($this->fileName), $this->httpStatus);
			$r->setMimeType($this->mimeType());
		}
		return $r;
	}

	protected function service ()
	{
		return FALSE;
	}

	protected function fileAppend ($object, $rootElement = 'object')
	{
		$data = '';
		switch ($this->format)
		{
			case self::JSON:
				if ($this->rowNumber !== 1)
					$data = ", \n";
				$data .= json_encode ($object, $this->jsonEncodeOptions);
				break;
			case self::XML:
				$data = arrayToXml ($object, $rootElement);
				$data .= "\n";
				break;
		}
		fwrite ($this->fileWriter, $data);
	}

	protected function fileClose ()
	{
		switch ($this->format)
		{
			case self::JSON:
				$data = "\n]\n";
				break;
			case self::XML:
				$data = "</list>\n";
				break;
		}
		fwrite ($this->fileWriter, $data);

		fclose ($this->fileWriter);
	}

	protected function fileOpen ()
	{
		$this->fileName = utils::tmpFileName('.fileList');
		$this->fileWriter = fopen ($this->fileName, 'w');

		switch ($this->format)
		{
			case self::JSON:
				$data = "[\n";
				break;
			case self::XML:
				$data = "<?xml version=\"1.0\"?>\n"."<list>\n";
				break;
		}
		fwrite ($this->fileWriter, $data);
	}

	protected function mimeType ()
	{
		switch ($this->format)
		{
			case self::JSON:
				return 'text/json';
			case self::XML:
				return 'text/xml';
		}

		return '';
	}

	protected function saveFileName ()
	{
		$sfn = $this->tableId.'.';
		switch ($this->format)
		{
			case self::JSON:
				$sfn .= 'json';
				break;
			case self::XML:
				$sfn .= 'xml';
				break;
		}

		return utils::safeChars($sfn, TRUE);
	}
}
