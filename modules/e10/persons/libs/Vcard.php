<?php

namespace e10\persons\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


class Vcard extends Utility
{
  var $personNdx = 0;
  var $personRecData = NULL;

  var $info = [];

  /** @var \e10\persons\TablePersons $tablePersons */
	var	$tablePersons;


  public function setPerson($personNdx)
  {
		$this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->personNdx = $personNdx;
    $this->personRecData = $this->tablePersons->loadItem($personNdx);
  }

  protected function createVcard()
  {
		$q [] = 'SELECT valueString, [property] FROM [e10_base_properties]';
		array_push ($q, ' WHERE [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $this->personNdx);
		array_push ($q, ' AND [property] IN %in', ['email', 'phone'], ' AND [group] = %s', 'contacts');
		array_push ($q, ' ORDER BY ndx');
		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['property'] === 'email')
			{
				if (!isset($this->info['email']))
					$this->info['email'] = $r['valueString'];
				$this->info['emails'][] = $r['valueString'];
			}
			if ($r['property'] === 'phone')
			{
				if (!isset($this->info['phone']))
					$this->info['phone'] = $r['valueString'];
				$this->info['phones'][] = $r['valueString'];
			}
		}

		$ld = "\r\n";
		$v = '';
		$v .= 'BEGIN:VCARD'.$ld;
		$v .= 'VERSION:4.0'.$ld;
 		$v .= 'FN:'.$this->personRecData['fullName'].$ld;
		if (isset($this->info['email']))
 			$v .= 'EMAIL;TYPE=work:'.$this->info['email'].$ld;
		if (isset($this->info['phone']))
 			$v .= 'TEL;TYPE=cell:'.$this->info['phone'].$ld;
 		$v .= 'END:VCARD'.$ld;

		$this->info['vcard'] = $v;
  }

	public function createQRCode()
	{
		$vcHash = 'c_'.sha1($this->info['vcard']);
		$dirName = __APP_DIR__.'/imgcache/persons/';

		$vcardBaseFileName = $vcHash.'.vcard';
		$vcardFullFileName = $dirName.$vcardBaseFileName;
    if (!is_readable($vcardFullFileName))
      file_put_contents($vcardFullFileName, $this->info['vcard']);

		$qrBaseFileName = $vcHash.'.svg';
		$qrFullFileName = $dirName.$qrBaseFileName;
    $this->info ['vcardQRCodeFullFileName'] = $qrFullFileName;
		$this->info ['vcardQRCodeURL'] = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/imgcache/persons/'.$qrBaseFileName;

		if (is_readable($qrFullFileName))
			return;

    if (!is_dir($dirName))
      Utils::mkDir($dirName);

		$cmd = "qrencode -lM -m 0 -t SVG --rle -o \"{$qrFullFileName}\" -r \"{$vcardFullFileName}\"";
		exec ($cmd);
		//$cmd = "qrencode -8 -t SVG -m 0 --rle -o \"{$qrFullFileName}\" \"https://x.yz.abc/qwert\"";
		//exec ($cmd);
	}

  public function run()
  {
    if (!$this->personRecData)
      return;

    $this->createVcard();
    $this->createQRCode();
  }
}