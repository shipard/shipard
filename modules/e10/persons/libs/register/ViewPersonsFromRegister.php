<?php

namespace e10\persons\libs\register;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;

/**
 * class ViewPersonsFromRegister
 */
class ViewPersonsFromRegister extends TableView
{
  public $properties = [];
	var $classification = [];
	public $addresses = [];

	var $existedPersons = [];

	public function init ()
	{
    parent::init();
    $this->fullWidthToolbar = TRUE;
		$this->rowsPageSize = 500;

		$this->objectSubType = TableView::vsMini;
	}

	public function selectRows ()
	{
		$this->rowsPageSize = 500;
		$this->queryRows = [];
		$this->ok = 1;

    if ($this->rowsFirst > 0)
      return;

    $fts = $this->fullTextSearch ();
    if ($fts === '')
      return;

    /*
		$q = [];
    array_push ($q, 'SELECT [persons].* ');
    array_push ($q, ' FROM [e10_persons_persons] AS [persons]');
    array_push ($q, ' WHERE 1');

    array_push($q, ' AND [persons].[lastName] LIKE %s', '%'.$fts.'%');

		array_push ($q, ' ORDER BY [persons].[lastName]');

    array_push ($q, $this->sqlLimit());
    */
		//$this->runQuery ($q);


    $url = 'https://data.shipard.org/persons?';
    $url .= http_build_query(['q' => $fts, 'showAs' => 'json']);

    $response = Utils::http_get($url);
		$responseContent = NULL;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

    if (isset($responseContent['status']) && $responseContent['status'])
    {
      foreach ($responseContent['results'] as $r)
      {
        $this->queryRows[] = $r;
      }
    }
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['oid'] = $item ['oid'];

		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => '#'.$item['id'], 'class' => 'id'];

		$listItem ['t2'] = [];

		$listItem ['t2'][] = ['text' => $item['primaryAddressText'], 'class' => '', 'icon' => 'user/home'];

		$listItem ['t2'][] = ['text' => 'IČ: '.$item['oid'], 'class' => 'label label-default'];

		if (isset($item['vatID']) && $item['vatID'] !== '')
			$listItem ['t2'][] = ['text' => 'DIČ: '.$item['vatID'], 'class' => 'label label-default'];

		if (isset($item['valid']) && $item['valid'] !== 1)
			$listItem ['t2'][] = ['text' => 'UKONČENO', 'suffix' => $item['validToH'], 'class' => 'label label-warning'];

		return $listItem;
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$oids = [];
		foreach ($this->queryRows as $op)
		{
			$oids[] = $op['oid'];
		}

		$rows = $this->db()->query('SELECT [recid], [valueString] FROM [e10_base_properties] WHERE [tableid] = %s', 'e10.persons.persons',
		' AND [valueString] IN %in', $oids,
		' AND  [property] = %s', 'oid', ' AND [group] = %s', 'ids');

		foreach ($rows as $r)
		{
			$this->existedPersons[$r['valueString']] = ['ndx' => $r['recid']];
		}
	}

	function decorateRow (&$item)
	{
		if (isset($this->existedPersons[$item['oid']]))
		{
			$item ['i1'] = ['text' => 'Firma již existuje', 'class' => 'label label-danger'];
		}
	}

  public function createToolbar ()
	{
		return [];
	}

  public function createFullWidthToolbarCode()
	{
		$fts = Utils::es($this->fullTextSearch ());

		$placeholder = 'zadejte IČ nebo pár slov z názvu firmy, a stiskněte ⏎';

		$c = '';

		$c .= "<div class='e10-sv-search e10-sv-search-toolbar e10-bg-t9' style='padding-left: 1ex; padding-right: 1ex;' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		$c .=	"<table style='width: 100%'><tr>";

		//$c .= $this->createCoreSearchCodeBegin();

		$c .= "<td class='fulltext' style='min-width:99%;'>";
		$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".Utils::es($placeholder)."' value='$fts' data-onenter='1' style='width: calc(100% - 1em); padding: 6px 2em;'/>";
		$c .=	"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0' style='margin-left: -2.5em; padding: 6px 2ex 3px 1ex; position:inherit; width: 2.5em; text-align: center;'><icon class='fa fa-times' style='width: 1.1em;'></i></span>";
		$c .= '</td>';

		$c .= "<td style='width: auto;'>";
		$c .= "<div class='viewerQuerySelect e10-dashboard-viewer'>";
		$c .= "<input name='mainQuery' type='hidden' value=''/>";
		$c .= '</div>';
		$c .= '</td>';


		$c .= '</tr></table>';
		$c .= '</div>';

		return $c;
	}
}
