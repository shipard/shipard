<?php

namespace e10doc\core\libs;
use \e10\base\TableAttachments;
use \e10\base\ViewAttachmentsAll;


/**
 * ViewerAttachmentsToSend
 */
class ViewerAttachmentsToSend extends ViewAttachmentsAll
{
	public function init ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'deleted', 'title' => 'Smazané'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['name'];
		$listItem ['i2'] = '#'.$item ['ndx'];
		$listItem ['image'] = $this->app->dsRoot.'/imgs/-w192/-h384/att/'.$item['path'].$item['filename'];

		return $listItem;
	}

	public function qryCommon (array &$q)
	{
    $srcDocNdx = $this->queryParam('srcDocNdx');
    $linkedIssues = [];

    $qi = [];
    array_push($qi, 'SELECT * FROM [e10_base_doclinks] AS [links]');
		array_push($qi, ' LEFT JOIN [wkf_core_issues] AS [issues] ON [links].dstRecId = [issues].ndx');
		array_push($qi, ' WHERE [links].linkId = %s', 'e10docs-inbox',
                    ' AND srcTableId = %s', 'e10doc.core.heads', ' AND srcRecId = %i', $srcDocNdx);
    $linkedIssuesRows = $this->db()->query($qi);
    foreach ($linkedIssuesRows as $lir)
    {
      $linkedIssues[] = $lir['dstRecId'];
    }

		array_push($q, 'AND [filetype] IN %in', ['pdf']);
		array_push($q, 'AND (');
    array_push ($q, ' (', 'att.[tableid] = %s', 'e10doc.core.heads', ' AND att.[recid] = %i)', $srcDocNdx);

		if (count($linkedIssues))
		{
      array_push($q, ' OR ');
			array_push($q,
				' EXISTS (SELECT ndx FROM wkf_core_issues WHERE att.recid = ndx AND tableId = %s', 'wkf.core.issues',
        ' AND wkf_core_issues.[ndx] IN %in', $linkedIssues,
				')');
		}
		array_push ($q, ')');
	}
}
