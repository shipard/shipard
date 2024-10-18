<?php

namespace e10mnf\core\libs;


use \Shipard\Base\Utility;
use Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;


/**
 * class WorkInProgressEngine
 */
class WorkInProgressEngine extends Utility
{
  /** @var \e10mnf\core\TableWorkRecs */
  var $tableWorkRecs;

  var $wipRecs = [];
  var $wips = [];

  public function init()
  {
    $this->tableWorkRecs = $this->app()->table('e10mnf.core.workRecs');
  }

  public function loadState()
  {
    $this->wipRecs = [];

    $q = [];
    array_push($q, 'SELECT [wr].* ');
    array_push($q, ' FROM [e10mnf_core_workRecs] AS [wr]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [docState] = %i', 1000);
    array_push($q, ' AND [workInProgress] = %i', 1);
    array_push($q, ' AND [person] = %i', $this->app()->userNdx());
    array_push($q, ' ORDER BY ndx');

    $rows = $this->db()->query($q);

    foreach ($rows as $r)
    {
      $this->wipRecs[] = $r->toArray();
    }
  }

  public function startWork()
  {
    if (count($this->wipRecs))
      return;

    $now = new \DateTime();

    $dbCounterNdx = intval($this->app()->testGetParam('wrDbCounter'));
    $workActivityNdx = intval($this->app()->testGetParam('workActivity'));
    $docKindNdx = intval($this->app()->testGetParam('wrDocKind'));

    $newWR = [
      'dbCounter' => $dbCounterNdx,
      'person' => $this->app()->userndx(),
      'docType' => 0,
      'docKind' => $docKindNdx,
      'workActivity' => $workActivityNdx,
      'beginDate' => $now->format('Y-m-d'),
      'beginTime' => $now->format('H:i'),
      'endDate' => NULL,
      'endTime' => '',
      'workInProgress' => 1,
      'docState' => 1000,
      'docStateMain' => 0,
    ];

    $newNdx = $this->tableWorkRecs->dbInsertRec($newWR);
    $this->tableWorkRecs->docsLog($newNdx);
  }

  public function endWork()
  {
    $wipRecNdx = $this->wipRecs[0]['ndx'] ?? 0;
    if (!$wipRecNdx)
      return;

    $now = new \DateTime();

    $updateWR = $this->wipRecs[0];

    $updateWR['endDate'] = $now->format('Y-m-d');
    $updateWR['endTime'] = $now->format('H:i');
    $updateWR['workInProgress'] = 0;
    $updateWR['docState'] = 4000;
    $updateWR['docStateMain'] = 2;

    $this->tableWorkRecs->checkDocumentState ($updateWR);
    $this->tableWorkRecs->dbUpdateRec($updateWR);
    $this->tableWorkRecs->checkAfterSave2($updateWR);
    $this->tableWorkRecs->docsLog($wipRecNdx);
  }

  protected function loadWips()
  {
    $ug = $this->app()->userGroups ();

    $q = [];
    array_push ($q, 'SELECT wips.*');
    array_push ($q, ' FROM [e10mnf_base_wipSettings] AS wips');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [docState] IN %in', [4000, 8000]);
    array_push ($q, ' AND EXISTS (');
      array_push ($q, 'SELECT ndx');
      array_push ($q, ' FROM [e10mnf_base_wipSettingsPersons] AS wipsPersons');
      array_push ($q, ' WHERE  wips.ndx = wipsPersons.wipSettings');
      array_push ($q, ' AND (');
      array_push ($q, '(wipsPersons.rowType = %i', 1, ' AND wipsPersons.person = %i)', $this->app->userNdx());
      if ($ug && count($ug))
        array_push ($q, ' OR (wipsPersons.rowType = %i', 0, ' AND wipsPersons.personsGroup IN %in)', $ug);
      array_push ($q, ')');
    array_push ($q, ')');

    array_push ($q, ' ORDER BY wips.[order], wips.[fullName]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $wip = $r->toArray();
      $this->wips[] = $wip;
    }
  }

  public function buttonCode()
  {
    $this->loadWips();

    $c = '';
    foreach ($this->wips as $wip)
    {
      $btnTitleStart = ($wip['btnTextStart'] !== '') ? $wip['btnTextStart'] : $wip['fullName'];
      $btnTitleStop = ($wip['btnTextStop'] !== '') ? $wip['btnTextStop'] : 'Ukončit práci';

      $dataActionParams = 'wrDbCounter='.$wip['wrDbCounter'];
      $dataActionParams .= '&wrDocKind='.$wip['wrDocKind'];
      $dataActionParams .= '&workActivity='.$wip['workActivity'];

      if ($this->app()->mobileMode)
      {
        $c .= "<div class='e10-startMenu-tile tile e10-widget-trigger'";
        if (count($this->wipRecs) === 0)
        {
          $c .= " data-action='action-wip-startWork'";
          $c .= " data-action-params='".$dataActionParams."'";
          $c .= " style='background-color: green;'";
        }
        else
        {
          $c .= " data-action='action-wip-endWork'";
          $c .= " style='background-color: red;'";
        }
        $c .= '>';

        $c .= "<ul>";

        if (count($this->wipRecs) === 0)
        {
          $c .= "<li class='left'>";
          $c .= $this->app()->ui()->icon('system/iconDateOfOrigin');
          $c .= "</li>";

          $c .= "<li class='content'><div class='title1'>";
          $c .= Utils::es($btnTitleStart);
          $c .= '</div></li>';
        }
        else
        {
          $c .= "<li class='left'>";
          $c .= $this->app()->ui()->icon('cmnbkpClosePeriod');
          $c .= "</li>";

          $c .= "<li class='content'><div class='title1'>";
          $c .= Utils::es('Ukončit práci');
          $c .= '</div></li>';
        }
        $c .= "</ul>";
        $c .= "</div>";
      }
      else
      {
        if (count($this->wipRecs) === 0)
        {
          $c .= "<button class='btn btn-success e10-widget-trigger' data-action='action-wip-startWork'";
          $c .= " data-action-params='".$dataActionParams."'";
          $c .= '>';
          $c .= $this->app()->ui()->icon('system/iconDateOfOrigin').' ';
          $c .= Utils::es($btnTitleStart);
          $c .= '</button>';
          $c .= '&nbsp;';
        }
        else
        {
          $c .= "<button class='btn btn-danger e10-widget-trigger' data-action='action-wip-endWork'>";
          $c .= $this->app()->ui()->icon('cmnbkpClosePeriod').' ';
          $c .= Utils::es('Ukončit práci');
          $c .= '</button>';
          $c .= '&nbsp;';
        }
      }

      if (count($this->wipRecs))
        break;
    }
    return $c;
  }
}
