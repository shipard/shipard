<?php

namespace Shipard\Utils;
use Shipard\Base\Utility;


/**
 * class Variables
 */
class Numbers extends Utility
{
  var $data = [];
  var $sections = [];

  var $moneyUnit = 'Kč';

  CONST ctNumber = 0, ctMoney = 1, ctDecimal = 2;

  public function addSection ($sectionId, $title)
  {
    $itm = [
      'id' => $sectionId,
      'title' => $title,
      'parts' => []
    ];

    $this->sections[$sectionId] = $itm;
  }

  public function addSectionPart ($sectionId, $partId, $mark, $title)
  {
    $itm = [
      'id' => $partId,
      'mark' => $mark,
      'title' => $title,
      'numbers' => [],
    ];

    $this->sections[$sectionId]['parts'][$partId] = $itm;
  }

  public function addNumber ($numberId, $sectionId, $partId, $title, $value, $unit = '', $dec = 2)
  {
    $itm = [
      'id' => $numberId,
      'sectionId' => $sectionId,
      'partId' => $partId,
      'partRowId' => count($this->sections[$sectionId]['parts'][$partId]['numbers'] ?? []) + 1,
      'title' => $title,
      'value' => $value,
      'unit' => $unit,
      'dec' => $dec
    ];

    $this->sections[$sectionId]['parts'][$partId]['numbers'][] = $numberId;

    $this->data[$numberId] = $itm;
  }

  public function addMoney ($numberId, $sectionId, $partId, $title, $value)
  {
    $this->addNumber($numberId, $sectionId, $partId, $title, $value, $this->moneyUnit, 2);
  }

  public function addString ($numberId, $sectionId, $partId, $title, $value)
  {
    $this->addNumber($numberId, $sectionId, $partId, $title, $value, '', 0);
  }

  public function addNumberNote($numberId, $note)
  {
    if (!isset($this->data[$numberId]['notes']))
      $this->data[$numberId]['notes'] = [];

    $esNote = Utils::es($note);
    $noteCode = $this->resolveFormula($esNote);
    if ($noteCode === $note)
      $this->data[$numberId]['notes'][] = ['text' => $note, 'class' => 'block e10-me'];
    else
    {
      $cc = "<span class='block' style='font-size: 110%;'>".$noteCode.'</span>';
      $this->data[$numberId]['notes'][] = ['code' => $cc/*, 'class' => 'block e10-me'*/];
    }
  }

  public function addSubColumns ($sectionId, $partId, $scInfo, $subColumnsData)
  {
    foreach ($scInfo['columns'] as $scColumn)
    {
      $numberId = $scColumn['id'];
      $this->addNumber($numberId, $sectionId, $partId, $scColumn['name'], $subColumnsData[$numberId]);
    }
  }

  public function getNumberStr($numberId)
  {
    $number = $this->data[$numberId];
    $value = Utils::nf($number['value'], $number['dec']);
    return $value;
  }

  public function getMoney($numberId)
  {
    return$this->data[$numberId]['value'] ?? 999999;
  }

  public function partContentTable($sectionId, $partId)
  {
    $contentTable = [];
    foreach ($this->data as $numberId => $number)
    {
      if ($number['sectionId'] !== $sectionId || $number['partId'] !== $partId)
        continue;

      $rowId = $this->sections[$sectionId]['parts'][$partId]['mark'].$number['partRowId'];

      if (is_string($number['value']))
        $value = $number['value'];
      else
        $value = Utils::nf($number['value'], $number['dec']);

      $contentItem = [
        'rowId' => $rowId,
        'pv' => $value,

        'unit' => $number['unit'],
      ];

      if (isset($number['notes']))
      {
        $contentItem['title'] = [['text' => $number['title'], 'class' => '']];
        foreach ($number['notes'] as $n)
        {
          $contentItem['title'][] = $n;
        }
      }
      else
        $contentItem['title'] = $number['title'];

      $contentTable[$numberId] = $contentItem;
    }

    $contentTitle = ['text' =>
      $this->sections[$sectionId]['parts'][$partId]['mark'] . '. '. $this->sections[$sectionId]['parts'][$partId]['title'],
      'class' => 'h3'
    ];
    $contentHeader = [
      'rowId' => '|#',
      'title' => 'Položka',
      'pv' => ' Hodnota',
      'unit' => 'Jed.'
    ];
    $content = ['type' => 'table', 'table' => $contentTable, 'header' => $contentHeader, 'title' => $contentTitle];

    return $content;
  }

  function resolveFormula($str)
	{
    $res = preg_replace_callback('/\[\((.*?)\)\]/', function ($m) {
        return $this->resolveFormulaNumber($m[0]);
      }, $str);

		return $res;
	}

  protected function resolveFormulaNumber($varId)
  {
    $format = '';
    $coreId = substr ($varId, 2, -2);
    $varId = strchr($coreId, ':', TRUE);
    if ($varId === FALSE)
    {
      $varId = $coreId;
    }
    else
    {
      $format = substr($coreId, strlen($varId) + 1);
    }

    if (!isset($this->data[$varId]))
    {
       return 'Invalid numberId `'.$varId.'`';
    }

    $number = $this->data[$varId];
    if (is_string($number['value']))
      $value = $number['value'];
    else
      $value = Utils::nf($number['value'], $number['dec']);

    $sectionId = $number['sectionId'];
    $partId = $number['partId'];

    $badgeTitle = "title='".Utils::es($number['title'])."'";

    $code = "<span style='background-color: #F0F0F0; border: 1px solid #BBB; border-radius: 3%; font-size:80%; margin: 0 .1rem;' $badgeTitle>";
    $code .= "<span style='padding: 0 .2rem;'>";
    $code .= $value;
    if ($number['unit'] !== '')
      $code .= ' '.$number['unit'];
    $code .= '</span>';
    $code .= "<span style='background-color: #AFDCEC; padding: 0 .2rem; border-left: 1px solid #BBB;'>";

    $code .= $this->sections[$sectionId]['parts'][$partId]['mark'].$number['partRowId'];
    $code .= '</span>';
    $code .= '</span>';

    return $code;
  }
}
