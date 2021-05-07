<?php

namespace lib\web;

use e10\TableView, e10\utils, e10\uiutils;


/**
 * Class ViewerSidebarImages
 * @package lib\web
 */
class ViewerSidebarImages extends TableView
{
	public function init ()
	{
/*
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'deleted', 'title' => 'Smazané'];
		$this->setMainQueries ($mq);
*/
		$this->htmlRowsElement = 'div';
		$this->htmlRowElement = 'div';

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;
		$this->objectSubType = TableView::vsMini;
	}

	public function zeroRowCode ()
	{
		$c = '';


		$c .= "<div class='e10-tvw-item' id='{$this->vid}Footerdddd'>";

		$c .= $this->addBtnCode ([
			['title' => 'Galerie všech obrázků', 'icons' => ['icon-picture-o'], 'code' => '{{articleImage}}', 'text' => 'Vše'],
			[
				'title' => 'Galerie vybraných obrázků', 'icons' => ['icon-check', 'icon-picture-o'],
				'code' => '{{articleImage;', 'function' => 'webTextArticleImageSelected', 'text' => 'Vybrané'
			]
		]);

		$c .= $this->addBtnCode ([
			['title' => 'Kolotoč všech obrázků', 'icons' => ['icon-spinner'], 'code' => '{{articleImage;showAs:carousel}}', 'text' => 'Vše'],
			[
				'title' => 'Kolotoč vybraných obrázků', 'icons' => ['icon-check', 'icon-spinner'],
				'code' => '{{articleImage;showAs:carousel;', 'function' => 'webTextArticleImageSelected', 'text' => 'Vybrané'
			]
		]);

		//$recId = intval ($this->queryParam ('recid'));
		//$c .= $this->app()->ui()->addAttachmentsInputCode($this->queryParam('tableid'), $this->queryParam('recid'), $this->vid);
		$c .= '</div>';

		return $c;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch();
		$primaryTableId = $this->queryParam('comboSrcTableId');
		$primaryRecNdx = $this->queryParam('comboSrcRecId');

		$q[] = 'SELECT att.* FROM [e10_attachments_files] AS att ';
		array_push($q, ' WHERE 1');

		if ($fts !== '')
		{
			array_push($q, ' AND att.[name] LIKE %s', '%'.$fts.'%');
		}

		array_push($q, ' AND att.[deleted] = 0');

		array_push ($q, 'AND [filetype] IN %in', ['jpg', 'jpeg', 'png', 'gif', 'svg']);

		array_push ($q, 'AND (');
		array_push ($q, ' (', 'att.[tableid] = %s', $primaryTableId, ' AND recid = %i', $primaryRecNdx,  ')');
		array_push ($q, ')');

		array_push ($q, ' ORDER BY att.[defaultImage] DESC, att.[order], att.[name]');

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function rowHtml ($listItem)
	{
		$url = \e10\base\getAttachmentUrl ($this->table->app(), $listItem);
		$thumbUrl = \e10\base\getAttachmentUrl ($this->table->app(), $listItem, 600, 500);


		$c = '';

		$class = '';
		if ($listItem ['deleted'])
			$class = ' deleted';

		$c .= "<div class='e10-pane e10-pane-mini {$class}' style='margin: .5ex .5ex;' data-pk='{$listItem['ndx']}'>";
		$c .= "<table class'fullWidth' style='padding: 3px;'>";


		$c .= "<tr><td colspan='2' class='padd5' style='border-bottom: 1px solid rgba(0,0,0,.2)'>";


		$c .= utils::es($listItem ['name']);

		$c .= $this->createItemMenuCode ($listItem, 'e10-tvw-item-menu pull-right');
		$c .= "<span class='e10-att-link df2-action-trigger pull-right' data-action='open-link' data-url-download='$url' data-url-preview='$url'>";
		$c .= "<span class='pre' style='padding-right: 0;'></span>";
		$c .= '</span>';

		$c .= '</td></tr>';

		$c .= '<tr>';
		$c .= "<td class='padd5' style='vertical-align: top; border-right:1px solid rgba(0,0,0,.2);'>";
		$c .= "<input type='checkbox' name='vchbx_{$listItem['ndx']}' value='{$listItem ['ndx']}'/> ";

		$c .= strval ($this->lineRowNumber + $this->rowsFirst).'.';

		$c .= "<span class='pull-right'>#{$listItem ['ndx']}</span>";

		$c .= "<div class='padd5 break number' style='line-height: 2.5em;'>";
		$c .= $this->addBtnCode ([['title' => 'Přidat jako samostatný obrázek', 'icons' => ['icon-picture-o'], 'code' => '{{articleImage;id:'.$listItem['ndx'].'}}']]);
		$c .= '<br>';
		$c .= $this->addBtnCode ([['title' => 'Přidat jako obrázek zarovnaný doleva', 'icons' => ['icon-angle-double-left', 'icon-picture-o'], 'code' => '{{articleImage;id:'.$listItem['ndx'].';style:left}}']]);
		$c .= $this->addBtnCode ([['title' => 'Přidat jako obrázek zarovnaný doprava', 'icons' => ['icon-picture-o', 'icon-angle-double-right'], 'code' => '{{articleImage;id:'.$listItem['ndx'].';style:right}}']]);
		$c .= "</div>";


		$c .= '</td>';

		$c .= "<td style='text-align: right; width: 60%; '>";
		//$c .= "<a href='$url' target='new'><img style='width:100%;' src='$thumbUrl'/></a>";
		$c .= "<img style='max-width:100%;' src='$thumbUrl'/>";
		$c .= '</td>';

		$c .= '</tr>';

		/*
		 * \E10\es ($listItem ['name'])
		 *
		if (isset ($this->classification [$listItem ['ndx']]))
		{
			$tags = [];
			forEach ($this->classification [$listItem ['ndx']] as $clsfGroup)
				$tags = array_merge ($tags, $clsfGroup);
			$c .= "<div class='padd5'>".$this->app()->ui()->composeTextLine($tags).'</div>';
		}
		*/

		//$c .= $this->createItemMenuCode ($listItem, 'e10-tvw-item-menu');

		$c .= '</tr></table>';
		$c .= '</div>';
		return $c;
	}

	public function createItemMenuCode ($item, $class = 'e10-tvw-item-menu right')
	{
		$deleted = false;
		$trash = $this->table->app()->model()->tableProperty ($this, 'trash');
		if ($trash != FALSE)
		{
			$trashColumn = $trash ['column'];

			if (isset ($trash ['value']))
				$trashValue = $trash ['value'];
			else
				$trashValue = 1;

			if ($item [$trashColumn] == $trashValue)
				$deleted = true;
		}

		$c  = "<span class='$class'>";

		if (!$deleted)
		{
			$c .= "<button class='btn btn-primary btn-xs df2-action-trigger' data-formId='default' data-action='editform' title='Opravit'><i class='fa fa-pencil'></i></button> ";
		}

		if ($trash != FALSE)
		{
			if ($deleted)
				$c .= "<button class='btn btn-success btn-xs df2-action-trigger' data-action='undeleteform'>Vzít z koše zpět</button> ";
			else
				$c .= "<button class='btn btn-danger btn-xs df2-action-trigger' data-action='deleteform' title='Smazat'><i class='fa fa-trash'></i></button> ";
		}

		$c .= "</span>";


		return $c;
	}

	function addBtnCode ($buttons)
	{
		$c = '';

		foreach ($buttons as $b)
		{
			$c .= " <button class='btn btn-default btn-sm e10-sidebar-setval' title=\"" . utils::es($b['title']) . "\"";

			if (isset($b['code']))
				$c .= " data-b64-value='" . base64_encode($b['code']) . "'";
			if (isset($b['function']))
				$c .= " data-function-value='" . $b['function'] . "'";

			$c .= '>';

			foreach ($b['icons'] as $icon)
			{
				$i = $this->app()->ui()->icons()->cssClass($icon);
				$c .= "<i class='$i'></i> ";
			}
			if (isset($b['text']))
				$c .= utils::es($b['text']);

			$c .= '</button>';
		}
		return $c;
	}
}
