<?php

namespace e10doc\invoicesOut\libs\apps;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;

/**
 * class ViewInvoicesOut
 */
class ViewInvoicesOut extends TableView
{
  var $currencies;
  var $paymentMethods;
  var $today;

	public function init ()
	{
    $this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->today = date('ymd');
		$this->paymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods');

		$this->classes = ['viewerWithCards'];
		$this->enableToolbar = FALSE;

		parent::init();

		$this->objectSubType = TableView::vsDetail;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem['class'] = 'card';

		$listItem ['docNumber'] = $item['docNumber'];
    $listItem ['title'] = $item['title'];
    $listItem ['currency'] = $this->currencies[$item ['currency']]['shortcut'];
    $listItem ['dateAccounting'] = Utils::datef($item['dateAccounting']);
    $listItem ['sumTotal'] = Utils::nf($item['sumTotal'], 2);
    $listItem ['symbol1'] = $item['symbol1'];
    $listItem ['symbol2'] = $item['symbol2'];

		return $listItem;
	}

	public function selectRows ()
	{
		$q = [];
    array_push($q, 'SELECT [heads].*,');
		array_push($q, ' persons.fullName as personFullName');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' WHERE 1');

    $this->appQuery($q);

    array_push ($q, ' ORDER BY [dateAccounting] DESC, [heads].[docNumber]');

    array_push ($q, $this->sqlLimit ());

    $this->runQuery ($q);
	}

  protected function appQuery(&$q)
  {
  }

	protected function balanceInfo ($item, &$listItem)
	{
		$bi = new \e10doc\balance\BalanceDocumentInfo($this->app());
		$bi->setDocRecData ($item);
		$bi->run ();

		if (!$bi->valid)
			return;

    $balanceInfo = [];

		$line = [];
		$line[] = ['text' => utils::datef($item['dateDue']), 'icon' => 'system/iconStar'];

		if ($bi->restAmount < 1.0)
		{
			$balanceInfo['text'] = 'Uhrazeno';
      $balanceInfo['icon'] = 'system/iconCheck';
      $balanceInfo['class'] = 'bg-success';
		}
		else
    {
			if ($bi->restAmount == $item['toPay'])
			{
        if ($bi->daysOver > 0)
        {
          $balanceInfo['text'] = 'NEUHRAZENO';
          $balanceInfo['icon'] = 'system/iconWarning';
          $balanceInfo['class'] = 'bg-danger';
        }
        else
        {
          $balanceInfo['text'] = 'Uhraďte do: '.Utils::datef($item['dateDue'], '%S');
          $balanceInfo['icon'] = 'system/iconCheck';
          $balanceInfo['class'] = 'bg-info';
        }
			}
			else
			{
        $balanceInfo['text'] = 'Částečně uhrazeno, zbývá '.Utils::nf($bi['restAmount'], 2);
        $balanceInfo['icon'] = 'system/iconCheck';
        $balanceInfo['class'] = 'bg-warning';
			}
    }

    $listItem['balanceInfo'] = $balanceInfo;
	}

  public function addReportsForDownload($item, &$listItem)
	{
    $docNdx = $item['ndx'];
    $docNumber = $item['docNumber'];

    $q = [];
    array_push($q, 'SELECT * FROM [wkf_core_issues]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND recNdx = %i', $docNdx);
    array_push($q, ' AND tableNdx = %i', 1078);
    array_push($q, ' ORDER BY ndx DESC');
    array_push($q, ' LIMIT 1');

    $outBoxRecs = $this->db()->query($q);
    foreach ($outBoxRecs as $or)
    {
      $attachments = UtilsBase::loadAttachments ($this->app(), [$or['ndx']], 'wkf.core.issues');
      if (isset($attachments[$or['ndx']]['images']))
      {
        $attIdx = 0;
        foreach ($attachments[$or['ndx']]['images'] as $a)
        {
          if (strtolower($a['filetype']) !== 'pdf')
            continue;

          $attFileName = $this->app()->urlRoot.'/att/'.$a['path'].$a['filename'];
          $attName = $a['name'];
          if (!$attIdx)
            $attName = 'VF'.$docNumber;

          if (!str_ends_with($attName, '.pdf'))
            $attName .= '.pdf';

          $listItem['reportFileName'] = $attFileName;

          $attIdx++;
          break;
        }
      }
    }
	}

	public function createToolbar()
	{
		return [];
	}
}
