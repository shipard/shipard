
function loadScriptSync(src, callback, args) {
	var s, r, t;
	r = false;
	s = document.createElement('script');
	s.type = 'text/javascript';
	s.src = src;
	if (typeof(callback) === 'function') {
		s.onload = s.onreadystatechange = function() {
			if (!r && (!this.readyState || this.readyState === 'complete')) {
				r = true;
				callback(args);
			}
		};
	};
	document.body.appendChild(s);
}


function googleMapsLoadScripts(callbackFunc)
{
	if (googleMapsApi === 0) {
		loadScriptSync ('https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/markerclusterer.js', googleMapsLoadScripts, callbackFunc);
		googleMapsApi++;
		return;
	}

	if (googleMapsApi === 1) {
		loadScriptSync ('https://cdnjs.cloudflare.com/ajax/libs/OverlappingMarkerSpiderfier/1.0.3/oms.min.js', googleMapsLoadScripts, callbackFunc);
		googleMapsApi++;
		return;
	}

	if (googleMapsApi === 2) {
		loadScriptSync ('https://maps.googleapis.com/maps/api/js?key=AIzaSyD3vQ9ZMqd9YrNiRUEuQTA1qGsupBgNT8M', googleMapsLoadScripts, callbackFunc);
		googleMapsApi++;
		return;
	}

	if (googleMapsApi === 3) {
		loadScriptSync (httpApiRootPath+'/e10-modules/e10/server/js/e10gmaps.js?v=2', googleMapsLoadScripts, callbackFunc);
		googleMapsApi++;
		return;
	}

	callbackFunc();
}


function googleMapsInitialize(mapElementId) {
	var mapOptions = {
		mapTypeId: google.maps.MapTypeId.ROADMAP
	};

	var me = $('#'+mapElementId);
	me.width(me.parent().width());

	var map = new google.maps.Map(me[0], mapOptions);
	google.maps.event.addListener(map, 'tilesloaded', function() {$('#'+mapElementId+'-loading').detach();});

	var infoWindow = new google.maps.InfoWindow();

	var oms = new OverlappingMarkerSpiderfier(map, {markersWontMove: true, markersWontHide: true});
	oms.addListener('click', function(marker) {
		infoWindow.setContent(marker.title);
		infoWindow.open(map, marker);
	});


	var mapDefId = me.attr('data-map-def-id');

	var url = httpApiRootPath+'/api/call/e10pro.wkf.getMap/'+mapDefId;
	$.getJSON (url, function(data) {
		setGMapsUsers (map, data.mapPins, oms);
	}).error(function() {alert('error: content not loaded (' + url + ')');});
}

function initGMap (mapElementId)
{
	if (googleMapsApi)
	{
		setTimeout(function(){googleMapsInitialize(mapElementId);}, 10);
	}
	else
	{
		googleMapsLoadScripts(function(){googleMapsInitialize(mapElementId);});
	}
}
