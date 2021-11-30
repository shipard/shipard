<?php

namespace ui\mobile;


/**
 * Class Maps
 * @package mobileui
 */
class Maps extends \Shipard\UI\OldMobile\PageObject
{
	public function createContentCodeInside ()
	{
		$c = "
		<script type='text/javascript'>

		function loadScript() {
			if (googleMapsApi)
				return;
			var script = document.createElement('script');
			script.type = 'text/javascript';
			script.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyD3vQ9ZMqd9YrNiRUEuQTA1qGsupBgNT8M&sensor=false' +
					'&signed_in=false&callback=initialize';
			document.body.appendChild(script);

			googleMapsApi = 1;
		}
		function loadScript2() {
			if (googleMapsApi === 2)
				return;

			var script2 = document.createElement('script');
			script2.type = 'text/javascript';
			script2.src = e10.httpServerRoot+'/e10-modules/e10/server/js/e10gmaps.js';
			document.body.appendChild(script2);

			googleMapsApi = 2;
		}
    function initialize() {
    		loadScript2();
        var mapOptions = {
            mapTypeId: google.maps.MapTypeId.ROADMAP
        };
        var me = document.getElementById('mapcanvas');
		    var map = new google.maps.Map(me, mapOptions);

        var url = '/api/call/locshare.client.getMap';
        e10.server.get (url, function(data) {
            setGMapsUsers (map, data.mapUsers);
        });
		}
    $(function () {if (googleMapsApi) setTimeout(initialize, 100); else loadScript();});
</script>

<div id='mapcanvas' style='position: absolute; margin-top: 48px; top: 0px; left: 0px; width: 100%; height: 100%;'>
mapa se načítá, čekejte prosím...
</div>
		";

		return $c;
	}

	public function title1 ()
	{
		return 'Mapa';
	}

	public function leftPageHeaderButton ()
	{
		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0'], 'backButton' => 1];
		return $lmb;
	}
}
