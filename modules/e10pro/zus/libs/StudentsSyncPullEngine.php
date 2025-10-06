<?php

namespace e10pro\zus\libs;

/**
 * class StudentsSyncPullEngine
 */
class StudentsSyncPullEngine extends \e10pro\bume\libs\PersonsSyncPullEngine
{
  var $sociPeriod = 'AY2';

  protected function checkOnePerson($personNdx, $personInfo)
  {
    if (!isset($personInfo['other']['teacherName']))
    {
      echo "No teacherName for person $personNdx: ".$personInfo['rec']['fullName']."\n";
      return;
    }
    $existedWO = $this->db()->query('SELECT * FROM e10mnf_core_workOrders',
                                    ' WHERE title = %s', $personInfo['other']['teacherName'],
                                    ' AND usersPeriod = %s', $this->sociPeriod)->fetch();

    if (!$existedWO)
    {
      $newWO = [
        'title' => $personInfo['other']['teacherName'],
        'usersPeriod' => $this->sociPeriod,
        'dbCounter' => 1,
        'docState' => 1200, 'docStateMain' => 1,
      ];

      /** @var \e10mnf\core\TableWorkOrders $tableWO */
      $tableWO = $this->app()->table('e10mnf.core.workOrders');
      $tableWO->checkNewRec($newWO);
      $newWONdx = $tableWO->dbInsertRec($newWO);

      $newWO = $tableWO->loadItem($newWONdx);
      $tableWO->checkDocumentState($newWO);
      $tableWO->dbUpdateRec($newWO);

      $existedWO = $this->db()->query('SELECT * FROM e10mnf_core_workOrders',
                                      ' WHERE title = %s', $personInfo['other']['teacherName'],
                                      ' AND usersPeriod = %s', $this->sociPeriod)->fetch();
    }

    if ($existedWO)
    {
      //echo "::: ".json_encode($personInfo['other']['teacherName'])."\n";
      //echo json_encode($existedWO->toArray())."\n\n";

      $wondx = $existedWO['ndx'];
      $existInWO = $this->db()->query('SELECT woPersons.*, wo.title AS woTitle FROM [e10mnf_core_workOrdersPersons] AS woPersons',
																			' LEFT JOIN [e10mnf_core_workOrders] AS wo ON woPersons.workOrder = wo.ndx',
																			' WHERE 1',
                                      ' AND wo.[usersPeriod] = %s', $this->sociPeriod,
                                      ' AND woPersons.person = %i', $personNdx)->fetch();

      if (!$existInWO)
      {
        $woPerson = ['workOrder' => $wondx, 'rowOrder' => $personNdx, 'person' => $personNdx];
        $this->db()->query('INSERT INTO [e10mnf_core_workOrdersPersons] ', $woPerson);
      }
    }
  }

  protected function apiCallClassId()
  {
    return 'zus-students-sync-pull';
  }
}

