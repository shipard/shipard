<?php

namespace e10pro\fp\libs\dc;


/**
 * class DCStorage
 */
class DCStorage extends \Shipard\Base\DocumentCard
{

  protected function addUsers()
  {
    $q = [];
    array_push ($q, 'SELECT [su].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ' FROM [e10pro_fp_storagesUsers] AS [su]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [su].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [su].[user] = [users].[ndx]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [su].[storage] = %i', $this->recData['ndx']);

    $rows = $this->app()->db()->query($q);
    $table = [];
    foreach ($rows as $r)
    {
      $table[] = [
        'pk' => $r['ndx'],
        'user' => ['text' => $r['userFullName'], 'docAction' => 'new', 'table' => 'e10pro.fp.storagesUsers', 'pk' => $r['ndx']],
      ];
    }

    $h = [
      '#' => '#',
      'user' => 'Uživatel',
    ];

    $title [] = [
      ['text' => 'Uživatelé', 'class' => 'h2'],
      [
        'docAction' => 'new', 'table' => 'e10pro.fp.storagesUsers', 'text' => 'Přidat',
        'type' => 'button',
        'actionClass' => 'btn btn-success btn-xs', 'icon' => 'system/actionAdd', 'class' => 'pull-right',
        'addParams' => "__storage={$this->recData['ndx']}"
      ],
    ];


    $this->addContent ('body', [
      'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
      'header' => $h, 'table' => $table, 'title' => $title,
    ]);
  }

  public function createContent ()
	{
    $this->addUsers();
	}
}
