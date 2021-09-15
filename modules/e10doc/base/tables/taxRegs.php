<?php

namespace e10doc\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;
use \Shipard\Utils\World;

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
		$recData['country'] = World::countryId($this->app(), $recData['worldCountry']);

		if (!isset ($recData['docState']) || $recData['docState'] == 0)
		{
			$recData['docState'] = 4000;
			$recData['docState'] = 2;
		}
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
						'taxType' => 'vat', 'taxId' => $vatId, 
						'country' => 'cz', 
						'docState' => 4000, 'docStateMain' => 2, 'title' => 'DPH - ' . $vatId,
						'periodType' => $vatPeriod, 'periodTypeVatCS' => 1
				];
				$newReg['worldCountry'] = World::countryNdx($this->app(), $newReg['country']);

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
				'ndx' => $r ['ndx'], 'taxType' => $r['taxType'], 'payerKind' => $r['payerKind'],
				'taxId' => $r ['taxId'], 'title' => $r ['title'], 
				'country' => $r['country'], 'worldCountry' => $r['worldCountry'], 
				'periodType' => $r['periodType']
			];
			if ($r['taxType'] === 'vat' && $r['country'] === 'cz')
				$tr['periodTypeVatCS'] = $r['periodTypeVatCS'];

			if ($r['taxType'] === 'vat' && $r['payerKind'] === 1)
				$taxFlags['useOSS'] = 1;

			$taxFlags['moreRegs']++;

			$list [$r['taxType']][$r['ndx']] = $tr;
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
	var $countries;

	public function init ()
	{
		$this->taxRegsTypes = $this->app()->cfgItem('e10doc.base.taxRegsTypes');
		$this->countries = $this->app()->cfgItem('e10.base.countries');

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
		$props[] = ['text' => $this->countries[$item['country']]['name'], 'class' => 'label label-default', 'icon' => 'system/iconGlobe'];

		$periodType = $this->table->columnInfoEnum ('periodType', 'cfgText');
		$props[] = ['text' => $periodType[$item['periodType']], 'class' => 'label label-default', 'icon' => 'system/iconCalendar'];

		if ($item['taxType'] === 'vat' && $item['country'] === 'cz')
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
			$this->addColumnInput ('taxType');
			if ($this->recData['taxType'] === 'vat')
				$this->addColumnInput ('payerKind');
			$this->addColumnInput ('worldCountry');
			$this->addColumnInput ('taxId');
			$this->addColumnInput ('periodType');
			if ($this->recData['taxType'] === 'vat' && $this->recData['country'] === 'cz')
				$this->addColumnInput ('periodTypeVatCS');
			$this->addColumnInput ('title');
		$this->closeForm ();
	}
}

