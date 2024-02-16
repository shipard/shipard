<?php

namespace Shipard\Application;
use \Shipard\Utils\Json;
use \e10\utils;


class AppLog extends \Shipard\Base\BaseObject
{
	var $taskId;
	var $taskType = 0;
	var $taskKind = 0;
	var $serverGid = 0;
	var $dsId = 0;
	var $userId = 0;
	var $events = [];
	var $timeStart;
	var $timeStop;

	CONST ttNone = 0, ttHttpApi = 1, ttHttpFeed = 2, ttWeb = 3, ttHttpAppPanel = 4;
	CONST tkNone = 0, tkForm = 1, tkViewer = 2, tkWidget = 3, tkWizard = 4, tkWindow = 5, tkReport = 6, tkObjects = 7, tkViewerDetail = 8,
			tkViewerPanel = 9, tkFormReport = 10, tkCall = 11, tkF = 12, tkDocumentCard = 14, tkApiRun = 15, tkApiV2 = 16;
	CONST ssNone = 0, ssTask = 1, ssSql = 2;

	function init()
	{
		$this->timeStart = microtime(TRUE);
		$this->taskId = strval (microtime(TRUE).'.'.mt_rand(10000, 99999));

		$e = $this->newEvent();
		$e['subsystem'] = self::ssTask;
		$e['cntSqlCmds'] = 0;
		$e['maxSqlCmdLen'] = 0;

		$this->events[] = $e;
	}

	function init2()
	{
		//$hostingCfg = utils::hostingCfg(['hostingGid', 'serverGid']);
		//if ($hostingCfg)  --TODO--
			$this->serverGid = "123456";//$hostingCfg['serverGid'];

		$this->dsId = $this->app->cfgItem ('dsid');
		$this->events[0]['dsId'] = $this->dsId;
		$this->events[0]['server'] = $this->serverGid;
	}

	public function setTaskType ($type, $kind)
	{
		$this->taskType = $type;
		$this->taskKind = $kind;
		$this->events[0]['taskType'] = $type;
		$this->events[0]['taskKind'] = $kind;

		$this->events[0]['cntSqlCmds'] = 0;
		$this->events[0]['maxSqlCmdLen'] = 0;

		$this->events[0]['url'] = $this->app->requestPath();
	}

	public function setUser ()
	{
		$this->userId = $this->app->userNdx();
	}

	function addLogItem ()
	{
	}

	function addSql ($cmd, $timeLen)
	{
		$e = $this->newEvent();
		$e['cmd'] = $cmd;
		$e['timeLen'] = $timeLen;
		$e['subsystem'] = self::ssSql;
		$this->events[] = $e;

		if ($timeLen > $this->events[0]['maxSqlCmdLen'])
			$this->events[0]['maxSqlCmdLen'] = $timeLen;

		$this->events[0]['cntSqlCmds']++;
	}

	function newEvent()
	{
		$e = [
				'server' => $this->serverGid, 'dsId' => $this->dsId,
				'taskId' => $this->taskId, 'taskType' => $this->taskType, 'taskKind' => $this->taskKind,
				'user' => $this->userId, 'time' => strval(microtime(TRUE)), 'subsystem' => self::ssNone
		];

		return $e;
	}

	function flush()
	{
		$this->timeStop = microtime(TRUE);
		$this->events[0]['timeLen'] = $this->timeStop - $this->timeStart;

		$maxBytes = 64000;

		$dataStr = Json::lint ($this->events);

		if ($this->app->cfgItem ('dsMode') === Application::dsmDevel)
		{
			file_put_contents(__APP_DIR__.'/tmp/__applog_'.time().'_'.mt_rand(100000, 999999).'_'.count($this->events).'_'.$this->events[0]['timeLen'].'.json', $dataStr);
			return;
		}

		/*
		if (strlen($dataStr) < $maxBytes)
		{
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			socket_sendto($sock, $dataStr, strlen($dataStr), 0, '127.0.0.1', 10785);
			socket_close($sock);
		}
		else
		{
			$size = intval (count($this->events) / (strlen($dataStr) / $maxBytes * 2 + 1));
			if ($size < 3)
				$size = 3;

			$blocks = array_chunk ($this->events, $size);
			foreach ($blocks as $oneBlock)
			{
				if (count($oneBlock))
				{
					$dataStr = Json::lint ($oneBlock);
					$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
					socket_sendto($sock, $dataStr, strlen($dataStr), 0, '127.0.0.1', 10785);
					socket_close($sock);
				}
			}
		}
		*/
	}
}

