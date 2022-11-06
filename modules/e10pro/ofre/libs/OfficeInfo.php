<?php

namespace e10pro\ofre\libs;
use Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;

/**
 * class OfficeInfo
 */
class OfficeInfo extends \e10mnf\core\libs\WorkOrderInfo
{
  var $atts;

  public function loadInfo()
  {
    parent::loadInfo();
    $this->loadMetersReadings();
    $this->loadIssues();
  }

	public function loadRows ()
	{
    parent::loadRows();

    if (isset($this->data['rowsContent']))
    {
      $this->data['rowsContent']['title']['text'] = 'Předpis plateb';
      $this->data['rowsContent']['title']['icon'] = 'user/moneyBill';
      $this->data['rowsContent']['header'] = ['#' => '#', 'text' => 'Účet platby', 'priceAll' => ' Částka'];
    }
	}

  public function loadMetersReadings ()
	{
    $q = [];
    array_push($q, 'SELECT vals.*,');
    array_push($q, ' [meters].[fullName] AS meterFullName, [meters].[shortName] AS meterShortName, [meters].[unit] AS meterUnit, [meters].[id] AS meterId');
    array_push($q, ' FROM [e10pro_meters_values] AS [vals]');
    array_push($q, ' LEFT JOIN [e10pro_meters_meters] AS [meters] ON [vals].[meter] = [meters].[ndx]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [meters].[workOrder] = %i', $this->recData['ndx']);
    array_push($q, ' ORDER BY [vals].[datetime] DESC, [meters].[id], [vals].[ndx]');

    $units = $this->app->cfgItem ('e10.witems.units');
    $t = [];
    $h = ['date' => 'Datum'];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $rowId = $r['datetime']->format('Y-m-d');
      $meterId = 'M'.$r['meter'];

      if (!isset($h[$meterId]))
      {
        $h[$meterId] = ' '.$r['meterShortName'];
        $h[$meterId.'U'] = 'jed.';
      }

      if (!isset($t[$rowId]))
      {
        $t[$rowId]['date'] = Utils::datef($r['datetime']);
      }
      $t[$rowId][$meterId] = $r['value'];
      $t[$rowId][$meterId.'U'] = $units[$r['meterUnit']]['shortcut'];
    }

		if (count ($t))
		{
			$this->data['rowsMetersReadings'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => ['icon' => 'tables/e10pro.meters.values', 'text' => 'Poslední odečty'],
        'header' => $h, 'table' => $t
      ];
		}
  }

  public function loadIssues ()
	{
		$q = [];
    array_push ($q, 'SELECT issues.*');
    array_push ($q, ' FROM [wkf_core_issues] AS issues');
    array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [workOrder] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY [dateCreate] DESC, [ndx] DESC');

    $t = [];
    $pks = [];
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $title = [
        ['text' => $r['subject'], 'class' => 'h2 block'],
        ['text' => Utils::datef($r['dateIncoming']), 'class' => 'label label-default'],
        ['text' => ' ', 'class' => 'block'],
      ];

      $i = [
        'issue' => $title,
        '_options' => ['cellClasses' => ['pict' => 'width20']]
      ];

      $t[$r['ndx']] = $i;
      $pks[] = $r['ndx'];
    }

		$this->atts = UtilsBase::loadAttachments ($this->app(), $pks, 'wkf.core.issues');
    foreach ($this->atts as $issueNdx => $att)
    {
      $links = $this->attLinks($issueNdx);

      if (count($links))
        $t [$issueNdx]['issue'] = array_merge($t [$issueNdx]['issue'], $links);
      if (!isset($t[$issueNdx]['pict']) && isset($links[0]['data']['url-preview']))
      {
        $t[$issueNdx]['pict'] = [
          'code' => "<img src='{$links[0]['data']['url-preview']}'style='max-width: 100%;'/>",
        ];
      }
    }

    if (count($t))
    {
      $h = ['pict' => 'Test', 'issue' => 'Zpráva'];
      $title = [['text' => 'Pošta k předání', 'class' => 'h2 padd5 pb1', 'icon' => 'user/envelope']];

      $title[] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Předat vše', 'data-class' => 'e10pro.ofre.libs.HandOverPostWizard', 'icon' => 'system/actionUpload',
        'actionClass' => 'btn btn-primary', 'class' => 'pb1 pull-right',
      ];


      $this->data['issues'] = [
        'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $title,
        'type' => 'table', 'table' => $t, 'header' => $h, 'params' => ['hideHeader' => 1,],
      ];
    }
  }

	function attLinks ($ndx)
	{
		$links = [];
		$attachments = $this->atts[$ndx];
		if (isset($attachments['images']))
		{
			foreach ($attachments['images'] as $a)
			{
				$icon = ($a['filetype'] === 'pdf') ? 'system/iconFilePdf' : 'system/iconFile';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => 'e10-att-link btn btn-xs btn-default df2-action-trigger', 'prefix' => ''];
				$l['data'] =
					[
						'action' => 'open-link',
						'url-download' => $this->app()->dsRoot.'/att/'.$a['path'].$a['filename'],
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename'],
						'popup-id' => 'wdbi', 'with-shift' => 'tab' /* 'popup' */
					];
				$links[] = $l;
			}
		}

		return $links;
	}
}
