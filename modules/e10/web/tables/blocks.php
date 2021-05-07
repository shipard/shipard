<?php

namespace e10\web;

//require_once __APP_DIR__ . '/e10-modules/e10/web/web.php';

use \e10\utils, \e10\json, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\TableViewDetail;


/**
 * Class TableBlocks
 * @package e10\web
 */
class TableBlocks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.blocks', 'e10_web_blocks', 'Bloky webu');
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = strval ($recData['ndx']);
			$this->app()->db()->query ("UPDATE [e10_web_blocks] SET [id] = %s WHERE [ndx] = %i", $recData['id'], $recData['ndx']);
		}

		parent::checkAfterSave2 ($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function saveConfig ()
	{
		// -- properties
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10web']['blocks']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10web.blocks.properties.json', utils::json_lint(json_encode ($cfg)));
	}

	public function blocksItems ()
	{
		$texy = new \E10\Web\E10Texy ($this->app());

		$bi = [];

		$q [] = 'SELECT items.*, [blocks].id AS blockId, [blocks].title AS blockTitle, ';
		array_push($q, 'pictures.path AS picturePath, pictures.fileName AS pictureFileName');
		array_push ($q, ' FROM [e10_web_blocksItems] AS [items]');
		array_push ($q, ' LEFT JOIN [e10_web_blocks] AS [blocks] ON [items].block = [blocks].ndx');
		array_push ($q, ' LEFT JOIN e10_attachments_files AS pictures ON items.picture = pictures.ndx');
		array_push ($q, ' WHERE items.docStateMain <= %i', 2, ' AND blocks.docStateMain <= %i', 2);
		array_push ($q, ' ORDER BY [blocks].id, [items].[order], [items].[title], [items].ndx');

		$itemsPks = [];
		$itemsMap = [];
		$rows = $this->db()->query($q);
		$index = 0;
		foreach ($rows as $r)
		{
			$blockId = $r['blockId'];
			$itemNdx = $r['ndx'];
			$itemId = $r['id'];
			if (!isset($bi[$blockId]))
			{
				$bi[$blockId] = ['title' => $r['blockTitle'], 'items' => []];
			}

			$text = $texy->process(($r['text']) ? $r['text'] : '');
			$item = ['title' => $r['title'], 'text' => $text, 'properties' => []];

			if (count($bi[$blockId]['items']) === 0)
			{
				$item['first'] = 1;
				$index = 0;
			}
			$item['index'] = $index;

			if ($r['picturePath'])
			{
				$item['picture'] = 1;
				$item['pictureUrl'] = $r['picturePath'].$r['pictureFileName'];
			}
			$bi[$blockId]['items'][$itemId] = $item;

			$itemsPks[] = $itemNdx;
			$itemsMap[$itemNdx] = ['itemId' => $itemId, 'blockId' => $blockId];

			$index++;
		}

		// -- properties
		$allProperties = $this->app()->cfgItem ('e10.base.properties', []);
		$q = [];
		array_push ($q, 'SELECT * FROM [e10_base_properties]');
		array_push ($q, ' WHERE [tableid] = %s', 'e10.web.blocksItems', ' AND [recid] IN %in', $itemsPks);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$p = $allProperties [$r['property']];

			$itemNdx = $r['recid'];
			$map = $itemsMap[$itemNdx];

			$blockId = $map['blockId'];
			$itemId = $map['itemId'];

			$pv = ($r['valueMemo'] && $r['valueMemo'] !== '') ? $texy->process($r['valueMemo']) : $r['valueString'];

			$bi[$blockId]['items'][$itemId]['properties'][$r['property']] = ['title' => $p['name'], 'value' => $pv];
		}

		return $bi;
	}
}


/**
 * Class ViewBlocks
 * @package e10\web
 */
class ViewBlocks extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		/*
		$props = [];

		if ($item ['projectGroupName'])
			$props [] = ['icon' => 'icon-sticky-note-o', 'text' => $item ['projectGroupName'], 'class' => 'label label-default'];

		if ($item ['projectName'])
			$props [] = ['icon' => 'icon-lightbulb-o', 'text' => $item ['projectName'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;
*/
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10_web_blocks] AS [blocks]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' blocks.[title] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[blocks].', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailBlockItems
 * @package e10\web
 */
class ViewDetailBlockItems extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent ([
				'type' => 'viewer', 'table' => 'e10.web.blocksItems', 'viewer' => 'default',
				'params' => ['block' => $this->item ['ndx']]
		]);
	}
}


/**
 * Class ViewDetailBlockCode
 * @package e10\web
 */
class ViewDetailBlockCode extends TableViewDetail
{
	public function createDetailContent ()
	{
		$bi = $this->table()->blocksItems();
		$code = json::lint($bi[$this->item['id']]);

		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $code]);
	}
}


/**
 * Class ViewDetailBlockTemplate
 * @package e10\web
 */
class ViewDetailBlockTemplate extends TableViewDetail
{
	public function createDetailContent ()
	{
		$bi = $this->table()->blocksItems();
		$item = $bi[$this->item['id']];

		$t = '';

		if ($this->item['blockType'] === 0)
		{
			foreach ($item['items'] as $itemId => $itemContent)
			{
				$t .= '{{{webBlocks.'.$this->item['id'].'.items.'.$itemId.'.text}}}'."\n";
			}
		}
		else
		{
			$t .= '{{#!webBlocks.' . $this->item['id'] . '.items}}' . "\n";
			foreach ($item['items'] as $itemId => $itemContent)
			{
				$t .= "\t" . '<h3>{{title}}</h3>' . "\n";

				foreach ($itemContent['properties'] as $key => $property)
				{
					$t .= "\t\t" . '<b>{{properties.' . $key . '.title}}</b>: {{properties.' . $key . '.value}}' . "\n";
				}
				break;
			}
			$t .= '{{/!webBlocks.' . $this->item['id'] . '.items}}' . "\n";
		}

		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $t]);
	}
}



/**
 * Class FormBlock
 * @package e10\web
 */
class FormBlock extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('title');
			$this->addColumnInput ('blockType');
			$this->addColumnInput ('id');
			if ($this->recData['blockType'] === 1)
			{
				$this->addList('doclinks', '', TableForm::loAddToFormLayout);
				$this->addColumnInput ('askForPicture');
			}
		$this->closeForm ();
	}
}
