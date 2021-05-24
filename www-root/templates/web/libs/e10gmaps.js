function ImageMarker( options ) {
	this.setValues( options );

    var title = this.get('title');

	this.$inner = $('<div>').css({
		position: 'relative',
		left: '-50%', top: '-100%',
		fontSize: '1px',
		lineHeight: '1px'
	}).attr ('title', title).addClass('e10-map-marker');

	this.$div = $('<div>')
		.append( this.$inner )
		.css({
			position: 'absolute',
			display: 'none'
		});

    this.getPosition = function() {
        return this.get('position');
    };

}



ImageMarker.prototype = new google.maps.OverlayView;

ImageMarker.prototype.onAdd = function() {
	$( this.getPanes().overlayMouseTarget ).append( this.$div );
};

ImageMarker.prototype.onRemove = function() {
	this.$div.remove();
};

ImageMarker.prototype.draw = function() {
	var marker = this;
	var projection = this.getProjection();
	var position = projection.fromLatLngToDivPixel( this.get('position') );

	var image = this.get('image');

	if (image !== null)
	{
		this.$div.css({
			left: position.x,
			top: position.y,
			display: 'block',
            width: '32px',
			height: '32px'
		});

		this.$inner
			.html('<img src="' + image + '" style="width:100%;"/>')
			.click(function (event) {
				var events = marker.get('events');
				events && events.click(event);
			});
	}
	else
	{
		this.$div.css({
			left: position.x,
			top: position.y,
			display: 'block',
            //width: '32px',
			height: '32px'
		});

		this.$inner
			.html('<span>'+this.get('nick')+'</span>')
			.css({
				"font-size": "14px", height: '24px', //width: '38px',
				"padding-top": '10px', "text-align": 'center'
			});
	}
};


function setGMapsUsers (map, users, oms)
{
	var markers = [];

    if (users.length === 0)
    {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (position) {
                initialLocation = new google.maps.LatLng(position.coords.latitude, position.coords.longitude);
                map.setZoom (10);
                map.setCenter(initialLocation);
            });
        }
        return;
    }
	var bounds = new google.maps.LatLngBounds ();

	for (var i in users)
	{
		var u = users[i];

		var position = new google.maps.LatLng(u.lat, u.lon);
		var acc = parseInt(u.acc);

		if (acc > 5) {
			var circle = new google.maps.Circle({
				center: position,
				radius: acc,
				map: map,
				fillColor: '#c6c6ff',
				strokeWeight: 1,
				fillOpacity: 0.4,
				strokeColor: '#c6c6ff',
				strokeOpacity: .8
			});
			bounds.union(circle.getBounds());
		}

		if (0) {
            var markerData = {
                //map: map,
                position: position,
                title: u.title,
                nick: u.nick,
                image: u.image
            };

            var marker = new ImageMarker(markerData);
            markers.push(marker);
            oms.addMarker(marker);
            bounds.extend(marker.position);
        }
        else
		{
            var pin = new google.maps.Marker({
                map: map,
                position: position,
                title: u.title,
				'label': '■'
            });
            markers.push (pin);
            oms.addMarker(pin);
            //markerCluster.addMarker(pin);
            bounds.extend(pin.position);
		}
	}

	map.fitBounds (bounds);

    var markerCluster = new MarkerClusterer(map, markers,
        {imagePath: 'https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/m', maxZoom: 15});


}

function setGMapsWay (map, geoData)
{
    var bounds = new google.maps.LatLngBounds ();
    var path = new google.maps.MVCArray();
    var poly = new google.maps.Polyline({ map: map });

    if (geoData.shipardHistory !== undefined) {
        var positions = geoData.shipardHistory;
        for (var i in positions) {
            var u = positions[i];

            var position = new google.maps.LatLng(u.lat, u.lon);

            if (path.getLength() === 0) {
                path.push(position);
                poly.setPath(path);
            } else {
                path.push(position);
            }
            var marker = new google.maps.Marker({
                map: map,
                position: position,
                title: u.title,
                icon: 'https://maps.gstatic.com/intl/en_us/mapfiles/markers2/measle_blue.png'
            });
            bounds.extend(position);
        }
    }
    map.fitBounds (bounds);
}
