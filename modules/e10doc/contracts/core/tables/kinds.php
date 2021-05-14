<?php

namespace e10doc\contracts\core;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10\TableView, \Shipard\Form\TableForm, \e10\DbTable, e10doc\core\e10utils, e10\utils, e10doc\core\CreateDocumentUtility;


/**
 * Class TableKinds
 * @package e10doc\contracts\core
 */
class TableKinds extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10doc.contracts.core.kinds', 'e10doc_contracts_kinds', 'Druhy smluv');
	}

	public function createHeader($recData, $options)
	{
		$hdr = parent::createHeader($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['ndx']) && $recData['ndx'])
		{
			$pgCount = $this->db()->query('SELECT COUNT(*) AS cnt FROM  [e10_base_doclinks]',
				' WHERE srcTableId = %s', 'e10doc.contracts.core.kinds',
				' AND srcRecId = %i', $recData['ndx'],
				' AND dstTableId = %s', 'e10.persons.groups',
				' AND linkId = %s', 'e10doc-contractKinds-pg')->fetch();

			if ($pgCount)
			{
				$useBatchCreating = $pgCount['cnt'] != 0;
				if ($useBatchCreating != $recData['useBatchCreating'])
				{
					$recData['useBatchCreating'] = $useBatchCreating;
					$this->db()->query ('UPDATE [e10doc_contracts_kinds] SET [useBatchCreating] = %i', $useBatchCreating,
						' WHERE [ndx] = %i', $recData['ndx']);
				}
			}
		}

		parent::checkAfterSave2 ($recData);
	}

	protected function checkChangedInput ($changedInput, &$saveData)
	{
		$colNameParts = explode ('.', $changedInput);

		// -- row item reset
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'item')
		{
			if (!isset ($saveData['softChangeInput']))
			{
				$item = $this->loadItem ($saveData['lists']['rows'][$colNameParts[2]]['item'], 'e10_witems_items');
				$this->resetRowItem ($saveData['recData'], $saveData['lists']['rows'][$colNameParts[2]], $item);
			}
			return;
		}
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'dstDocKind')
		{
			if ($cfgItem['ndx'] === 0)
				return TRUE;

			if ($form->recData['dstDocType'] !== $cfgItem['docType'])
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}


	public function resetRowItem ($headRecData, &$rowRecData, $itemRecData)
	{
		$rowRecData ['text'] = $itemRecData['fullName'];
		$rowRecData ['unit'] = $itemRecData['defaultUnit'];
		$rowRecData ['priceItem'] = e10utils::itemPriceSell($this->app(), $headRecData['taxCalc']+1, $itemRecData);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$docKinds = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_contracts_kinds] WHERE [docState] != 9800 ORDER BY [order], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$dk = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'tn' => $r ['tabName'],
				'icon' => ($r ['icon'] !== '') ? $r ['icon'] : 'icon-thumbs-up',

				'period' => $r['period'], 'periodOC' => $r['periodOC'],
				'invoicingDay' => $r['invoicingDay'], 'invoicingDayOC' => $r['invoicingDayOC'],
				'createOffsetValue' => $r['createOffsetValue'], 'createOffsetUnit' => $r['createOffsetUnit'],
				'dstDocType' => $r['dstDocType'], 'dstDocTypeOC' => $r['dstDocTypeOC'],
				'dstDocKind' => $r['dstDocKind'], 'dstDocKindOC' => $r['dstDocKindOC'],
				'taxCalc' => $r['taxCalc'], 'taxCalcOC' => $r['taxCalcOC'],
				'title' => $r['title'], 'titleOC' => $r['titleOC'],
				'currency' => $r['currency'], 'currencyOC' => $r['currencyOC'],
				'paymentMethod' => $r['paymentMethod'], 'paymentMethodOC' => $r['paymentMethodOC'],
				'myBankAccount' => $r['myBankAccount'], 'myBankAccountOC' => $r['myBankAccountOC'],
				'dueDays' => $r['dueDays'], 'dueDaysOC' => $r['dueDaysOC'],
				'dstDocState' => $r['dstDocState'], 'dstDocAutoSend' => $r['dstDocAutoSend'],
				'centre' => $r['centre'], 'centreOC' => $r['centreOC'],
				'wkfProject' => $r['wkfProject'], 'wkfProjectOC' => $r['wkfProjectOC'],
				'workOrder' => $r['workOrder'], 'workOrderOC' => $r['workOrderOC'],
			];

			$docKinds [strval($r['ndx'])] = $dk;
		}

		// -- save to file
		$cfg ['e10doc']['contracts']['kinds'] = $docKinds;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.contracts.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewKinds
 * @package e10doc\contracts\core
 */
class ViewKinds extends TableView
{
	public function init()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries();
	}

	public function renderRow($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon($item);

		$props = [];
		if ($item['tabName'] !== '')
			$props[] = ['text' => $item['tabName'], 'icon' => 'icon-folder-o', 'class' => 'label label-default'];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'icon-sort', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows()
	{
		$fts = $this->fullTextSearch();

		$q [] = 'SELECT * FROM [e10doc_contracts_kinds]';
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q,
				' [fullName] LIKE %s', '%' . $fts . '%',
				' OR [shortName] LIKE %s', '%' . $fts . '%'
			);
			array_push($q, ')');
		}

		$this->queryMain($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery($q);
	}
}


/**
 * Class FormKind
 * @package e10doc\contracts\core
 */
class FormKind extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Druh', 'icon' => 'icon-file-text-o'];
		$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'icon-list'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('tabName');

					$this->addSeparator(self::coH2);
					$this->openRow ();
						$this->addColumnInput ('period');
						$this->addColumnInput ('periodOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('paymentMethod');
						$this->addColumnInput ('paymentMethodOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('myBankAccount');
						$this->addColumnInput ('myBankAccountOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('dueDays');
						$this->addColumnInput ('dueDaysOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('invoicingDay');
						$this->addColumnInput ('invoicingDayOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('dstDocType');
						$this->addColumnInput ('dstDocTypeOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('dstDocKind');
						$this->addColumnInput ('dstDocKindOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('taxCalc');
						$this->addColumnInput ('taxCalcOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('currency');
						$this->addColumnInput ('currencyOC', self::coRight);
					$this->closeRow();

					$this->openRow ();
						$this->addColumnInput ('title');
						$this->addColumnInput ('titleOC', self::coRight);
					$this->closeRow();

					$this->addSeparator(self::coH2);

					if ($this->app()->cfgItem ('options.core.useCentres', 0))
					{
						$this->openRow();
							$this->addColumnInput('centre');
							$this->addColumnInput('centreOC',self::coRight);
						$this->closeRow();
					}

					if ($this->app()->cfgItem ('options.core.useProjects', 0))
					{
						$this->openRow();
							$this->addColumnInput('wkfProject');
							$this->addColumnInput('wkfProjectOC',self::coRight);
						$this->closeRow();
					}

					if ($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
					{
						$this->openRow();
							$this->addColumnInput('workOrder');
							$this->addColumnInput('workOrderOC', self::coRight);
						$this->closeRow();
					}

					$this->addSeparator(self::coH2);
					$this->openRow();
						$this->addColumnInput('createOffsetValue');
						$this->addColumnInput('createOffsetUnit');
					$this->closeRow();

					$this->addColumnInput('dstDocState');
					if ($this->recData['dstDocState'] === CreateDocumentUtility::sdsDone)
						$this->addColumnInput('dstDocAutoSend');

					$this->addSeparator(self::coH2);
					$this->addList('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab ();
					$this->addList('rows');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

