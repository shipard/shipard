<?php

namespace terminals\base;

use \E10\utils, \E10\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TablePrinters
 * @package terminals\base
 */
class TablePrinters extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.base.printers', 'terminals_base_printers', 'Tiskárny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$printers = [];
		$rows = $this->app()->db->query ('SELECT * from [terminals_base_printers] WHERE [docState] != 9800 ORDER BY [id], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$printers [$r['ndx']] = [
					'ndx' => $r['ndx'], 'id' => $r['id'],
					'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
					'printerType' => $r ['printerType'], 'receiptsPrinterType' => $r ['receiptsPrinterType'],
					'posPrinterDriver' => $r['posPrinterDriver'],
					'printMethod' => $r ['printMethod'], 'printerAddress' => $r ['printerAddress'],
					'printEmail' => $r ['printEmail'], 'printURL' => $r ['printURL'], 'networkQueueId' => $r ['networkQueueId'],
					'labelsType' => $r['labelsType'],
			];
		}

		// -- save to file
		$cfg ['e10']['terminals']['printers'] = $printers;
		file_put_contents(__APP_DIR__ . '/config/_terminals.printers.json', utils::json_lint (json_encode ($cfg)));
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'labelsType')
		{
			$pdCfg = $this->loadPrinterDriverCfg($form->recData['posPrinterDriver']);
			if (!$pdCfg || !isset($pdCfg['labels']))
				return ['' => '--- ostatní ---'];

			$enum = [];
			foreach ($pdCfg['labels'] as $ltId => $ltCfg)
			{
				$enum[$ltId] = $ltCfg['fn'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

	function loadPrinterDriverCfg($printerDriverId)
	{
		$printerDriverCfg = $this->app()->cfgItem('terminals.postPrinterDrivers.'.$printerDriverId, NULL);

		$pdfn = __SHPD_MODULES_DIR__ . $printerDriverCfg['driver'];
		$printerDriver = Utils::loadCfgFile($pdfn);
		if (!$printerDriver)
			return NULL;

		return $printerDriver;
	}
}


/**
 * Class ViewPrinters
 * @package terminals\base
 */
class ViewPrinters extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$printerTypes = $this->table->columnInfoEnum ('printerType', 'cfgText');
		$listItem ['t2'] = $printerTypes [$item ['printerType']];

		$printMethods = $this->table->columnInfoEnum ('printMethod', 'cfgText');
		$listItem ['i2'] = [['text' => $printMethods [$item ['printMethod']]]];

		$printMethod = $item['printMethod'];
		$printerType = $item['printerType'];
		if ($printMethod == 0 || $printMethod == 1)
		{
			$listItem ['i2'][] = ['text' => $item['printURL']];
			$listItem ['i2'][] = ['text' => $item['networkQueueId']];
		}
		elseif ($printMethod == 2)
		{
			$listItem ['i2'][] = ['text' => $item['printEmail']];
		}
		elseif ($printMethod == 5)
		{
			$listItem ['i2'][] = ['text' => $item['printerAddress']];
		}
		if ($printerType == 1)
		{
			$receiptPrinterTypes = $this->table->columnInfoEnum ('receiptsPrinterType', 'cfgText');
			$rpt = (isset($receiptPrinterTypes[$item['receiptsPrinterType']])) ? $item['receiptsPrinterType'] : 'normal';
			$listItem ['t2'] .= ': '.$receiptPrinterTypes[$rpt];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_base_printers]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [shortName] LIKE %s', '%'.$fts.'%',
					' OR [fullName] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPrinter
 * @package terminals\base
 */
class FormPrinter extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$printMethod = $this->recData['printMethod'];
		$printerType = $this->recData['printerType'];

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('printerType');
			if ($printerType == 1 || $printerType == 2)
			{
				$this->addColumnInput ('posPrinterDriver');
			}
			if ($printerType == 1)
			{
				$this->addColumnInput ('receiptsPrinterType');
			}
			if ($printerType == 2)
			{
				$this->addColumnInput ('labelsType');
			}

			$this->addColumnInput ('printMethod');

			if ($printMethod == 0 || $printMethod == 1)
			{
				$this->addColumnInput ('printURL');
				$this->addColumnInput ('networkQueueId');
			}
			elseif ($printMethod == 2)
			{
				$this->addColumnInput ('printEmail');
			}
			elseif ($printMethod == 5)
			{
				$this->addColumnInput ('printerAddress');
				$this->addStatic(['text' => 'Tiskárna musí mít povolen přístup z IP adresy '.$_SERVER['SERVER_ADDR'], 'class' => 'padd5 e10-em e10-off']);
			}
		$this->closeForm ();
	}
}


