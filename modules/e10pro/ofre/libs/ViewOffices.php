<?php

namespace e10pro\ofre\libs;
use \e10\base\libs\UtilsBase;

/**
 * class ViewOffices
 */
class ViewOffices extends \e10mnf\core\ViewWorkOrders
{
	var $issues = [];
	var $atts = [];

	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY customers.fullName, workOrders.[docNumber]');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		$issuesPks = [];

		$q = [];
		array_push ($q, 'SELECT issues.*');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [workOrder] IN %in', $this->pks);
		array_push ($q, ' ORDER BY [dateCreate] DESC, [ndx] DESC');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->issues[$r['workOrder']][] = $r->toArray();
			$issuesPks[] = $r['ndx'];
		}

		if (count($issuesPks))
		{
			$this->atts = UtilsBase::loadAttachments ($this->app(), $issuesPks, 'wkf.core.issues');
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}

		if (isset ($this->issues[$item ['pk']]))
		{
			$item['i2'] = ['text' => strval(count($this->issues[$item ['pk']])), 'icon' => 'user/envelope', 'class' => 'label label-danger'];

			/*
			foreach ($this->issues[$item ['pk']] as $issue)
			{
				if (isset($this->atts[$issue['ndx']]))
				{
					$attLinks = $this->attLinks($issue['ndx']);
					if (count($attLinks))
					{
						$item['t3'] = array_merge($item['t3'], $attLinks);
					}
				}
			}
			*/
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
