<?php

namespace e10pro\fp\libs\apps;


/**
 * class FilePortalUIStructMenuCreator
 */
class FilePortalUIStructMenuCreator extends \Shipard\UI\ng\UIStructMenuCreator
{
  public function run ()
  {
    /*** @var \e10pro\fp\TableFilePortals */
    $tableFilePortals = $this->app()->table('e10pro.fp.filePortals');
    $userNdx = $this->app()->uiUserNdx();
    $usersFilePortals = $tableFilePortals->loadUsersPortals($userNdx);

    foreach ($usersFilePortals as $fpNdx => $fpData)
    {
      $menuItem = [
        'title' => $fpData['portalFullName'],
        'icon' => 'user/folder',
        'id' => 'fp-'.$fpData['portalUId'],
        'objectType' => 'widget',
        'classId' => 'e10pro.fp.libs.apps.WidgetFilePortal',
        'widgetParams' => ['data-request-portal-uid' => $fpData['portalUId']]
      ];

      $this->subMenuContent['items']['fp-'.$fpData['portalUId']] = $menuItem;
    }
  }
}

