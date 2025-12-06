<?php

namespace integrations\eds\libs;
use \Shipard\Utils\Json;


/**
 * class EDSEngineOpenWeather
 */
class EDSEngineOpenWeather extends \integrations\eds\libs\EDSEngineCore
{
  var $iconListFontAwesome = [
    '01d' => ['icon' => 'sun', 'emoji' => '☀️', 'class' => 'clear-sky-day'],
    '01n' => ['icon' => 'moon', 'emoji' => '🌙', 'class' => 'clear-sky-night'],
    '02d' => ['icon' => 'cloud-sun', 'emoji' => '🌤️', 'class' => 'few-clouds-day'],
    '02n' => ['icon' => 'cloud-moon', 'emoji' => '🌥️', 'class' => 'few-clouds-night'],
    '03d' => ['icon' => 'cloud', 'emoji' => '☁️', 'class' => 'scattered-clouds'],
    '03n' => ['icon' => 'cloud', 'emoji' => '☁️', 'class' => 'scattered-clouds'],
    '04d' => ['icon' => 'clouds', 'emoji' => '☁️', 'class' => 'broken-clouds'],
    '04n' => ['icon' => 'clouds', 'emoji' => '☁️', 'class' => 'broken-clouds'],
    '09d' => ['icon' => 'showers', 'emoji' => '🌧️', 'class' => 'shower-rain'],
    '09n' => ['icon' => 'showers', 'emoji' => '🌧️', 'class' => 'shower-rain'],
    '10d' => ['icon' => 'cloud-sun-rain', 'emoji' => '🌦️', 'class' => 'rain-day'],
    '10n' => ['icon' => 'cloud-moon-rain', 'emoji' => '🌧️', 'class' => 'rain-night'],
    '11d' => ['icon' => 'cloud-bolt', 'emoji' => '⛈️', 'class' => 'thunderstorm'],
    '11n' => ['icon' => 'cloud-bolt', 'emoji' => '⛈️', 'class' => 'thunderstorm'],
    '13d' => ['icon' => 'snowflakes', 'emoji' => '❄️', 'class' => 'snow'],
    '13n' => ['icon' => 'snowflakes', 'emoji' => '❄️', 'class' => 'snow'],
    '50d' => ['icon' => 'cloud-fog', 'emoji' => '🌫️', 'class' => 'fog'],
    '50n' => ['icon' => 'cloud-fog', 'emoji' => '🌫️', 'class' => 'fog'],
  ];


  public function postProcessData(&$data)
  {
    $data['shpForecast'] = ['daily' => []];

    foreach ($data['daily'] as $d)
    {
      $fd = [
        'date' => $this->epochToDate($d['dt']),
        'dt' => $this->epochToDateTime($d['dt']),
        'sunrise' => $this->epochToHHMM($d['sunrise']),
        'sunset' => $this->epochToHHMM($d['sunset']),
        'moonrise' => $this->epochToHHMM($d['moonrise']),
        'moonset' => $this->epochToHHMM($d['moonset']),
        'moonInfo' => $this->moonInfo($d['moon_phase']),
        'summary' => $d['summary'],
        'temp' => [],
        'feels_like' => [],
        'pressure' => $d['pressure'],
        'humidity' => $d['humidity'],
        'wind_speed' => $d['wind_speed'],
        'wind_deg' => $d['wind_deg'],
        'clouds' => $d['clouds'],
        'pop' => $d['pop'],
      ];

      foreach ($d['temp'] as $k => $v)
      {
        $fd['temp'][$k] = $this->kelvinToCelsius($v, 1);
        $fd['temp'][$k.'0'] = $this->kelvinToCelsius($v, 0);
      }
      foreach ($d['feels_like'] as $k => $v)
      {
        $fd['feels_like'][$k] = $this->kelvinToCelsius($v, 1);
        $fd['feels_like'][$k.'0'] = $this->kelvinToCelsius($v, 0);
      }

      if (isset($d['rain']))
        $fd['rain'] = $d['rain'];
      if (isset($d['snow']))
        $fd['snow'] = $d['snow'];

      $iconId = $d['weather'][0]['icon'];
      if (isset($this->iconListFontAwesome[$iconId]))
      {
        $fd['icon'] = $this->iconListFontAwesome[$iconId]['icon'];
        $fd['iconEmoji'] = $this->iconListFontAwesome[$iconId]['emoji'];
        $fd['iconClass'] = $this->iconListFontAwesome[$iconId]['class'];
      }

      $data['shpForecast']['daily'][] = $fd;
    }

    if ($this->app()->debug)
      echo Json::lint($data['shpForecast'])."\n";
  }

  protected function epochToDateTime ($epoch)
  {
    return date('Y-m-d H:i:s', $epoch);
  }

  protected function epochToDate ($epoch)
  {
    return date('Y-m-d', $epoch);
  }

  protected function epochToHHMM ($epoch)
  {
    return date('H:i', $epoch);
  }

  protected function kelvinToCelsius ($kelvin, $decimals = 2)
  {
    return round($kelvin - 273.15, $decimals, PHP_ROUND_HALF_ODD);
  }

  protected function moonInfo($moonPhase)
  {
    $mi = [];
    $mi['phase'] = $moonPhase;
    $mi['illumination'] = round($moonPhase * 100, 1);
    if ($moonPhase == 0 || $moonPhase == 1)
    {
      $mi['title'] = 'New Moon';
      $mi['class'] = 'new-moon';
      $mi['emoji'] = '🌑';
    }
    elseif ($moonPhase > 0 && $moonPhase < 0.25)
    {
      $mi['title'] = 'Waxing Crescent';
      $mi['class'] = 'waxing-crescent';
      $mi['emoji'] = '🌒';
    }
    elseif ($moonPhase == 0.25)
    {
      $mi['title'] = 'First Quarter';
      $mi['class'] = 'first-quarter';
      $mi['emoji'] = '🌓';
    }
    elseif ($moonPhase > 0.25 && $moonPhase < 0.5)
    {
      $mi['title'] = 'Waxing Gibbous';
      $mi['class'] = 'waxing-gibbous';
      $mi['emoji'] = '🌔';
    }
    elseif ($moonPhase == 0.5)
    {
      $mi['title'] = 'Full Moon';
      $mi['class'] = 'full-moon';
      $mi['emoji'] = '🌕';
    }
    elseif ($moonPhase > 0.5 && $moonPhase < 0.75)
    {
      $mi['title'] = 'Waning Gibbous';
      $mi['class'] = 'waning-gibbous';
      $mi['emoji'] = '🌖';
    }
    elseif ($moonPhase == 0.75)
    {
      $mi['title'] = 'Last Quarter';
      $mi['class'] = 'last-quarter';
      $mi['emoji'] = '🌗';
    }
    elseif ($moonPhase > 0.75 && $moonPhase < 1)
    {
      $mi['title'] = 'Waning Crescent';
      $mi['class'] = 'waning-crescent';
      $mi['emoji'] = '🌘';
    }
    return $mi;
  }
}
