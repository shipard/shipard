<?php

namespace e10pro\canteen;
use \e10\Utility, \e10\utils;


/**
 * Class SupplierMenuSender
 * @package e10pro\canteen
 */
class SupplierMenuSender extends Utility
{
	var $fromCron = 0;

	public function init()
	{
	}

	function sendMenuCanteen ($canteen)
	{
		if (trim($canteen['supplierEmail']) === '')
			return;

		$subject = 'Objednávka jídla - '.$canteen['fn'];

		$body = '';

		$body .= '<html><body>';

		$body .= "Dobrý den, <br><br>";
		$body .= "zasíláme Vám přehled objednaných jídel pro ".utils::es($canteen['fn']).".<br><br>";


		$o = new \e10pro\canteen\dataView\DisplayMenuWidget($this->app());
		$o->isRemoteRequest = 1;
		$o->setRequestParams(['showAs' => 'email', 'showTotalOrders' => 1, 'canteen' => $canteen['ndx']]);
		$o->run();

		$body .= $o->data['html'];

		$body .= '</body></html>';

		$emails = preg_split("/[\s,]+/", $canteen['supplierEmail']);
		foreach ($emails as $emailTo)
		{
			$msg = new \E10\MailMessage($this->app);
			$msg->setFrom($canteen['fn'], $this->app->cfgItem('options.core.ownerEmail'));
			$msg->setTo($emailTo);

			$msg->setSubject($subject);
			$msg->setBody($body, TRUE);
			$msg->sendMail();
		}
	}

	public function run()
	{
		$canteens = $this->app->cfgItem ('e10pro.canteen.canteens', []);
		foreach ($canteens as $canteenNdx => $canteen)
		{
			if ($this->fromCron)
			{
				$today = utils::today('Y-m-d');
				$lockFileName = __APP_DIR__ . '/tmp/canteen-'.$canteenNdx.'-supplier-order-' . $today . ".info";
				if (is_readable($lockFileName))
					continue;

				$firstTime = (isset($canteen['sendSupplierOrderTime']) && $canteen['sendSupplierOrderTime'] !== '') ? $canteen['sendSupplierOrderTime'] : '07:35';
				$firstMoment = new \DateTime($today.' '.$firstTime.':00');

				$now = new \DateTime();
				if ($firstMoment > $now)
					continue;

				touch($lockFileName);
			}

			$this->sendMenuCanteen($canteen);
		}
	}
}

