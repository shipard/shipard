<?php

namespace e10\persons\libs;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class AddrStandardizationCLITool
 */
class AddrStandardizationCLITool extends Utility
{
  var $usedInFiscalYear = 0;
  var $country = 60; // CZ
  var $personType = 1; // citizens, not companies

  var $maxSuccessfullyCount = 3;

  /** @var \e10\persons\TablePersonsContacts */
  var $tablePersonsContacts;

  public function init()
  {
    $this->tablePersonsContacts = $this->app()->table('e10.persons.personsContacts');
  }

  public function run()
  {
    $this->init();


		$q [] = 'SELECT [contacts].*,';
		array_push ($q, ' [persons].[fullName] AS [personName], [persons].[personType], [persons].[company],');
		array_push ($q, ' [persons].[docState] AS [personDocState], [persons].[docStateMain] AS [personDocStateMain]');
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [contacts].[person] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].flagAddress = %i', 1);
    array_push ($q, ' AND [persons].[personType] = %i', $this->personType);
    array_push ($q, ' AND [contacts].[flagStandardized] = 0');
    array_push ($q, ' AND [contacts].[adrCountry] = %i', $this->country);
    array_push ($q, ' AND [contacts].[docState] = %i', 4000); // active

    if ($this->usedInFiscalYear)
      array_push ($q, ' AND EXISTS (SELECT ndx FROM e10doc_core_heads WHERE contacts.person = e10doc_core_heads.person ',
                                    ' AND [fiscalYear] = %i', $this->usedInFiscalYear, ')');

    $rows = $this->db()->query($q);
    $successfullyCount = 0;
    foreach ($rows as $r)
    {
      $contactNdx = $r['ndx'];

      if ($this->app()->debug)
        echo "# `{$r['personName']}`: ";

      $se = new \e10\persons\libs\AddrStandardizationEngine($this->app());
      if ($se->standardizeAddressViaSuggestions($contactNdx))
        $successfullyCount++;

      if ($this->app()->debug)
        echo "\n";

      if ($successfullyCount >= $this->maxSuccessfullyCount)
        break;
    }
  }
}
