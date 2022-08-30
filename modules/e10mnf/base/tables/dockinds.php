<?php

namespace e10mnf\base;
use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDocKinds
 * @package e10mnf\base
 */
class TableDocKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.dockinds', 'e10mnf_base_dockinds', 'Druhy zakázek');
	}

	public function saveConfig ()
	{
		$docKinds = [];

		$rows = $this->app()->db->query ('SELECT * from [e10mnf_base_dockinds] WHERE [docState] != 9800 ORDER BY [order], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$dk = [
				'ndx' => $r ['ndx'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'workOrderType' => $r ['workOrderType'], 'workOrderFrequency' => $r ['workOrderFrequency'],
				'useInvoicingPeriodicity' => $r['useInvoicingPeriodicity'],
				'disableRows' => $r ['disableRows'], 'priceOnHead' => $r ['priceOnHead'],
				'vds' => $r ['vds'],
				'useDescription' => $r ['useDescription'],
				'usePersonsList' => $r ['usePersonsList'],
				'useDateIssue' => $r ['useDateIssue'], 'labelDateIssue' => $r ['labelDateIssue'],
				'useDateContract' => $r ['useDateContract'], 'labelDateContract' => $r ['labelDateContract'],
				'useDateBegin' => $r ['useDateBegin'], 'labelDateBegin' => $r ['labelDateBegin'],
				'useDateDeadlineRequested' => $r ['useDateDeadlineRequested'], 'labelDateDeadlineRequested' => $r ['labelDateDeadlineRequested'],
				'useDateDeadlineConfirmed' => $r ['useDateDeadlineConfirmed'], 'labelDateDeadlineConfirmed' => $r ['labelDateDeadlineConfirmed'],
				'useRefId1' => $r ['useRefId1'], 'labelRefId1' => $r ['labelRefId1'],
				'useRefId2' => $r ['useRefId2'], 'labelRefId2' => $r ['labelRefId2'],
				'useIntTitle' => $r ['useIntTitle'],
				'useRetentionGuarantees' => $r ['useRetentionGuarantees'],
				'useAddress' => $r ['useAddress'], 'invoicesInDetail' => $r ['invoicesInDetail'],
				'viewerPrimaryTitle' => $r ['viewerPrimaryTitle'],
				'useMembers' => $r ['useMembers'],
				'useHeadSymbol1' => $r ['useHeadSymbol1'],
				'useOwnerWorkOrder' => $r ['useOwnerWorkOrder'],
				'useRowValidFromTo' => $r ['useRowValidFromTo'],
				'useRowDateDeadlineRequested' => $r ['useRowDateDeadlineRequested'], 'labelRowDateDeadlineRequested' => $r ['labelRowDateDeadlineRequested'],
				'useRowDateDeadlineConfirmed' => $r ['useRowDateDeadlineConfirmed'], 'labelRowDateDeadlineConfirmed' => $r ['labelRowDateDeadlineConfirmed'],
				'useRowRefId1' => $r ['useRowRefId1'], 'labelRowRefId1' => $r ['labelRowRefId1'],
				'useRowRefId2' => $r ['useRowRefId2'], 'labelRowRefId2' => $r ['labelRowRefId2'],
				'useRowRefId3' => $r ['useRowRefId3'], 'labelRowRefId3' => $r ['labelRowRefId3'],
				'useRowRefId4' => $r ['useRowRefId4'], 'labelRowRefId4' => $r ['labelRowRefId4'],
			];

			if ($dk['labelDateIssue'] === '')
				$dk['labelDateIssue'] = 'Datum vystavení';
			if ($dk['labelDateContract'] === '')
				$dk['labelDateContract'] = 'Datum podpisu smlouvy';
			if ($dk['labelDateBegin'] === '')
				$dk['labelDateBegin'] = 'Datum zahájení';
			if ($dk['labelDateDeadlineRequested'] === '')
				$dk['labelDateDeadlineRequested'] = 'Požadovaný termín';
			if ($dk['labelDateDeadlineConfirmed'] === '')
				$dk['labelDateDeadlineConfirmed'] = 'Potvrzený termín';
			if ($dk['labelRefId1'] === '')
				$dk['labelRefId1'] = 'Interní číslo zakázky';
			if ($dk['labelRefId2'] === '')
				$dk['labelRefId2'] = 'Objednávka / HS';

			if ($dk['labelRowRefId1'] === '')
				$dk['labelRowRefId1'] = 'Objednávka / HS';
			if ($dk['labelRowRefId2'] === '')
				$dk['labelRowRefId2'] = 'Kód 2';
			if ($dk['labelRowRefId3'] === '')
				$dk['labelRowRefId3'] = 'Kód 3';
			if ($dk['labelRowRefId4'] === '')
				$dk['labelRowRefId4'] = 'Kód 4';

			if ($r['useDetailMainSettings'])
			{
				$dk['mainDetail'] = [
					'parts' => [$r['detailMainPart1'], $r['detailMainPart2'], $r['detailMainPart3'], $r['detailMainPart4']]
				];
			}

			if ($dk['workOrderFrequency'] != 2)
				$dk['useInvoicingPeriodicity'] = 0;

			$docKinds [strval($r['ndx'])] = $dk;
		}

		$docKinds ['0'] = [
			'ndx' => 0, 'fullName' => '', 'shortName' => '',
			'workOrderType' => 0, 'workOrderFrequency' => 0, 'useInvoicingPeriodicity' => 0,
			'disableRows' => 0, 'priceOnHead' => 0,
			'viewerPrimaryTitle' => 0,
			'useDescription' => 0,
			'usePersonsList' => 0,
			'useDateIssue' => 1, 'labelDateIssue' => 'Datum vystavení',
			'useDateContract' => 0, 'labelDateContract' => '',
			'useDateBegin' => 0, 'labelDateBegin' => '',
			'useDateDeadlineRequested' => 0, 'labelDateDeadlineRequested' => '',
			'useDateDeadlineConfirmed' => 1, 'labelDateDeadlineConfirmed' => 'Potvrzený termín',
			'useRefId1' => 0, 'labelRefId1' => '',
			'useRefId2' => 1, 'labelRefId2' => 'Objednávka / HS',
			'useIntTitle' => 0,
			'useRetentionGuarantees' => 0,

			'useRowValidFromTo' => 0,
			'useRowDateDeadlineRequested' => 0,
			'useRowDateDeadlineConfirmed' => 0,
		];

		// save to file
		$cfg ['e10mnf']['workOrders']['kinds'] = $docKinds;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.docKinds.json', utils::json_lint (json_encode ($cfg)));

		// -- properties
		unset ($cfg);
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10mnf']['workOrders']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.workOrders.properties.json', utils::json_lint(json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewDocKinds
 * @package e10mnf\base
 */
class ViewDocKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10mnf_base_dockinds]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%',
					' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];
		$props[] = ['text' => '#'.$item['ndx'], 'class' => 'pull-right e10-small e10-id'];
		$props[] = ['text' => $item['shortName'], 'class' => ''];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'pull-right label label-default'];

		$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * Class FormDocKind
 * @package e10mnf\base
 */
class FormDocKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Detail', 'icon' => 'formDetail'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('workOrderType');
					$this->addColumnInput ('workOrderFrequency');
					if ($this->recData['workOrderFrequency'] == 2)
						$this->addColumnInput ('useInvoicingPeriodicity');
					$this->addColumnInput ('priceOnHead');
					$this->addColumnInput ('useDescription');
					$this->addColumnInput ('useAddress');
					$this->addColumnInput ('usePersonsList');
					$this->addColumnInput ('invoicesInDetail');
					$this->addColumnInput ('viewerPrimaryTitle');
					$this->addColumnInput ('useMembers');
					$this->addColumnInput ('useHeadSymbol1');
					$this->addColumnInput ('useOwnerWorkOrder');
					$this->addColumnInput ('order');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab ();
					$this->addStatic(['text' => 'Hlavička', 'class' => 'h2 pl1']);

					$this->layoutOpen (TableForm::ltGrid);
						$this->openRow ();
							$this->addColumnInput ('useDateIssue', TableForm::coColW5);
							$this->addColumnInput ('labelDateIssue', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useDateContract', TableForm::coColW5);
							$this->addColumnInput ('labelDateContract', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useDateBegin', TableForm::coColW5);
							$this->addColumnInput ('labelDateBegin', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useDateDeadlineRequested', TableForm::coColW5);
							$this->addColumnInput ('labelDateDeadlineRequested', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useDateDeadlineConfirmed', TableForm::coColW5);
							$this->addColumnInput ('labelDateDeadlineConfirmed', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRefId1', TableForm::coColW5);
							$this->addColumnInput ('labelRefId1', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRefId2', TableForm::coColW5);
							$this->addColumnInput ('labelRefId2', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useIntTitle', TableForm::coColW5);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRetentionGuarantees', TableForm::coColW5);
						$this->closeRow ();
					$this->layoutClose ();

					$this->addSeparator(self::coH2);
					$this->addStatic(['text' => 'Řádky zakázky', 'class' => 'h2 pl1']);
					$this->layoutOpen (TableForm::ltGrid);
						$this->openRow ();
							$this->addColumnInput ('disableRows', TableForm::coColW12);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowValidFromTo', TableForm::coColW12);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowDateDeadlineRequested', TableForm::coColW5);
							$this->addColumnInput ('labelRowDateDeadlineRequested', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowDateDeadlineConfirmed', TableForm::coColW5);
							$this->addColumnInput ('labelRowDateDeadlineConfirmed', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowRefId1', TableForm::coColW5);
							$this->addColumnInput ('labelRowRefId1', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowRefId2', TableForm::coColW5);
							$this->addColumnInput ('labelRowRefId2', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowRefId3', TableForm::coColW5);
							$this->addColumnInput ('labelRowRefId3', TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('useRowRefId4', TableForm::coColW5);
							$this->addColumnInput ('labelRowRefId4', TableForm::coColW7);
						$this->closeRow ();
					$this->layoutClose ();

					$this->addSeparator(self::coH2);
					$this->addColumnInput ('vds');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('useDetailMainSettings');
					if ($this->recData['useDetailMainSettings'])
					{
						$this->addColumnInput('detailMainPart1');
						$this->addColumnInput('detailMainPart2');
						$this->addColumnInput('detailMainPart3');
						$this->addColumnInput('detailMainPart4');
					}
				$this->closeTab ();
		$this->closeTabs();
		$this->closeForm ();
	}
}
