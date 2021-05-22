<?php

namespace e10\web;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\TableViewDetail;


/**
 * Class TableBlocksItems
 * @package e10\web
 */
class TableBlocksItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.blocksItems', 'e10_web_blocksItems', 'Položky bloků webu');
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['id'] = strval ($recData['ndx']);
			$this->app()->db()->query ("UPDATE [e10_web_blocksItems] SET [id] = %s WHERE [ndx] = %i", $recData['id'], $recData['ndx']);
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

	//checkDocumentPropertiesList
}


/**
 * Class ViewBlocksItems
 * @package e10\web
 */
class ViewBlocksItems extends TableView
{
	public function init ()
	{
		parent::init();

		if ($this->queryParam ('block'))
			$this->addAddParam ('block', $this->queryParam ('block'));

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

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
		array_push ($q, ' FROM [e10_web_blocksItems] AS [items]');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [block] = %i', $this->queryParam ('block'));


		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' items.[title] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[items].', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormBlockItem
 * @package e10\web
 */
class FormBlockItem extends TableForm
{
	public function renderForm ()
	{
		$webBlock = $this->table->loadItem($this->recData['block'], 'e10_web_blocks');
		if ($webBlock['blockType'] === 0)
			$this->renderFormPagePart($webBlock);
		else
			$this->renderFormListItem($webBlock);
	}

	public function renderFormPagePart ($webBlock)
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];

		$this->openForm ();

			$this->addColumnInput ('title');
			$this->addColumnInput ('order');
			$this->addColumnInput ('id');
			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
				$this->closeTab ();

				$this->openTab (self::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function renderFormListItem ($webBlock)
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$properties = $this->addList ('properties', '', TableForm::loAddToFormLayout|TableForm::loWidgetParts);

			$tabs ['tabs'][] = ['text' => 'Položka', 'icon' => 'x-content'];
			forEach ($properties ['memoInputs'] as $mi)
				$tabs ['tabs'][] = ['text' => $mi ['text'], 'icon' => $mi ['icon']];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addColumnInput ('order');
					$this->addColumnInput ('id');
					$this->appendCode ($properties ['widgetCode']);
					if ($webBlock['askForPicture'])
						$this->addColumnInput ('picture');
				$this->closeTab ();

				forEach ($properties ['memoInputs'] as $mi)
				{
					$this->openTab ();
						$this->appendCode ($mi ['widgetCode']);
					$this->closeTab ();
				}

				$this->openTab (self::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

}
