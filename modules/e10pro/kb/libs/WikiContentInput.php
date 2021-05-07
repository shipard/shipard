<?php

namespace e10pro\kb\libs;

use \lib\core\ui\TreeView;


/**
 * Class WikiContentInput
 * @package e10pro\kb\libs
 */
class WikiContentInput extends TreeView
{
	/** @var \e10pro\kb\TableWikies */
	var $tableWikies;

	var $wikies = NULL;
	var $sections = NULL;

	public function init()
	{
		parent::init();

		$this->tableWikies = $this->app()->table('e10pro.kb.wikies');

		$this->objectClassId = 'e10pro.kb.libs.WikiContentInput';

		$this->header = [
			'title' => '>title',
			'ndx' => '>id',
		];

		$this->colClasses['ndx'] = 'nowrap width10em number';
	}

	function loadData()
	{
		$this->loadWikies();
		if ($this->level === 0)
		{
			$this->loadData_Wikies();
			return;
		}
		if ($this->level === 1)
		{
			$this->loadData_Sections();
			return;
		}

		$this->loadData_Pages();
	}

	function loadData_Wikies()
	{
		foreach ($this->wikies as $wikiNdx => $wiki)
		{
			$item = ['type' => 'wiki', 'ndx' => $wikiNdx, 'title' => $wiki['fn']];

			$item['_options'] = [
				'expandable' => [
					'column' => 'title',
					'level' => $this->level,
					'exp-this-id' => 'w'.$wikiNdx,
					'query-params' => ['wiki' => $wikiNdx]
				]
			];

			$this->data[] = $item;
		}
	}

	function loadData_Sections()
	{
		$expandedId = $this->requestParams['expanded-id'];

		$wikiNdx = (isset($this->queryParams['wiki'])) ? intval($this->queryParams['wiki']) : 0;
		if (!$wikiNdx)
			return;

		$q [] = 'SELECT sections.ndx, sections.title FROM [e10pro_kb_sections] AS [sections]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND ndx IN %in', $this->sections);
		array_push($q, ' AND wiki = %i', $wikiNdx);
		array_push($q, ' AND docStateMain < %i', 4);
		array_push($q, ' ORDER BY [order], [title]');


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['type' => 'section', 'ndx' => $r['ndx'], 'title' => $r['title']];

			$item['_options'] = [
				'selectable' => 's'.$r['ndx'],
				'expandable' => [
					'column' => 'title',
					'level' => $this->level,
					'exp-this-id' => $expandedId.'.'.'s'.$r['ndx'],
					'exp-parent-id' => $expandedId,
					'query-params' => ['owner-section' => $r['ndx']]
				]
			];

			$this->data[] = $item;
		}
	}

	function loadData_Pages()
	{
		$expandedId = $this->requestParams['expanded-id'];

		$sectionNdx = (isset($this->queryParams['owner-section'])) ? intval($this->queryParams['owner-section']) : 0;
		$ownerTextNdx = (isset($this->queryParams['owner-text'])) ? intval($this->queryParams['owner-text']) : 0;
		if (!$sectionNdx && !$ownerTextNdx)
			return;

		$q [] = 'SELECT texts.*';
		array_push($q, ' FROM [e10pro_kb_texts] AS texts');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND texts.docStateMain < %i', 4);

		if ($sectionNdx)
			array_push($q, ' AND texts.section = %i', $sectionNdx, ' AND texts.ownerText = %i', 0);
		else
			array_push($q, ' AND texts.ownerText = %i', $ownerTextNdx);

		array_push($q, ' ORDER BY [texts].[order], [texts].[title]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['type' => 'page', 'ndx' => $r['ndx'], 'title' => $r['title']];

			if ($sectionNdx)
			{
				$item['_options'] = [
					'selectable' => 'p'.$r['ndx'],
					'expandable' => [
						'column' => 'title',
						'level' => $this->level,
						'exp-this-id' => $expandedId.'.'.'p' . $r['ndx'],
						'exp-parent-id' => $expandedId,
						'query-params' => ['owner-text' => $r['ndx']]
					]
				];
			}
			else
			{
				$item['_options'] = [
					'selectable' => 'p'.$r['ndx'],
					'expandable' => [
						'column' => 'title',
						'level' => $this->level,
						'exp-this-id' => $expandedId.'.'.'p' . $r['ndx'],
						'exp-parent-id' => $expandedId,
						'query-params' => ['owner-text' => $r['ndx']]
					]
				];
			}
			$this->data[] = $item;
		}
	}

	function loadWikies()
	{
		$this->wikies = $this->tableWikies->usersWikies();
		$this->sections = $this->tableWikies->userSections();
	}
}
