<?php

namespace swdev\dm\dc;

use e10\utils, e10\json;


/**
 * Class DMEnum
 * @package swdev\dm\dc
 */
class DMEnum extends \e10\DocumentCard
{
	var $allLanguages;
	var $userLanguages;
	var $UILangs;
	var $srcLanguageNdx = 6;
	var $srcLanguage;
	var $trTexts = [];

	var $dsClasses = [
		1000 => 'e10-docstyle-concept',
		1200 => 'e10-docstyle-halfdone',
		4000 => 'e10-docstyle-done',
		8000 => 'e10-docstyle-edit',
	];

	function addValues()
	{
		$h = ['_v' => ' Hodn.'];
		$t = [];

		$config = json_decode($this->recData['config'], TRUE);

		foreach ($config['textsIds'] as $columnId => $columnTitle)
			$h[$columnId] = $columnTitle;

		/*
		$t [] = [
			'txt' => $this->allLanguages[$this->srcLanguageNdx]['flag'],
			'v' => [['text' => $this->recData['text'], 'class' => 'block e10-bold']],
		];
		*/

		$q[] = 'SELECT [values].* FROM [swdev_dm_enumsValues] AS [values]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [values].[enum] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$value = $r['value'];
			$columnId = $r['columnId'];
			if (!isset($t[$value]))
				$t[$value] = ['_v' => strval($value)];
			if (!isset($t[$value][$columnId]))
				$t[$value][$columnId] = [];

			$t[$value][$columnId][] = ['text' => $r['text'], 'class' => 'block e10-bold'];


			foreach ($this->userLanguages as $ul)
			{
				$txt = $this->allLanguages[$ul]['flag'].' '.'--- nepřel. ---';
				$ds = 1000;
				if (isset($this->trTexts[$value][$columnId][$ul]))
				{
					$txt = $this->allLanguages[$ul]['flag'].' '.$this->trTexts[$value][$columnId][$ul]['t'];
					$ds = $this->trTexts[$value][$columnId][$ul]['ds'];
					$pk = $this->trTexts[$value][$columnId][$ul]['pk'];
					$t[$value][$columnId][] = [
						'text' => $txt, 'actionClass' => 'block e10-ds-block ' . $this->dsClasses[$ds],
						'docAction' => 'edit', 'table' => 'swdev.dm.enumsValuesTr', 'pk' => $pk,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
					];
				}
				else {
					$t[$value][$columnId][] = [
						'text' => $txt, 'actionClass' => 'block e10-ds-block ' . $this->dsClasses[$ds],
						'docAction' => 'new', 'table' => 'swdev.dm.enumsValuesTr',
						'addParams' => '__enum='.$this->recData['ndx'].'&__enumValue='.$r['ndx'].'&__lang='.$ul,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
					];
				}
			}
		}

		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $t, 'main' => TRUE,
			]);
	}

	public function createContentBody ()
	{
		$this->addValues();
	}

	function loadTrTexts()
	{
		$q[] = 'SELECT [tr].*, [ev].columnId, [ev].[value] FROM [swdev_dm_enumsValuesTr] AS [tr]';
		array_push($q, ' LEFT JOIN [swdev_dm_enumsValues] AS [ev] ON [tr].enumValue = [ev].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [tr].[enum] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->trTexts[$r['value']][$r['columnId']][$r['lang']] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
		}
	}

	public function createContent ()
	{
		$this->userLanguages = $this->app()->cfgItem('swdev.tr.translators.'.$this->app()->userNdx(), []);

		$this->allLanguages = $this->app()->cfgItem ('swdev.tr.lang.langs', []);
		$this->srcLanguage = $this->allLanguages[$this->srcLanguageNdx];
		$this->UILangs = $this->app()->cfgItem('swdev.tr.lang.ui', []);

		if (!count($this->userLanguages))
			$this->userLanguages[] = 1;

		$this->loadTrTexts();
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
