<?php

namespace integrations\govbox\libs;

use \Shipard\Utils\Utils;



class DownloadSentMessages extends \Shipard\Base\Utility
{
  public function run()
  {
    echo "Downloading sent messages from GovBox...\n";



    $days = 14;
    $limit = 5;


		$govBoxes = $this->app()->cfgItem('integrations.govboxes', NULL);
		if ($govBoxes === NULL)
			return;

		$gbNdx = key($govBoxes);
		$govBox = $govBoxes[$gbNdx];
		$productionMode = ($govBox['testMode'] == 0);

		$dataBox = new \Defr\CzechDataBox\DataBox();
		$dataBox->loginWithUsernameAndPassword($govBox['login'], $govBox['password'], $productionMode);
		$simpleApi = $dataBox->getSimpleApi();

    $messages = $simpleApi->getListOfSentMessages();
    foreach ($messages as $message)
    {
      echo "<h2>Msg# " . $message->getDmID() . "</h2>";
      var_dump($message);

      echo "<h3>Signed message</h3>";
      var_dump($simpleApi->downloadSignedSentMessage($message->getDmID()));

      echo "<h3>Delivery info</h3>";
      var_dump($simpleApi->downloadDeliveryInfo($message->getDmID()));
    }
  }
}
