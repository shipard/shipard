<?php

namespace lib\dataView;

use \Shipard\Utils\MiniMarkdown, e10\json, e10\Utility;


/**
 * Class DataView
 * @package lib
 */
class DataView extends Utility
{
	var $classId = '';
	var $remoteElementId = '';
	var $isRemoteRequest = 0;

	var $requestParams = NULL;
	var $data = [];
	var $debugLevel = 0;
	/** @var \Shipard\Report\TemplateMustache */
	var $template = NULL;
	var $secure = 0;

	var $userRoles = [];

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

		return MiniMarkdown::render($res);
	}

	protected function init()
	{
		if ($this->template)
			$this->secure = $this->template->serverInfo['secureWebPage'] ?? 0;
		if ($this->secure)
			$this->debugLevel = $this->requestParam ('debug', 0);
	}

	protected function loadData()
	{
	}

	protected function renderData()
	{
		$this->data['html'] = '';
		$this->data['html'] .= $this->remoteDataViewElementOpen();

		$webScriptId = $this->requestParam ('webScript', '');
		$showAs = $this->requestParam ('showAs', '');
		if ($webScriptId !== '')
		{
			$script = new \lib\web\WebScript($this->app());
			$script->setScriptId($webScriptId);
			$script->runScript($this->data);
			$this->appendMessages($script->messages());
			$this->data['html'] .= $script->resultCode;
		}
		elseif ($showAs !== '')
		{
			$this->data['html'] .= $this->renderDataAs($showAs);
		}
		elseif (isset($this->data['table']))
		{
			$this->checkTableHeader();
			$this->data['html'] .= \e10\renderTableFromArray($this->data['table'], $this->data['header'], [], $this->app());
		}
		if ($this->debugLevel)
		{
			$this->data['html'] .= "<!---- DATA BEGIN -----\n" . json::lint($this->data['table']) . "\n\n----- DATA END ---->\n\n";
		}

		$this->data['html'] .= $this->remoteDataViewElementClose();
	}

	protected function remoteDataViewElementOpen()
	{
		$c = '';
		if ($this->remoteElementId === '' || $this->isRemoteRequest)
			return $c;

		$c .= "<div id='$this->remoteElementId' class='e10-remote-pane e10-remote-dataView'";
		$c .= " data-web-action='e10-web-data-view'";
		$c .= " data-web-action-data-view-class-id='{$this->classId}'";
		$c .= " data-web-action-data-view-request-params='".base64_encode(json_encode($this->requestParams))."'";
		$c .= '>';

		return $c;
	}

	protected function remoteDataViewElementClose()
	{
		$c = '';
		if ($this->remoteElementId === '' || $this->isRemoteRequest)
			return $c;

		$c .= "</div>";

		return $c;
	}

	protected function renderDataAs($showAs)
	{
		$this->err("Unsupported `showAs` type `{$showAs}`");
		return '';
	}

	public function requestParam ($id, $defaultValue = '')
	{
		if (!isset($this->requestParams[$id]))
			return $defaultValue;

		return $this->requestParams[$id];
	}

	public function setRequestParams($params)
	{
		$this->requestParams = $params;
	}

	public function setTemplate($template)
	{
		$this->template = $template;
	}

	public function run()
	{
		$this->init();
		$this->loadData();
		$this->renderData();
	}

	protected function checkRequestParamsList($id, $isString = FALSE)
	{
		$list = [];
		if (!isset($this->requestParams[$id]))
			return;
		if (is_array($this->requestParams[$id]))
			return;

		$parts = explode (',', $this->requestParams[$id]);
		foreach ($parts as $p)
		{
			if ($isString)
				$list[] = $p;
			else
			{
				$pndx = intval($p);
				if ($pndx)
					$list[] = $pndx;
			}
		}
		if (count($list))
			$this->requestParams[$id] = $list;
		else
			unset($this->requestParams[$id]);
	}

	protected function checkTableHeader()
	{
		if (!isset($this->requestParams['tableColumns']))
			return;

		$tc = explode(',', $this->requestParams['tableColumns']);

		$oldHeader = $this->data['header'];
		$newHeader = [];

		foreach ($tc as $c)
		{
			$cid = trim($c);
			if (!isset($oldHeader[$cid]))
				continue;
			$newHeader[$cid] = $oldHeader[$cid];
		}

		$this->data['header'] = $newHeader;
	}

	protected function extendQuery (&$q)
	{
	}

	protected function reloadTo($baseUrl)
	{
		$url = $this->app->urlProtocol . $_SERVER['HTTP_HOST']. $this->app->urlRoot;
		$url .= $baseUrl;

		header ('Location: ' . $url);
		die();
	}

	protected function checkUser()
	{
		if ($this->app()->webEngine->authenticator && $this->app()->webEngine->authenticator->session && isset($this->app()->webEngine->authenticator->session['person']))
		{
			$userRecData = $this->tablePersons->loadItem($this->app()->webEngine->authenticator->session['person']);
			if ($userRecData)
			{
				$this->userRoles = explode('.', $userRecData['roles']);
				foreach ($this->userRoles as $role)
				{
					$this->data['user']['roles'][$role] = 1;
				}
			}
		}
	}
}

