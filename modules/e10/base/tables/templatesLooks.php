<?php

namespace e10\base;

include_once __DIR__ . '/../base.php';

use e10\DataModel, e10\utils, e10\TableView, e10\TableViewDetail, e10\TableForm, e10\DbTable;


/**
 * Class TableTemplatesLooks
 * @package e10\base
 */
class TableTemplatesLooks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.templatesLooks', 'e10_base_templatesLooks', 'Vzhledy šablon');
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

    $wtl = new \lib\web\WebTemplateLook($this->app());
		$wtl->check($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$tableTemplates = $this->app()->table('e10.base.templates');
		$templateId = $tableTemplates->templateId($recData['templateType'], $recData['template']);
		$recData['templateId'] = $templateId;

		if (!isset($recData['lookId']) || $recData['lookId'] == '')
		{
			$recData['lookId'] = base_convert(strval(time() + mt_rand (1000000, 40000000000)), 10, 36);
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function columnRefInputTitle ($form, $srcColumnId, $inputPrefix)
	{

		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;

		if (!$pk)
			return '';

		if ($pk < 100000)
			return parent::columnRefInputTitle($form, $srcColumnId, $inputPrefix);

		$allStdLooks = utils::loadCfgFile(__SHPD_ROOT_DIR__.__SHPD_TEMPLATE_SUBDIR__.'web/looks.json');
		$pk = strval($pk);
		if ($allStdLooks && isset($allStdLooks[$pk]))
			$refTitle = ['text' => $allStdLooks[$pk]['name']];
		else
			$refTitle = ['text' => 'Neznámý vzhled'];
		return $refTitle;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		$template = new \e10\TemplateCore ($this->app());
		$template->loadTemplate($recData['templateId'], 'page-web.mustache');

		if (!$template || $template->options === FALSE || !isset($template->options[$columnId]))
			return FALSE;

		return $template->options[$columnId];
	}

	public function templateLooks ($templateType, $templateNdx)
	{
		$looks = [];

		// -- user defined looks
		$q [] = 'SELECT * FROM [e10_base_templatesLooks]';
		array_push ($q, ' WHERE [template] = %i', $templateNdx, ' AND [docStateMain] < 4', ' ORDER BY [name], [ndx] ');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$looks[$r['ndx']] = ['ndx' => intval($r['ndx']), 'name' => $r['name'], 'id' => $r['lookId']];
		}


		// -- standard template looks
		$tableTemplates = $this->app()->table('e10.base.templates');
		$templateId = $tableTemplates->templateId($templateType, $templateNdx);
		$template = new \e10\TemplateCore ($this->app());
		$template->loadTemplate($templateId, FALSE);

		$stdLooks = utils::loadCfgFile($template->templateRoot.'/looks.json');
		if ($stdLooks)
		{
			foreach ($stdLooks as $lookNdx => $look)
			{
				$looks[$lookNdx] = ['ndx' => intval($lookNdx), 'name' => $look['name'], 'id' => $look['id']];
			}
		}

		return $looks;
	}

	public function templateLookInfo ($lookNdx)
	{
		if ($lookNdx < 100000)
		{
			$item = $this->loadItem($lookNdx);

			return ['name' => $item['name'], 'id' => $item['lookId']];
		}

		$allStdLooks = utils::loadCfgFile(__SHPD_ROOT_DIR__.__SHPD_TEMPLATE_SUBDIR__.'web/looks.json');
		$pk = strval($lookNdx);
		if ($allStdLooks && isset($allStdLooks[$pk]))
			return $allStdLooks[$pk];
	}
}


/**
 * Class ViewTemplatesLooks
 * @package e10\base
 */
class ViewTemplatesLooks extends TableView
{
	/** @var  \e10\base\TableTemplates */
	var $tableTemplates;

	public function init ()
	{
		$this->tableTemplates = $this->app()->table('e10.base.templates');

		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['lookId'], 'class' => 'id'];
		$listItem ['t2'] = $this->tableTemplates->templateName($item['templateType'], $item['template']);
		$listItem ['i2'] = '#'.$item ['templateId'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT * from [e10_base_templatesLooks] WHERE 1";

		if ($fts != '')
			array_push ($q, " AND ([name] LIKE %s OR [templateId] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewTemplatesLooksWeb
 * @package e10\base
 */
class ViewTemplatesLooksWeb extends ViewTemplatesLooks
{
	public function defaultQuery (&$q)
	{
		array_push ($q, ' AND [templateType] = %i', 0);
	}
}


/**
 * Class ViewTemplatesLooksWebCombo
 * @package e10\base
 */
class ViewTemplatesLooksWebCombo extends ViewTemplatesLooksWeb
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i2'] = '#'.$item ['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$templateNdx = intval($this->queryParam('templateNdx'));

		$this->rowsPageSize = 500;
		//$fts = '';

		$stdLooks = $this->table->templateLooks(0, $templateNdx);
		foreach ($stdLooks as $lookNdx => $look)
		{
			$this->queryRows [] = $look;
		}
	}
}


/**
 * Class ViewDetailTemplateLook
 * @package e10\base
 */
class ViewDetailTemplateLook extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailTemplateLookData
 * @package e10\base
 */
class ViewDetailTemplateLookData extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10.base.libs.dc.TemplateLookData');
	}
}

/**
 * Class FormTemplateLook
 * @package e10\base
 */
class FormTemplateLook extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Rozšíření stylů', 'icon' => 'formStylesExtension'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addSubColumns ('lookParams');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('templateType');
					$this->addColumnInput ('template');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('lookStyleExt', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
