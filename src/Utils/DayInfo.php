<?php

namespace Shipard\Utils;
use \Shipard\Utils\Utils;


/**
 * class DayInfo
 */
class DayInfo extends \Shipard\Base\Utility
{
  var $year = 0;

  var $yearInfo = [];
  var $dayInfo = [];

  var $calCountryId = 'cz';
  var $calInfoCfg = NULL;


  public function setYear($year)
  {
    if ($this->year !== $year)
    {
      $this->year = $year;
      $this->init();
    }
  }

  public function dayInfo($date)
  {
    $y = intval($date->format('Y'));
    $m = intval($date->format('m'));
    $d = intval($date->format('d'));
    $dow = intval($date->format('N'));

    $this->setYear($y);

    // -- nameDay
    if (isset($this->calInfoCfg['nameDays'][$m][$d]))
    {
      if (is_array($this->calInfoCfg['nameDays'][$m][$d]))
      {
        $this->dayInfo ['nameDays'] = $this->calInfoCfg['nameDays'][$m][$d];
        $this->dayInfo ['nameDay'] = implode(', ', $this->calInfoCfg['nameDays'][$m][$d]);
      }
      else
      {
        $this->dayInfo ['nameDays'] = [$this->calInfoCfg['nameDays'][$m][$d]];
        $this->dayInfo ['nameDay'] = $this->calInfoCfg['nameDays'][$m][$d];
      }
    }

    // -- working day / holiday
    $this->dayInfo ['nwd'] = 0;
    $this->dayInfo ['wd'] = 1;
    $this->dayInfo ['hd'] = 0;
    if ($dow > 5)
    {
      $this->dayInfo ['nwd'] = 1;
    }
    if (isset($this->yearInfo['holidays'][$m][$d]))
    {
      $this->dayInfo ['nwd'] = 1;
      $this->dayInfo ['hd'] = 1;
    }
    if ($this->dayInfo ['nwd'])
      $this->dayInfo ['wd'] = 0;
  }

  public function format(string $f)
  {
    $res = str_replace('%n', $this->dayInfo ['nameDay'] ?? '', $f);
    $res = str_replace('%N', $this->dayInfo ['nwd'], $res);
    $res = str_replace('%W', $this->dayInfo ['wd'], $res);
    $res = str_replace('%H', $this->dayInfo ['hd'], $res);

    return $res;
  }

  protected function createYearInfo()
  {
    if (isset($this->calInfoCfg['holidays']))
    {
      foreach ($this->calInfoCfg['holidays'] as $monthId => $month)
      {
        if ($monthId === 'relative')
          continue;

        $m = intval($monthId);
        foreach ($month as $dayId => $day)
        {
          $d = intval($dayId);

          foreach ($day as $dh)
          {
            if (isset($dh['yearMin']) && $this->year < $dh['yearMin'])
              continue;
            if (isset($dh['yearMax']) && $this->year > $dh['yearMax'])
              continue;

            $h = [
              'title' => $dh['ln'],
            ];

            if (!isset($this->yearInfo['holidays'][$m][$d]))
              $this->yearInfo['holidays'][$m][$d] = [];

            $this->yearInfo['holidays'][$m][$d][] = $h;
          }
        }
      }
    }
  }

  public function init()
  {
    $this->calInfoCfg = Utils::loadCfgFile(__SHPD_MODULES_DIR__.'install/country-modules/calendar/'.$this->calCountryId.'.json');
    $this->createYearInfo();
  }
}
