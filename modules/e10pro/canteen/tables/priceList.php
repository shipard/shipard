<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TablePriceList
 * @package e10pro\canteen
 */
class TablePriceList extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.priceList', 'e10pro_canteen_priceList', 'Ceník jídel');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'fullName', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function columnInfoEnumSrc ($columnId, $form)
	{
		if ($columnId === 'foodKind')
		{
			$enum = [];
			$enum[0] = 'Hlavní jídlo';

			if ($form && isset($form->recData['canteen']) && $form->recData['canteen'])
			{
				$canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$form->recData['canteen']);
				if (!$canteenCfg || !isset($canteenCfg['addFoods']))
					return $enum;
				foreach ($canteenCfg['addFoods'] as $afNdx => $af)
					$enum[$afNdx] = $af['fn'];
			}

			return $enum;
		}

		return parent::columnInfoEnumSrc ($columnId, $form);
	}
}


/**
 * Class ViewPriceList
 * @package e10pro\canteen
 */
class ViewPriceList extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = ['text' => $item['canteenName'], 'icon' => 'icon-cutlery'];

		if ($item['foodKind'] === 0)
			$listItem ['t1']['suffix'] = 'Hlavní jídlo';
		elseif ($item['foodKindName'])
			$listItem ['t1']['suffix'] = $item['foodKindName'];

		$props = [];
		$props[] = ['text' => utils::nf($item['priceEmp'], 2), 'icon' => 'icon-money', 'suffix' => 'Zaměstnanci', 'class' => ''];
		$props[] = ['text' => utils::nf($item['priceExt'], 2), 'icon' => 'icon-money', 'suffix' => 'Externisté', 'class' => ''];

		$txtDate = '';
		if ($item['validFrom'])
			$txtDate = utils::datef($item['validFrom']);
		$txtDate .= ' ➞ ';
		if ($item['validTo'])
			$txtDate .= utils::datef($item['validTo']);

		$listItem['i2'] = ['text' => $txtDate, 'icon' => 'icon-calendar', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT priceList.*, canteens.shortName AS canteenName, addFoods.fullName AS foodKindName';
		array_push ($q, ' FROM [e10pro_canteen_priceList] AS priceList');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON priceList.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN [e10pro_canteen_canteensAddFoods] AS addFoods ON priceList.foodKind = addFoods.ndx');
		array_push ($q, ' WHERE 1');

		$this->queryMain ($q, 'priceList.', ['[validFrom]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPriceList
 * @package e10pro\canteen
 */
class FormPriceList extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('canteen');
			$this->addColumnInput ('foodKind');
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');
			$this->addColumnInput('priceEmp');
			$this->addColumnInput('priceExt');
		$this->closeForm ();
	}
}

