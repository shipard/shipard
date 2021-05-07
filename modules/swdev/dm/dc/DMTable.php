<?php

namespace swdev\dm\dc;

use e10\utils, e10\json;


/**
 * Class DMTable
 * @package swdev\dm\dc
 */
class DMTable extends \e10\DocumentCard
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
			'txt' => ['text' => 'Název', 'icon' => 'icon-table'],
			'v' => [['text' => $this->recData['name'], 'class' => 'block']],
		];

		foreach ($this->userLanguages as $ul)
		{
			if (isset($this->trTexts['table'][$ul][0]))
			{
				$txt = $this->trTexts['table'][$ul]['0']['t'];
				$ds = $this->trTexts['table'][$ul]['0']['ds'];
				if ($txt === '')
					$txt = '--- nepřeloženo ---';
				$pk = $this->trTexts['table'][$ul]['0']['pk'];
				$t[0]['v'][] = [
					'text' => $this->allLanguages[$ul]['flag'].' '.$txt, 'actionClass' => 'block e10-ds-block '.$this->dsClasses[$ds],
					'docAction' => 'edit', 'table' => 'swdev.dm.dmTrTexts', 'pk' => $pk,
					'type' => 'span', '_actionClass' => '', '_btnClass' => '',
				];
			}
		}

		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-top', 'type' => 'table',
				'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth dcInfo dcInfoB']
			]);
	}

	function addColumns()
	{
		$q[] = 'SELECT * FROM [swdev_dm_columns] AS [cols]';
		array_push($q, ' WHERE [cols].[table] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [cols].ndx');

		$tc = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'id' => $r['id'],
				'name' => [['text' => $r['name'], 'class' => 'block e10-bold']],
				'label' => [['text' => ($r['label']) ? $r['label'] : '', 'class' => 'block e10-bold']]
			];

			foreach ($this->userLanguages as $ul)
			{
				if (isset($this->trTexts['cols'][$r['ndx']][$ul]['1']))
				{
					$txt = $this->trTexts['cols'][$r['ndx']][$ul]['1']['t'];
					$ds = $this->trTexts['cols'][$r['ndx']][$ul]['1']['ds'];
					if ($txt === '')
						$txt = '--- nepřeloženo ---';
					$pk = $this->trTexts['cols'][$r['ndx']][$ul]['1']['pk'];
					$item['name'][] = [
						'text' => $this->allLanguages[$ul]['flag'].' '.$txt, 'actionClass' => 'block e10-ds-block '.$this->dsClasses[$ds],
						'docAction' => 'edit', 'table' => 'swdev.dm.dmTrTexts', 'pk' => $pk,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
					];
				}
				if (isset($this->trTexts['cols'][$r['ndx']][$ul]['2']))
				{
					$txt = $this->trTexts['cols'][$r['ndx']][$ul]['2']['t'];
					$ds = $this->trTexts['cols'][$r['ndx']][$ul]['2']['ds'];
					if ($txt === '')
						$txt = '--- nepř. ---';
					$pk = $this->trTexts['cols'][$r['ndx']][$ul]['2']['pk'];
					$item['label'][] = [
						'text' => $this->allLanguages[$ul]['flag'].' '.$txt, 'actionClass' => 'block e10-ds-block '.$this->dsClasses[$ds],
						'docAction' => 'edit', 'table' => 'swdev.dm.dmTrTexts', 'pk' => $pk,
						'type' => 'span', '_actionClass' => '', '_btnClass' => '',
					];
				}
			}

			$tc[] = $item;
		}

		$hc = ['#' => '#', 'id' => 'id', 'name' => 'Název', 'label' => 'Titulek'];

		$title = [['icon' => 'icon-columns', 'text' => 'Sloupce tabulky']];
		foreach ($this->userLanguages as $ul)
		{
			if (!in_array($ul, $this->UILangs))
				continue;

			$title[] = [
				'type' => 'action', 'action' => 'addwizard', '__data-table' => 'wkf.core.issues', 'data-pk' => strval($this->recData['ndx']), 'data-addParams' => 'lang='.$ul,
				'text' => $this->allLanguages[$ul]['flag'].' Přeložit', 'title' => 'Přeložit všechny sloupce tabulky', 'data-class' => 'swdev.dm.libs.WizardTranslationTable',
				'_icon' => 'icon-magic',
				'element' => 'span', 'class' => 'pull-right e10-small', 'actionClass' => '', 'btnClass' => 'btn-sm btn-default',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default'
			];
		}

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'table' => $tc, 'header' => $hc,
			'params' => ['_hideHeader' => 1, 'forceTableClassXXX' => 'fullWidth'],
			'title' => $title
		]);
	}

	public function createContentBody ()
	{
		$this->addHead();
		$this->addColumns();
	}

	function loadTrTexts()
	{
		$q[] = 'SELECT * FROM [swdev_dm_dmTrTexts]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [table] = %i', $this->recData['ndx']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['textType'] == 0)
				$this->trTexts['table'][$r['lang']][0] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
			elseif ($r['textType'] == 1 || $r['textType'] == 2)
				$this->trTexts['cols'][$r['column']][$r['lang']][$r['textType']] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
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
