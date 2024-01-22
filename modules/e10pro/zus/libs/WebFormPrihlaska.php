<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils, \e10\str;


class WebFormPrihlaska extends \Shipard\Base\WebForm
{
	var $valid = FALSE;
	var $pobocky;
	var $oddeleni;
	var $spamScore = '';

	public function fields ()
	{
		return [
			'firstNameS', 'lastNameS', 'datumNarozeni', 'mistoNarozeni', 'rodneCislo', 'statniPrislusnost',
			'street', 'city', 'zipcode',
			'skolaTrida', 'skolaNazev',
			'mistoNarozeni', 'statniPrislusnost',
			'misto', 'svpObor', 'svpOddeleni',
			'fullNameM', 'emailM', 'phoneM', 'useAddressM', 'streetM', 'cityM', 'zipcodeM',
			'fullNameF', 'emailF', 'phoneF', 'useAddressF', 'streetF', 'cityF', 'zipcodeF',
			'zdravotniPostizeni', 'zdravotniPostizeniPopis',
			'skolniRok',
		];
	}

	public function createFormCode ($options = 0)
	{
		$useReCaptcha = ($this->template && isset($this->template->pageParams['recaptcha-v3-site-key']));

		$this->nacistPobocky();

		$c = '';

		if ($useReCaptcha)
		{
			$c .= "<noscript><p>";
			$c .= Utils::es('Kontaktní formulář vyžaduje javascript...');
			$c .= "</p></noscript>";
		}

		$c .= "<form class='form-horizontal zus-prihlaska-form' method='POST'";
		if ($useReCaptcha)
			$c .= " style='display: none;'";

		$c .= ">";
		$c .= "<input type='hidden' name='webFormState' value='1'/>";
		$c .= "<input type='hidden' name='webFormId' value='e10pro.zus.libs.WebFormPrihlaska'/>";
		if ($useReCaptcha)
		{
			$c .= "<input type='hidden' id='recaptcha-response' name='webFormReCaptchtaResponse' value=''/>";
		}

		$c.= "<div class='row pt-3 zus-prihlaska-obor'>";
		$c.= "<div class='col col-4'>";
		$obory = zusutils::obory($this->app, TRUE, '-- Vyberte obor --');
		$c .= $this->addFormInput ('Obor', 'select', 'svpObor', ['select' => $obory, 'labelAbove' => 1, 'mandatory' => 1]);
		$c.= "</div>";

		$c.= "<div class='col col-4' id='studijni-zamereni'>";
		$oddeleni = $this->app->cfgItem ("e10pro.zus.oddeleni");
		$oddeleniEnum = [];
		$oddeleniEnum[0] = '-- Vyberte studijní zaměření --';
		foreach ($oddeleni as $oddeleniNdx => $oddeleniCfg)
		{
			if (intval($oddeleniNdx))
			{
				$oddeleniEnum[$oddeleniNdx] = $oddeleniCfg['nazev'];
				$this->oddeleni[$oddeleniCfg['obor']][] = $oddeleniNdx;
			}
		}
		$c .= $this->addFormInput ('Studijní zaměření', 'select', 'svpOddeleni', ['select' => $oddeleniEnum, 'labelAbove' => 1, 'mandatory' => 1]);
		$c.= "</div>";

		$c.= "<div class='col col-4'>";
		$c .= $this->addFormInput ('Studium na pobočce', 'select', 'misto', ['select' => zusutils::pobocky($this->app, TRUE, '-- Vyberte pobočku --'), 'labelAbove' => 1, 'mandatory' => 1]);
		$c.= "</div>";
		$c.= "</div>";

		$c.= "<small class='text-mutted' id='stop-stav-info'>";
		$c.= "tady by mohlo být něco pěkného";
		$c.= "</small>";


		//$c.= "<div class='row'>";
		$c .= "<h4>".'Osobní údaje žáka'.'</h4>';
		//$c.= "</div>";

		$c.= "<div class='row zus-prihlaska-jmeno-a-prijmeni'>";
			$c.= "<div class='col col-6'>";
				$c .= $this->addFormInput ('Jméno', 'text', 'firstNameS', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-6'>";
				$c .= $this->addFormInput ('Příjmení', 'text', 'lastNameS', ['mandatory' => 1]);
			$c.= "</div>";
		$c.= "</div>";

		$c.= "<div class='row pt-2 zus-prihlaska-osobni-udaje'>";
			$c.= "<div class='col col-3'>";
				$c .= $this->addFormInput ('Datum narození', 'date', 'datumNarozeni', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-3'>";
				$c .= $this->addFormInput ('Místo narození', 'text', 'mistoNarozeni', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-3'>";
				$c .= $this->addFormInput ('Rodné číslo', 'text', 'rodneCislo', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-3'>";
				$c .= $this->addFormInput ('Státní příslušnost', 'text', 'statniPrislusnost', ['mandatory' => 1]);
			$c.= "</div>";
		$c.= "</div>";


		$c.= "<div class='row pt-2 zus-prihlaska-adresa-zaka'>";
			$c.= "<div class='col col-5'>";
				$c .= $this->addFormInput ('Ulice a číslo popisné', 'text', 'street', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-5'>";
				$c .= $this->addFormInput ('Obec', 'text', 'city', ['mandatory' => 1]);
			$c.= "</div>";
			$c.= "<div class='col col-2'>";
				$c .= $this->addFormInput ('PSČ', 'text', 'zipcode', ['mandatory' => 1]);
			$c.= "</div>";
		$c.= "</div>";

		//$c.= "<div class='col col-12'>";
		$c .= "<h4>".'Od 1. září školního roku ';
		$c .= $this->addFormInput ('', 'select', 'skolniRok', ['select' => ['2024' => '2024 / 2025', '2023' => '2023 / 2024'], 'inputStyle' => 'width: 10rem; display: inline-block; margin-left: .5rem; margin-right: .5rem;']);
		$c .= ' bude žákem / žákyní (MŠ / ZŠ / SŠ):'.'</h4>';

		//$c.= "</div>";
		$c.= "<div class='row pt-3 zus-prihlaska-skola-zaka'>";
			$c.= "<div class='col col-4'>";
				$c .= $this->addFormInput ('Třída', 'select', 'skolaTrida', ['select' => ['1' => '1', '2' => '2', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9']]);
			$c.= "</div>";
			$c.= "<div class='col col-8'>";
				$c .= $this->addFormInput ('Název a místo školy', 'text', 'skolaNazev', ['mandatory' => 1]);
			$c.= "</div>";
		$c.= "</div>";

		$c.= "<div class='row pt-3 zus-prihlaska-zakonni-zastupci'>";
		$c.= "<div class='col col-6 zus-prihlaska-zz-1'>";
		$c .= "<h4>".'Zákonný zástupce 1'.'</h4>';
		$c .= $this->addFormInput ('Jméno a příjmení', 'text', 'fullNameM', ['mandatory' => 1]);
		$c .= $this->addFormInput ('Telefon', 'text', 'phoneM', ['mandatory' => 1]);
		$c .= $this->addFormInput ('E-mail', 'email', 'emailM', ['mandatory' => 1]);
		$c .= $this->addFormInput ('Zadat odlišnou adresu', 'checkbox', 'useAddressM', []);
		$c .= $this->addFormInput ('Ulice a číslo popisné', 'text', 'streetM');
		$c .= $this->addFormInput ('Obec', 'text', 'cityM');
		$c .= $this->addFormInput ('PSČ', 'text', 'zipcodeM');

		$c.= "</div>";
		$c.= "<div class='col col-6 zus-prihlaska-zz-2'>";
		$c .= "<h4>".'Zákonný zástupce 2'.'</h4>';
		$c .= $this->addFormInput ('Jméno a příjmení', 'text', 'fullNameF');
		$c .= $this->addFormInput ('Telefon', 'text', 'phoneF');
		$c .= $this->addFormInput ('E-mail', 'email', 'emailF');
		$c .= $this->addFormInput ('Zadat odlišnou adresu', 'checkbox', 'useAddressF', []);
		$c .= $this->addFormInput ('Ulice a číslo popisné', 'text', 'streetF');
		$c .= $this->addFormInput ('Obec', 'text', 'cityF');
		$c .= $this->addFormInput ('PSČ', 'text', 'zipcodeF');

		$c.= "</div>";
		$c.= "</div>";


		$c.= "<div class='row pt-3 zus-prihlaska-zdravotni-znevyhodneni'>";
			$c.= "<div class='col col-8'>";
				$c .= "<h4>".'Zdravotní znevýhodnění žáka/žákyně:'.'</h4>';
			$c.= "</div>";
			$c.= "<div class='col col-4'>";
				$c .= $this->addFormInput ('', 'select', 'zdravotniPostizeni', ['select' => ['0' => 'Ne', '1' => 'Ano']]);
			$c.= "</div>";
			$c.= "<div class='col col-12'>";
				$c .= $this->addFormInput ('Popis', 'text', 'zdravotniPostizeniPopis', ['mandatory' => 1]);
			$c.= "</div>";
		$c.= "</div>";

		$c .= "<br><small>Údaje označené * jsou povinné.</small><br>";

		$c .= "
		<div class='mt-3 zus-prihlaska-beru-na-vedomi' style='background-color: rgba(0, 0, 0, .05); border: 1px solid rgba(0,0,0,.25); border-radius: 4px; padding: .5em;'>
		<h4>Beru na vědomí:</h4><br>
		<ul>
			<li>Vzdělávání v Základní umělecké škole upravuje zákon č. 561/2004 Sb., o předškolním, základním, středním, vyšším odborném a jiném vzdělávání v platném znění
				(Školský zákon), vyhláška č. 71/2005 Sb. o základním uměleckém vzdělávání v platném znění, Školní řád ZUŠ Morava a Školní vzdělávací program ZUŠ Morava.
			<li>Základní umělecká škola Morava se řídí platnými zákonnými předpisy souvisejícími se zajištěním výuky a s předpisy o ochraně osobních údajů. Odesláním
					přihlášky souhlasím s ustanoveními dokumentu Zpracování osobních údajů v ZUŠ Morava, uveřejněným na webu www.zusmorava.cz, nebo v kanceláři školy.
			<li>Studium v základní umělecké škole může být ukončeno z těchto důvodů:
				<ol>
					<li>závažné porušení školního řádu
					<li>jestliže byl žák na konci 2. pololetí celkově hodnocen stupněm „neprospěl“ a nebylo mu povoleno opakování ročníku
					<li>jestliže zákonný zástupce nezletilého žáka nebo zletilý žák neuhradil úplatu za vzdělání ve stanoveném termínu
					<li>jestliže o to písemně požádá zákonný zástupce nezletilého žáka nebo zletilý žák
				</ol>
				<li>Beru na vědomí, že zaplatím úplatu za vzdělání (školné) v termínech, které určí škola. (do 31. 8. za 1. pololetí, do 31. 3. za 2. pololetí)
				<li>Dle §22 odst. 3 zákona č. 561/2004 Sb. v platném znění jsou zákonní zástupci dětí a nezletilých žáků povinni informovat školu o změně zdravotní způsobilosti, zdravotních obtížích
						dítěte a jiných závažných skutečnostech, které by mohly mít vliv na průběh vzdělávání.
		</ul>
		</div>
		";


		$c .= "<div class='form-group'><div class='col-sm-offset-2 col-sm-10'><br><button type='submit' class='btn btn-primary'>Odeslat přihlášku</button></div></div>";
		$c .= '</form>';

		$c .= "
			<script>
			var pobockyNaZamerenich = ".json_encode($this->pobocky).";
			var oddeleniNaOborech = ".json_encode($this->oddeleni).";
			document.addEventListener('DOMContentLoaded', function() {
				$('form.form-horizontal').on ('change', 'input, select', function(event) {
					prihlaska(event, $(this));
				});
				if ($('#zdravotniPostizeni').val() != '1')
					$('#zdravotniPostizeniPopis').parent().css({'display': 'none'});
			 }, false);


			function prihlaska(event, element)
			{
				if (element.attr('id') === 'zdravotniPostizeni')
				{
					if (element.val() === '0')
						$('#zdravotniPostizeniPopis').parent().css({'display': 'none'});
					else
						$('#zdravotniPostizeniPopis').parent().css({'display': 'block'});
				}

				nastavitPrihlasku();
			}

			function nastavitPrihlasku()
			{
				let stopStav = 0;
				let stopStavMsg = '';

				if ($('#useAddressM').is(':checked'))
				{
					$('#streetM').prop('disabled', false);
					$('#cityM').prop('disabled', false);
					$('#zipcodeM').prop('disabled', false);
				}
				else
				{
					$('#streetM').prop('disabled', true);
					$('#cityM').prop('disabled', true);
					$('#zipcodeM').prop('disabled', true);

					$('#streetM').val($('#street').val());
					$('#cityM').val($('#city').val());
					$('#zipcodeM').val($('#zipcode').val());
				}

				if ($('#useAddressF').is(':checked'))
				{
					$('#streetF').prop('disabled', false);
					$('#cityF').prop('disabled', false);
					$('#zipcodeF').prop('disabled', false);
				}
				else
				{
					$('#streetF').prop('disabled', true);
					$('#cityF').prop('disabled', true);
					$('#zipcodeF').prop('disabled', true);

					$('#streetF').val($('#street').val());
					$('#cityF').val($('#city').val());
					$('#zipcodeF').val($('#zipcode').val());
				}

				var oborId = parseInt($('#svpObor').val());
				$('#svpOddeleni > option').each(function() {
					var thisOption = $(this);
					const oddeleni = parseInt(thisOption.attr('value'));
					if (oddeleni)
					{
						if (oddeleniNaOborech[oborId] === undefined || oddeleniNaOborech[oborId].indexOf(oddeleni) === -1)
							thisOption.hide();
						else
						{
							thisOption.show();

							if (pobockyNaZamerenich[oddeleni] === undefined || pobockyNaZamerenich[oddeleni].lenght === 0)
							{
								this.disabled = true;
								stopStav = 1;
								if (stopStavMsg !== '')
									stopStavMsg += ', ';
								stopStavMsg += thisOption.text();
							}
							else
								this.disabled = false;
						}
					}
				});
				var oddeleniId = parseInt($('#svpOddeleni').val());
				if (oddeleniNaOborech[oborId] === undefined || oddeleniNaOborech[oborId].indexOf(oddeleniId) === -1)
					$('#svpOddeleni').val('0');


				var zamereniId = parseInt($('#svpOddeleni').val());
				$('#misto > option').each(function() {
					var thisOption = $(this);
					const pobocka = parseInt(thisOption.attr('value'));
					if (pobocka)
					{
						if (pobockyNaZamerenich[zamereniId] === undefined || pobockyNaZamerenich[zamereniId].indexOf(pobocka) === -1)
							this.disabled = true;
						else
							this.disabled = false;
					}
				});

				var mistoId = parseInt($('#misto').val());
				if (pobockyNaZamerenich[zamereniId] === undefined || pobockyNaZamerenich[zamereniId].indexOf(mistoId) === -1)
					$('#misto').val('0');

				if (stopStav)
				{
					$('#stop-stav-info').show().text('U některých studijních zaměření máme bohužel naplněnou kapacitu a nepřijímáme nové žáky: ' + stopStavMsg + '.');
				}
				else
				{
					$('#stop-stav-info').hide().text('');
				}
			}

			nastavitPrihlasku();
			</script>
		";

		return $c;
	}

	public function validate ()
	{
		$this->valid = TRUE;

		$this->checkValidField('firstNameS', 'Jméno není vyplněno');
		$this->checkValidField('lastNameS', 'Příjmení není vyplněno');
		$this->checkValidField('datumNarozeni', 'Datum narození není vyplněno');
		$this->checkValidField('mistoNarozeni', 'Místo narození není vyplněno');
		$this->checkValidField('rodneCislo', 'Rodné číslo není vyplněno');
		$this->checkValidField('street', 'Ulice není vyplněna');
		$this->checkValidField('city', 'Obec není vyplněna');
		$this->checkValidField('zipcode', 'PSČ není vyplněno');
		$this->checkValidField('svpObor', 'Obor není vyplněn');
		$this->checkValidField('svpOddeleni', 'Studijní zaměření není vyplněno');
		$this->checkValidField('skolaNazev', 'Název školy není vyplněn');
		$this->checkValidField('fullNameM', 'Jméno není vyplněno');
		$this->checkValidField('phoneM', 'Telefon není vyplněn');
		$this->checkValidField('emailM', 'E-mail není vyplněn');
		$this->checkValidField('misto', 'Není vybrána pobočka');

		$reCaptchaResponse = $this->app->testPostParam ('webFormReCaptchtaResponse', NULL);
		if ($reCaptchaResponse !== NULL)
		{
			if ($reCaptchaResponse === '')
			{
				$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
				return FALSE;
			}

			$validateUrl = 'https://www.google.com/recaptcha/api/siteverify?secret='.$this->template->pageParams['recaptcha-v3-secret-key'].'&response='.$reCaptchaResponse.'&remoteip='.$_SERVER ['REMOTE_ADDR'];
			$validateResult =  \E10\http_post ($validateUrl, '');
			$validateResultData = json_decode($validateResult['content'], TRUE);
			if ($validateResultData && isset($validateResultData['success']))
			{
				if ($validateResultData['success'])
				{
					if ($validateResultData['score'] < 0.5)
					{
						$this->formErrors ['msg'] = 'Vaše zpráva bohužel vypadá jako SPAM.';
						return FALSE;
					}
					$this->spamScore = strval($validateResultData['score']);
				}
				else
				{
					$this->formErrors ['msg'] = 'Odeslání formuláře se nezdařilo.';
					return FALSE;
				}
			}
		}

		return $this->valid;
	}

	public function checkValidField ($id, $msg)
	{
		if ($id === 'misto')
		{
			$misto = intval($this->app->testPostParam ('misto'));
			if (!$misto)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}
		if ($id === 'svpObor')
		{
			$svpObor = intval($this->app->testPostParam ('svpObor'));
			if (!$svpObor)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}
		if ($id === 'svpOddeleni')
		{
			$svpOddeleni = intval($this->app->testPostParam ('svpOddeleni'));
			if (!$svpOddeleni)
			{
				$this->formErrors [$id] = $msg;
				$this->valid = FALSE;
				return;
			}
		}

		if ($this->app->testPostParam ($id) == '')
		{
			$this->formErrors [$id] = $msg;
			$this->valid = FALSE;
		}
	}

	public function doIt ()
	{
		/** @var \e10pro\zus\TablePrihlasky $tablePrihlasky */
		$tablePrihlasky = $this->app->table('e10pro.zus.prihlasky');

		$newRegistration = [
			'datumPrihlasky' => utils::today(),
			'skolniRok' => $this->data['skolniRok'],

			'svpObor' => intval($this->data['svpObor']),
			'svpOddeleni' => intval($this->data['svpOddeleni']),

			'firstNameS' => str::upToLen($this->data['firstNameS'], 60),
			'lastNameS' => str::upToLen($this->data['lastNameS'], 80),
			'datumNarozeni' => $this->data['datumNarozeni'],
			'rodneCislo' => str::upToLen($this->data['rodneCislo'], 15),
			'mistoNarozeni' => str::upToLen($this->data['mistoNarozeni'], 80),
			'statniPrislusnost' => str::upToLen($this->data['statniPrislusnost'], 80),

			'street' => str::upToLen($this->data['street'], 250),
			'city' => str::upToLen($this->data['city'], 90),
			'zipcode' => str::upToLen($this->data['zipcode'], 20),
			'misto' => $this->data['misto'],

			'skolaTrida' => str::upToLen($this->data['skolaTrida'], 10),
			'skolaNazev' => str::upToLen($this->data['skolaNazev'], 80),

			'fullNameM' => str::upToLen($this->data['fullNameM'], 140),
			'emailM' => str::upToLen($this->data['emailM'], 60),
			'phoneM' => str::upToLen($this->data['phoneM'], 60),
			'useAddressM' => intval($this->data['useAddressM']),
			'streetM' => str::upToLen($this->data['streetM'], 250),
			'cityM' => str::upToLen($this->data['cityM'], 90),
			'zipcodeM' => str::upToLen($this->data['zipcodeM'], 20),

			'fullNameF' => str::upToLen($this->data['fullNameF'], 140),
			'emailF' => str::upToLen($this->data['emailF'], 60),
			'phoneF' => str::upToLen($this->data['phoneF'], 60),
			'useAddressF' => intval($this->data['useAddressF']),
			'streetF' => str::upToLen($this->data['streetF'], 250),
			'cityF' => str::upToLen($this->data['cityF'], 90),
			'zipcodeF' => str::upToLen($this->data['zipcodeF'], 20),

			'zdravotniPostizeni' => intval($this->data['zdravotniPostizeni']),
			'zdravotniPostizeniPopis' => str::upToLen($this->data['zdravotniPostizeniPopis'], 160),

			'webSentDate' => new \DateTime(),

			'docState' => 1000, 'docStateMain' => 0,
		];
		$newNdx = $tablePrihlasky->dbInsertRec($newRegistration);
		$tablePrihlasky->docsLog($newNdx);

		return TRUE;
	}

	public function successMsg ()
	{
		return $this->dictText('Hotovo. Během několika minut Vám pošleme e-mail s potvrzením.');
	}

	protected function nacistPobocky()
	{
		$this->pobocky = [];

		$q = [];
		array_push($q, 'SELECT pobocky.*, oddeleni.[stop] AS oddeleniStop FROM [e10pro_zus_oddeleniPobocky] AS pobocky');
		array_push($q, ' LEFT JOIN [e10pro_zus_oddeleni] AS oddeleni ON pobocky.oddeleni = oddeleni.ndx');
		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['stop'] || $r['oddeleniStop'])
				continue;
			$this->pobocky[$r['oddeleni']][] = $r['pobocka'];
		}
	}
}
