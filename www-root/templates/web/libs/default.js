$(function() {
	e10w_responsiveVideo();
	e10w_embeddedMaps();
});

function e10w_responsiveVideo() {
	var $allVideos = $("iframe[src^='https://player.vimeo.com'], iframe[src^='https://www.youtube.com'], object, embed");

	$allVideos.each(function() {
		$(this)
			.attr('data-aspectRatio', this.height / this.width)
			.removeAttr('height')
			.removeAttr('width');
	});

	$(window).resize(function() {
		$allVideos.each(function() {
			var $el = $(this);
			var newWidth = $el.parent().width();
			$el.width(newWidth).height(newWidth * $el.attr('data-aspectRatio'));
		});

	}).resize();
}

function e10w_embeddedMaps() {
	var mapElement = $('div.e10-embedd-map');
	if (!mapElement.length)
		return;

	var script = document.createElement('script');
	script.src = '//maps.googleapis.com/maps/api/js?key='+googleMapsApiKey+'&callback=e10w_initEmbeddedMap';
	document.body.appendChild(script);
}

function e10w_initEmbeddedMap() {
	var mapId = '8767';

	var map;
	var bounds = new google.maps.LatLngBounds();
	var mapOptions = {mapTypeId: 'roadmap'};
	map = new google.maps.Map(document.getElementById('map_canvas_'+mapId), mapOptions);
	map.setTilt(45);
	var infoWindow = new google.maps.InfoWindow(), marker, i;
	for( i = 0; i < markers.length; i++ ) {
		var position = new google.maps.LatLng(markers[i][1], markers[i][2]);
		bounds.extend(position);
		marker = new google.maps.Marker({
			position: position,
			map: map,
			title: markers[i][0]
		});

		google.maps.event.addListener(marker, 'click', (function(marker, i) {
			return function() {
				infoWindow.setContent(infoWindowsContent[i][0]);
				infoWindow.open(map, marker);
			}
		})(marker, i));
	}

	map.fitBounds(bounds);
}

