<?php

namespace wkf\msgs;

use \E10\TableView, \E10\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \E10\utils;


/**
 * class TableMsgsVGR
 */
class TableMsgsVGR extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.msgs.msgsVGR', 'wkf_msgs_msgsVGR', 'Adresáti hromadné pošty');
	}

	public function virtualGroupEnumItems ($columnId, $recData)
	{
		$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$recData['virtualGroup'], NULL);
		if (!$virtualGroup)
			return [];

		$vgObject = $this->app()->createObject($virtualGroup['classId']);
		if (!$vgObject)
			return [];

		return $vgObject->enumItems($columnId, $recData);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'virtualGroupItem' || $columnId === 'virtualGroupItem2' || $columnId === 'virtualGroupItem3' || $columnId === 'virtualGroupItem4' || $columnId === 'virtualGroupItem5')
		{
			if (!$form)
				return [];

			return $this->virtualGroupEnumItems($columnId, $form->recData);
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}
}


/**
 * Class FormMsgVGR
 */
class FormMsgVGR extends TableForm
{
	public function renderForm ()
	{
		$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$this->recData['virtualGroup'], []);

		$this->openForm (self::ltNone);
			$this->layoutOpen(self::ltHorizontal);
				$this->layoutOpen(self::ltVertical);
					$this->addColumnInput ('virtualGroup');
				$this->layoutClose('pl1');
				$this->layoutOpen(self::ltVertical);
					$this->addColumnInput ('virtualGroupItem');
				$this->layoutClose('pl1');
				$this->layoutOpen(self::ltVertical);
					if (isset($virtualGroup['queryColumns']) && isset($virtualGroup['queryColumns']['virtualGroupItem2']))
						$this->addColumnInput ('virtualGroupItem2', TableForm::coColW4);
				$this->layoutClose('pl1');
			$this->layoutClose();

			if (isset($virtualGroup['queryColumns']) && isset($virtualGroup['queryColumns']['virtualGroupItem3']))
			{
				$this->layoutOpen(self::ltHorizontal);
					$this->layoutOpen(self::ltVertical);
						$this->addColumnInput('virtualGroupItem3', TableForm::coColW6);
					$this->layoutClose('pl1');
					$this->layoutOpen(self::ltVertical);
						if (isset($virtualGroup['queryColumns']) && isset($virtualGroup['queryColumns']['virtualGroupItem4']))
							$this->addColumnInput ('virtualGroupItem4', TableForm::coColW4);
					$this->layoutClose('pl1');
					$this->layoutOpen(self::ltVertical);
					if (isset($virtualGroup['queryColumns']) && isset($virtualGroup['queryColumns']['virtualGroupItem5']))
						$this->addColumnInput ('virtualGroupItem5', TableForm::coColW4);
					$this->layoutClose('pl1');
				$this->layoutClose();
			}

		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$this->recData['virtualGroup'], []);

		if (isset($virtualGroup['queryColumns'][$colDef ['sql']]))
			return $virtualGroup['queryColumns'][$colDef ['sql']];

		return parent::columnLabel ($colDef, $options);
	}
}
