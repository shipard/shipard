<?php

namespace swdev\translation\dc;

use e10\utils, e10\json;


/**
 * Class DictItem
 * @package swdev\translation\dc
 */
class DictItem extends \e10\DocumentCard
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

	function addHead()
	{
		$h = ['txt' => ' Vlastnost', 'v' => 'Hodnota'];
		$t = [];

		$t [] = [
			'txt' => $this->allLanguages[$this->srcLanguageNdx]['flag'],
			'v' => [['text' => $this->recData['text'], 'class' => 'block e10-bold']],
		];

		foreach ($this->userLanguages as $ul)
		{
			$txt = '--- nepřeloženo ---';
			$ds = 1000;
			if (isset($this->trTexts[$ul]))
			{
				$txt = $this->trTexts[$ul]['t'];
				$ds = $this->trTexts[$ul]['ds'];
				$pk = $this->trTexts[$ul]['pk'];
				$t[] = [
					'txt' => $this->allLanguages[$ul]['flag'],
					'v' => [
						'text' => $txt, 'actionClass' => 'block e10-ds-block '.$this->dsClasses[$ds],
						'docAction' => 'edit', 'table' => 'swdev.translation.dictsItemsTr', 'pk' => $pk,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
					]
				];
			}
			else
			{
				$t[] = [
					'txt' => $this->allLanguages[$ul]['flag'],
					'v' => [
						'text' => $txt, 'actionClass' => 'block e10-ds-block '.$this->dsClasses[$ds],
						'docAction' => 'new', 'table' => 'swdev.translation.dictsItemsTr',
						'addParams' => '__dictItem='.$this->recData['ndx'].'&__lang='.$ul,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
						]
				];
			}
		}

		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth dcInfo dcInfoB']
			]);
	}


	public function createContentBody ()
	{
		$this->addHead();
	}

	function loadTrTexts()
	{
		$q[] = 'SELECT * FROM [swdev_translation_dictsItemsTr]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [dictItem] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->trTexts[$r['lang']] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
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
