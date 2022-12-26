<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;


/**
 * Class TableParams
 */
class TableParams extends DbTable
{
	CONST
		ptNumber = 0,
		ptString = 3
	;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.params', 'mac_iot_params', 'Parametry');
	}

  public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

    if ($recData['paramType'] == TableParams::ptNumber)
      $recData['defaultValueStr'] = strval($recData['defaultValueNum']);
    else
      $recData['defaultValueNum'] = 0;
  }

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//	$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}
}


/**
 * Class ViewParams
 */
class ViewParams extends TableView
{
  VAR $paramsTypes;

	public function init ()
	{
    $this->paramsTypes = $this->app()->cfgItem('mac.iot.paramsTypes');

		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'idName'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $t2 = [];

    $t2[] = ['text' => $item['idName'], 'class' => 'label label-primary'];

    $pt = $this->paramsTypes[$item['paramType']] ?? NULL;
    if ($pt)
      $t2[] = ['text' => $pt['fn'], 'class' => 'label label-default'];

    if ($item['paramType'] == TableParams::ptNumber)
      $t2[] = ['text' => $item['defaultValueNum'], 'class' => 'label label-info', 'suffix' => 'Výchozí hodnota'];
    else
      $t2[] = ['text' => $item['defaultValueStr'], 'class' => 'label label-info', 'suffix' => 'Výchozí hodnota'];


    $listItem['t2'] = $t2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [params].*';
		array_push ($q, ' FROM [mac_iot_params] AS [params]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [params].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[params].', ['[params].[shortName], [fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormParam
 */
class FormParam  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('idName');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('paramType');
          $this->addSeparator(self::coH4);
          if ($this->recData['paramType'] == TableParams::ptNumber)
            $this->addColumnInput ('defaultValueNum');
          else
            $this->addColumnInput ('defaultValueStr');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailParam
 */
class ViewDetailParam extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

