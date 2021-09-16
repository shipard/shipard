<?php

namespace e10doc\base;

use \E10\utils, \E10\TableView, \Shipard\Form\TableForm, \E10\DbTable;
use \Shipard\Utils\World;
use \e10doc\core\libs\E10Utils;

/**
 * Class TableTaxRegs
 */
class TableTaxRegs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.taxRegs', 'e10doc_base_taxRegs', 'Registrace k daním');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'taxCountry' && $form)	
			return E10Utils::taxCountries ($this->app(), $form->recData['taxArea']);

		return parent::columnInfoEnum ($columnId, $valueType = 'cfgText', $form);	
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function saveConfig ()
	{
		// -- default VAT registration
		$regs = $this->db()->query('SELECT * FROM [e10doc_base_taxRegs] WHERE [taxType] = %s', 'vat')->fetch();
		if (!$regs)
		{
			$vatPeriod = $this->app()->cfgItem ('options.core.vatPeriod', 0);
			$vatId = $this->app()->cfgItem ('options.core.ownerVATID', '');
			if ($vatPeriod != 0 && $vatId != '')
			{
				$newReg = [
						'taxArea' => 'eu', 
						'taxCountry' => 'cz', 
						'taxType' => 'vat', 
						'taxId' => $vatId, 
						'docState' => 4000, 'docStateMain' => 2, 'title' => 'DPH - ' . $vatId,
						'periodType' => $vatPeriod, 'periodTypeVatCS' => 1
				];

				$this->db()->query('INSERT INTO [e10doc_base_taxRegs] ', $newReg);
			}
		}

		// -- create cfg file
		$list = [];
		$taxFlags = ['moreRegs' => 0, 'useOSS' => 0];
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_taxRegs] WHERE [docState] != 9800 ORDER BY [ndx]');

		foreach ($rows as $r)
		{
			$tr = [
				'ndx' => $r ['ndx'], 
				'taxArea' => $r['taxArea'], 
				'taxCountry' => $r['taxCountry'],
				'taxType' => $r['taxType'], 'payerKind' => $r['payerKind'],
				'taxId' => $r ['taxId'], 'title' => $r ['title'], 
				'periodType' => $r['periodType']
			];
			if ($r['taxType'] === 'vat' && $r['taxCountry'] === 'cz')
				$tr['periodTypeVatCS'] = $r['periodTypeVatCS'];

			if ($r['taxArea'] === 'eu' && $r['taxType'] === 'vat' && $r['payerKind'] === 1)
				$taxFlags['useOSS'] = 1;

			$taxFlags['moreRegs']++;

			$list[$r['ndx']] = $tr;
		}

		// save to file - registrations
		$cfg ['e10doc']['base']['taxRegs'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.base.taxRegs.json', utils::json_lint (json_encode ($cfg)));
		// save to file - flags
		$cfg = [];
		$cfg ['e10doc']['base']['tax']['flags'] = $taxFlags;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.base.tax.flags.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewTaxRegs
 * @package E10Doc\Base
 */
class ViewTaxRegs extends TableView
{
	var $taxRegsTypes;
	var $taxAreas;

	public function init ()
	{
		$this->taxRegsTypes = $this->app()->cfgItem('e10doc.base.taxRegsTypes');
		$this->taxAreas = $this->app()->cfgItem('e10doc.base.taxAreas');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $this->taxRegsTypes[$item['taxType']]['name'];

		$payerKind = $this->table->columnInfoEnum ('payerKind', 'cfgText');
		$listItem ['t1'] .= ' / ' . $payerKind[$item['payerKind']];

		$listItem ['i1'] = $item['taxId'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		$taxArea = $this->taxAreas[$item['taxArea']] ?? NULL;
		if ($taxArea)
		{
			$props[] = ['text' => $taxArea['sn'], 'class' => 'label label-default', 'icon' => 'system/iconGlobe'];

			$taxCountry = $taxArea['countries'][$item['taxCountry']] ?? NULL;
			if ($taxCountry)
				$props[] = ['text' => $taxCountry['fn'], 'class' => 'label label-default'];
			else
				$props[] = ['text' => 'Neznámá země', 'class' => 'label label-danger'];
			}	
		else
			$props[] = ['text' => 'Neznámá daňová oblast', 'class' => 'label label-danger'];

		$periodType = $this->table->columnInfoEnum ('periodType', 'cfgText');
		$props[] = ['text' => $periodType[$item['periodType']], 'class' => 'label label-default', 'icon' => 'system/iconCalendar'];

		if ($item['taxType'] === 'vat' && $item['taxCountry'] === 'cz')
		{
			$periodType = $this->table->columnInfoEnum ('periodTypeVatCS', 'cfgText');
			$props[] = ['text' => 'KH: '.$periodType[$item['periodTypeVatCS']], 'class' => 'label label-default', 'icon' => 'system/iconCalendar'];
		}

		$listItem ['t2'] = $props;
		$listItem ['i2'] = $item['title'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_taxRegs]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [title] LIKE %s', '%'.$fts.'%',
					' OR [taxId] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[taxType], [ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTaxReg
 * @package E10Doc\Base
 */
class FormTaxReg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('taxArea');
			$this->addColumnInput ('taxType');
			$this->addColumnInput ('taxCountry');

			if ($this->recData['taxType'] === 'vat')
				$this->addColumnInput ('payerKind');

			$this->addColumnInput ('taxId');
			$this->addColumnInput ('periodType');
			if ($this->recData['taxType'] === 'vat' && $this->recData['taxCountry'] === 'cz')
				$this->addColumnInput ('periodTypeVatCS');
			$this->addColumnInput ('title');
		$this->closeForm ();
	}
}

