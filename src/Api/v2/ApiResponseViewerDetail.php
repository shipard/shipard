<?php

namespace Shipard\Api\v2;


class ApiResponseViewerDetail extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Viewer\TableViewDetail */
  var $detail;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    $tableId = $this->requestParams['table'] ?? '';
    /** @var \Shipard\Table\DbTable */
    $table = $this->app->table ($tableId);
    if (!$table)
    {
      error_log("--INVALID-TABLE-- `{$tableId}`");
    }

    $this->detail = NULL;
    $viewId = $this->requestParams['viewId'] ?? $this->requestParams['view-id'] ?? 'default';
    $detailId = $this->requestParams['detailId'] ?? $this->requestParams['detail-id'] ?? 'default';

    $rowNdx = intval($this->requestParams['pk'] ?? 0);
    if ($table)
      $this->detail = $table->getDetailData ($viewId, $detailId, $rowNdx);
    if ($this->detail)
    {
      $this->detail->doIt();
      $this->responseData['hcContent'] = $this->detail->objectData ['htmlContent'];
      $this->responseData['hcHeader'] = $this->detail->objectData ['htmlHeader'];

      /*
      $this->objectData ['htmlContent'] = $this->createDetailCode ();
      $this->objectData ['htmlHeader'] = $this->createHeaderCode ();
      $this->objectData ['htmlButtons'] = $this->createToolbarCode ();
      */

      $this->responseData['type'] = $this->requestParam('actionId') ?? 'INVALID';
      $this->responseData['objectType'] = 'dataViewDetail';
      //$this->responseData['objectId'] = $v->vid;
    }
    else
    {
      error_log("--INVALID-VIEWER-DETAIL-- `{$viewId}` / `{$detailId}`");
    }
  }
}
