<?php

namespace lib\web;

use e10\TableView, e10\utils, e10\uiutils;


/**
 * Class ViewerSidebarImagesWiki
 * @package lib\web
 */
class ViewerSidebarImagesWiki extends \lib\web\ViewerSidebarImages
{
	public function selectRows ()
	{
		$fts = $this->fullTextSearch();
		$primaryTableId = $this->queryParam('comboSrcTableId');
		$primaryRecNdx = $this->queryParam('comboSrcRecId');
		$userSectionsParam = $this->queryParam('userSections');

		$q[] = 'SELECT att.* FROM [e10_attachments_files] AS att ';
		array_push($q, ' LEFT JOIN [e10pro_kb_texts] AS texts ON att.recid = texts.ndx');
		array_push($q, ' WHERE 1');
		array_push ($q, 'AND att.[tableid] = %s', $primaryTableId);

		if ($fts !== '')
		{
			array_push($q, ' AND att.[name] LIKE %s', '%'.$fts.'%');

			$us = explode('.', $userSectionsParam);
			array_push($q, ' AND texts.[section] IN %in', $us);
		}
		else
		{
			array_push ($q,' AND recid = %i', $primaryRecNdx);
		}


		array_push ($q, 'AND [filetype] IN %in', ['jpg', 'jpeg', 'png', 'gif', 'svg']);
		array_push($q, ' AND att.[deleted] = 0');

		array_push ($q, ' ORDER BY att.[defaultImage] DESC, att.[order], att.[name]');

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}
