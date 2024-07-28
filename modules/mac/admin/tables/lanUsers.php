<?php

namespace mac\admin;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;


/**
 * Class TableLanUsers
 */
class TableLanUsers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.admin.lanUsers', 'mac_admin_lanUsers', 'LAN Uživatelé');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function getDocumentLockState ($recData, $form = NULL)
	{
		$lock = parent::getDocumentLockState ($recData, $form);
		if ($lock !== FALSE)
			return $lock;

    $userType = $this->app()->cfgItem('mac.admin.lanUsersTypes.'.$recData['userType'], []);
    $tokenize = $userType['tokenize'] ?? 0;
    if ($tokenize)
    {
      return ['mainTitle' => 'Uživatel je uzamčen', 'subTitle' => 'Jedná se o systémového uživatele'];
    }
		return FALSE;
	}
}


/**
 * Class ViewLanUsers
 */
class ViewLanUsers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
    $userType = $this->app()->cfgItem('mac.admin.lanUsersTypes.'.$item['userType'], []);

    $listItem ['pk'] = $item ['ndx'];
    $listItem ['i1'] = ['text' => '#'.$item ['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = [['text' => $item['login'], 'icon' => 'user/signIn', 'class' => 'label label-default']];

    if ($item['lanShortName'])
      $listItem ['t2'][] = ['text' => $item['lanShortName'], 'icon' => 'tables/mac.lan.lans', 'class' => ''];

    if ($userType['tokenize'] ?? 0)
    {
      $labelExp = ['text' => $item['expireAfter']->format('Y-m-d'), 'suffix' => $item['expireAfter']->format('H:m:j'), 'class' => 'label label-success'];
      if ($item['expired'])
        $labelExp['class'] = 'label label-danger';

      $listItem ['i2'] = $labelExp;
    }

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [users].*, lans.shortName AS lanShortName');
    array_push ($q, ' FROM [mac_admin_lanUsers] AS [users]');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON [users].lan = lans.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [name] LIKE %s', '%'.$fts.'%',
				' OR [login] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[users].', ['[expired], [userType], [name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailLanUser
 */
class ViewDetailLanUser extends TableViewDetail
{
}


/**
 * Class FormLanUser
 */
class FormLanUser extends TableForm
{
	public function renderForm ()
	{
    $userType = $this->app()->cfgItem('mac.admin.lanUsersTypes.'.$this->recData['userType'], []);
    $tokenize = $userType['tokenize'] ?? 0;
    $mandatory = $userType['mandatory'] ?? 0;
		$usePubKeys = $userType['usePubKeys'] ?? 0;

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		if ($usePubKeys)
			$tabs ['tabs'][] = ['text' => 'Klíče', 'icon' => 'user/key'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('userType');
					$this->addColumnInput ('login');
					$this->addColumnInput ('name');
					$this->addColumnInput ('password');
          if ($tokenize)
          {
            $this->addColumnInput ('lan');
            $this->addColumnInput ('expireAfter');
            $this->addColumnInput ('expired');
          }
					elseif ($mandatory)
						$this->addColumnInput ('lan');
				$this->closeTab ();

				if ($usePubKeys)
				{
					$this->openTab (TableForm::ltNone);
						$this->addList ('keys');
					$this->closeTab ();
				}
			$this->closeTabs ();
		$this->closeForm ();
	}
}
