<?php

namespace e10\persons;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \Shipard\Viewer\TableView, \E10\TableViewDetail, \Shipard\Table\DbTable, \Shipard\Utils\Utils;
use \Shipard\Form\TableForm;
use \Shipard\Application\DataModel;

/**
 * class TableAddrPlacesInReg
 */
class TableAddrPlacesInReg extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.persons.addrPlacesInReg', 'e10_persons_addrPlacesInReg', 'Adresní místa v registru');
	}

	public function columnRefInput ($form, $srcTable, $srcColumnId, $options, $label, $inputPrefix)
	{
		$pk = isset ($form->recData [$srcColumnId]) ? $form->recData [$srcColumnId] : 0;

		$ndxColumn = '';
		$titleColumn = '';

		$autocomplete = $this->app()->model()->tableProperty ($this, 'autocomplete');
		if ($autocomplete)
		{
			$ndxColumn = $autocomplete ['columnValue'];
			$titleColumn = $autocomplete ['columnTitle'];
		}

    /*
		$q = "SELECT [fullName] FROM [e10_persons_persons] WHERE [ndx] = " . intval ($pk);
		$refRec = $this->app()->db()->query ($q)->fetch ();
		$refTitle = $refRec [$titleColumn] ?? '';
    */
    $refTitle = '';

		$thisTableId = $this->tableId();
		$srcTableId = $srcTable->tableId ();
		$ip = str_replace ('.', '_', $inputPrefix);

		$columnInputClass = 'e10-inputNdx';
		if ($options & DataModel::coSaveOnChange)
			$columnInputClass .= ' e10-ino-saveOnChange';

		$class = 'e10-inputReference header';

		$inputParams = '';
		if ($options & TableForm::coReadOnly)
			$inputParams = " readonly='readonly'";

		$inputCode  = '';
		$inputCode .= "<div id='{$form->fid}_refinp_$ip{$srcColumnId}' class='$class'$inputParams>";
		$inputCode .= "<input type='hidden' name='$inputPrefix{$srcColumnId}' id='inp_$ip{$srcColumnId}' class='$columnInputClass' data-column='$srcColumnId' data-fid='{$form->fid}'/>";
		$inputCode .= "<input name='$inputPrefix{$srcColumnId}' id='inp_refid_$ip{$srcColumnId}' class='e10-inputRefId e10-viewer-search autofocus' style='width: 80%;' data-column='$srcColumnId' data-srctable='$srcTableId' data-sid='{$form->fid}Sidebar' autocomplete='off' autofocus='autofocus' $inputParams/>";

		$inputCode .= "<span class='btns' style='display:none;'>";
		//if (!($options & TableForm::coReadOnly))
		//	$inputCode .= $this->app()->ui()->icon('system/actionClose', 'e10-inputReference-clearItem').'&nbsp;';
		//$inputCode .= $this->app()->ui()->icon('system/actionOpen', 'e10-inputReference-editItem', 'i', " data-table='$thisTableId' data-pk='0'").'&nbsp;';
		$inputCode .= "</span>";

		$inputCode .= "<span class='e10-refinp-infotext'>" .$refTitle . '</span>';

		if (intval($pk))
		{
			$clsf = \E10\Base\ListClassification::referenceWidget($form, $srcColumnId, $this, $pk);
			if ($clsf['html'] !== '')
			{
				$inputCode .= "<div style='padding: 2px; clear: both; margin: 4px; '>".$clsf['html'].'</div>';
			}
		}
		$inputCode .= '</div>';

		$info ['widgetCode'] = NULL;
		$info ['inputCode'] = $inputCode;
		$info ['labelCode'] = NULL;
		if ($label)
			$info ['labelCode'] = "<label for='inp_refid_$ip{$srcColumnId}'>" . Utils::es ($label) . "</label>";

		return $info;
	}
}


/**
 * class ViewAddrPlacesInReg
 */
class ViewAddrPlacesInReg extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

		parent::init();
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

    $url = 'https://data.shipard.app/papi/la-addr-places/?';
    $url .= http_build_query(['q' => $fts]);

    $response = Utils::http_get($url);

		$responseContent = NULL;
		if (isset($response['content']))
			$responseContent = json_decode($response['content'], TRUE);

    if (isset($responseContent['success']) && $responseContent['success'])
    {
      foreach ($responseContent['object']['addrPlaces'] as $r)
      {
        $this->queryRows[] = $r;
      }
    }
	}

	public function renderRow ($item)
	{
//		$listItem ['pk'] = $item ['addrPlaceId'];
		$listItem ['pk'] = json_encode($item);
    //$listItem ['data-cc']['addrPlaceInReg'] = strval($item['addrPlaceId']);
    //$listItem ['data-cc']['stdAddrPlaceData'] = json_encode($item);

		$listItem ['t1'] = [];
		if ($item['streetFullName'])
			$listItem ['t1'][] = ['text' => $item['streetFullName'], 'class' => 'label label-default'];

		$houseNr = ['text' => $item['houseNr'], 'class' => 'label label-default'];
		if ($item['houseNr1Type'])
			$houseNr['prefix'] = 'č.ev.';
		elseif ($item['houseNr1Type'] === 0 && !$item['street'] /*&& !$item['cityPart']*/)
			$houseNr['prefix'] = 'č.p.';

		$listItem ['t1'][] = $houseNr;

		//$listItem ['i1'] = ['text' => '#'.Utils::nf($item['addrPlaceId']), 'class' => 'id'];

		$listItem ['t2'] = [];

		if ($item['cityPart2FullName'])
			$listItem ['t2'][] = ['text' => $item['cityPart2FullName'], 'class' => 'label label-warning'];

		$city = ['text' => $item['cityFullName'], 'class' => 'label label-success'];
		if ($item['zipCodeIdName'])
			$city['suffix'] = $item['zipCodeIdName'];

		if ($item['cityPartFullName'] && $item['cityPartFullName'] != $item['cityFullName'])
			$city['text'] .= ' - '.$item['cityPartFullName'];

		$listItem ['t2'][] = $city;

		if ($item['admUnit2FullName'] && $item['admUnit2FullName'] != $item['cityFullName'])
			$listItem ['t2'][] = ['text' => $item['admUnit2FullName'], 'class' => 'label label-info'];
		if ($item['admUnit1FullName'])
			$listItem ['t2'][] = ['text' => $item['admUnit1FullName'], 'class' => 'label label-primary'];
		if ($item['admUnit0FullName'] && $item['admUnit0FullName'] != $item['cityFullName'])
			$listItem ['t2'][] = ['text' => $item['admUnit0FullName'], 'class' => 'label label-default'];

		$listItem ['icon'] = '';

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}
