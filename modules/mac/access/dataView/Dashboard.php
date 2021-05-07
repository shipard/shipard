<?php

namespace mac\access\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class Dashboard
 * @package mac\access\dataView
 */
class Dashboard extends DataView
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;

	/** @var \mac\access\TableTags */
	var $tableTags;

	var $tagNdx = 0;
	var $tagInfo = [];

	protected function init()
	{
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tableTags = $this->app()->table('mac.access.tags');

		$this->requestParams['showAs'] = 'webAppWidget';

		parent::init();
	}

	protected function loadData()
	{
		$this->loadDataTagInfo();
		$this->loadScenarios();
	}

	protected function loadDataTagInfo()
	{
		$this->tagNdx = 0;
		if ($this->app()->webEngine->authenticator && $this->app()->webEngine->authenticator->session)
			$this->tagNdx = $this->app()->webEngine->authenticator->session['loginTag'];

		$this->checkUser();

		if (!$this->tagNdx)
			return;

		$tagInfo = new \mac\access\libs\TagInfo($this->app());
		$tagInfo->init();
		$tagInfo->setTag($this->tagNdx);
		$tagInfo->load();

		$this->data['tagInfo'] = $tagInfo->tagInfo;
	}

	protected function loadScenarios()
	{
		$this->data['scenarios'] = [];

		$q = [];
		array_push ($q, 'SELECT [scenarios].*');
		array_push ($q, ' FROM [mac_iot_scenarios] AS [scenarios]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [scenarios].docState IN %in', [4000, 8000]);
		array_push ($q, ' AND [scenarios].enableManualRun = %i', 1);

		array_push ($q, ' ORDER BY [scenarios].[order], [scenarios].[shortName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'title' => $r['shortName'],
			];
			$this->data['scenarios'][] = $item;
		}
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWebAppWidget();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsWebAppWidget()
	{
		foreach ($this->data as $key => $value)
			$this->template->data[$key] = $value;

		$c = '';

		$c .= $this->template->renderSubTemplate ('mac.access.dashboard');
		//$c .= "TEST: ".json_encode(/*$this->app()->user()->data*/$this->userRoles)."!!!";

		return $c;
	}
}
