<?php

namespace services\nomenc\libs;

use \Shipard\Utils\Utils, \Shipard\Base\Utility;


class NomeclatureImport extends Utility
{
  var string $nomencId = '';
  var ?array $nomencCfg = NULL;
  var int $nomenctTypeNdx = 0;

  function loadConfig()
  {
    $idParts = explode('-', $this->nomencId);
    $fnBase = $idParts[0].'/'.$this->nomencId;


    $fnFull = __SHPD_MODULES_DIR__.'/services/nomenc/config/'.$fnBase.'.json';
    $cfg = $this->loadCfgFile($fnFull);
    if ($cfg === FALSE)
    {
      
      return FALSE;
    }

    $this->nomencCfg = $cfg;

    return TRUE;
  }

  function checkNomencType()
  {
    $exist = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', $this->nomencCfg['typeId'])->fetch();
    if (isset($exist['id']))
    {
      $this->nomenctTypeNdx = $exist['ndx'];
      return TRUE;
    }

    $insert = $this->nomencCfg['nomencTypeDef'];
    $insert['id'] = $this->nomencCfg['typeId'];
    $insert['docState'] = 4000;
    $insert['docStateMain'] = 2;

    $this->db()->query('INSERT INTO [e10_base_nomencTypes]', $insert);
    $this->nomenctTypeNdx = intval ($this->db()->getInsertId ());
    if (!$this->nomenctTypeNdx)
    {
      return $this->err('Insert new type failed...');
    }

    return TRUE;
  }

  function doImport()
  {
    /** @var \lib\nomenclature\ImportNomenclature $o */
    $o = $this->app()->createObject($this->nomencCfg['classImport'] ?? '');
    if (!$o)
    {
      return $this->err('Invalid import class `'.$this->nomencCfg['classImport'].'`');
    }
    $o->run();
  }

  public function run()
  {
    if (!$this->loadConfig())
      return;

    if (!$this->checkNomencType())  
      return;

    $this->doImport();

    //print_r($this->nomencCfg);
  }
}

