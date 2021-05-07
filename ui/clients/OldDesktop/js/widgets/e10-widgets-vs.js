e10client.prototype.widgets.vs = {
	camerasTimer: 0,
	widgetId: '',
	gridType: '',
	gridMode: '',
	smartActiveCamera: '',
	smartMainElement: null,
	archiveDate: '',
	archiveHour: '',
	localServers: null
};


e10client.prototype.widgets.vs.init = function (elementId, localServers) {
	e10.widgets.vs.gridType = $('#e10-widget-vs-type').val ();
	e10.widgets.vs.gridMode = $('#e10-widget-vs-mode').val ();
	e10.widgets.vs.widgetId = elementId;
	e10.widgets.vs.localServers = localServers;

	if (e10.widgets.vs.gridMode === 'smart')
	{
		e10.widgets.vs.smartMainElement = $('#e10-vs-smart-main');
		e10.widgets.vs.smartActiveCamera = e10.widgets.vs.smartMainElement.attr ('data-active-cam');
	}

	if (e10.widgets.vs.gridType === 'live')
		e10.widgets.vs.reloadLive();
	else
		e10.widgets.vs.initArchive();
};


e10client.prototype.widgets.vs.reloadLive = function ()
{
	if (e10.widgets.vs.camerasTimer) {
		clearTimeout(e10.widgets.vs.camerasTimer);
	}
	for (var si in e10.widgets.vs.localServers) {
		var ws = e10.widgets.vs.localServers[si];
		//console.log (ws);
		var camUrl = ws.camerasURL;
		var urlPath = ws.camerasURL + "/cameras?callback=?";
		var jqxhr = $.getJSON(urlPath, function (data) {
			var cntSuccess = 0;
			for (var ii in data) {
				if (!data[ii].image)
					continue;
				var picFileName = camUrl + 'imgs/-w960/-q70/' + ii + "/" + data[ii].image;
				var origPicFileName = camUrl + '/imgs/' + ii + "/" + data[ii].image;

				var imgElement = $('#e10-camp-' + ii);
				if (imgElement.length === 0)
					continue;

				var cameraId = imgElement.attr ('data-camera');

				if (imgElement.hasClass('zoomed'))
					imgElement.attr("src", origPicFileName).parent().attr("data-pict", origPicFileName);
				else
					imgElement.attr("src", picFileName).parent().attr("data-pict", origPicFileName);

				if (e10.widgets.vs.smartMainElement !== null && cameraId === e10.widgets.vs.smartActiveCamera)
				{
					$('#e10-vs-smart-main-img').attr ('src', origPicFileName);
				}

				if (data[ii].error)
					imgElement.addClass('e10-error');
				else
					imgElement.removeClass('e10-error');

				cntSuccess++;
			}
			if (!cntSuccess)
			{
				e10.widgets.vs.camerasTimer = 0;
				return;
			}
			e10.widgets.vs.camerasTimer = setTimeout(e10.widgets.vs.reloadLive, 3000);
		}).error(function () {
			alert("error XobnovaKamer: content not loaded (" + urlPath + ")");
		});
	}
};

e10client.prototype.widgets.vs.initArchive = function ()
{
	e10.widgets.vs.archiveDate = $('#e10-widget-vs-day').val();
	e10.widgets.vs.archiveHour = $('#e10-widget-vs-hour').val();
	e10.widgets.vs.setVideos ();
};

e10client.prototype.widgets.vs.setVideos = function ()
{
	$('#'+e10.widgets.vs.widgetId).find ('div.e10-camv').each(function ()
			{
				e10.widgets.vs.setVideo ($(this));
			}
	);
};

e10client.prototype.widgets.vs.setMainPicture = function (e)
{
	var cameraId = e.attr ('data-camera');
	e10.widgets.vs.smartActiveCamera = cameraId;
	$('#e10-vs-smart-main-img').attr ('src', e.attr('src'));
};

e10client.prototype.widgets.vs.zoomPicture = function (e)
{
	if (e.hasClass('zoomed'))
	{
		e.removeClass('zoomed');
		e.parent().parent().removeClass('zoomed');
	}
	else
	{
		e.addClass('zoomed');
		e.parent().parent().addClass('zoomed');
	}
};

e10client.prototype.widgets.vs.zoomMainPicture = function (e)
{
	var primaryBox = e.parent().find('div.e10-wvs-smart-primary-box');

	if (e.hasClass('zoomed'))
	{
		e.removeClass('zoomed');
		primaryBox.removeClass('zoomed');
	}
	else
	{
		e.addClass('zoomed');
		primaryBox.addClass('zoomed');
	}
};

e10client.prototype.widgets.vs.setVideo = function (e)
{
	var camUrl = e.attr ('data-cam-url');
	var cameraId = e.attr ('data-camera');
	var bfn = e.attr ('data-bfn');

	var videoFileName = cameraId + '-'+e10.widgets.vs.archiveDate+'-' + e10.widgets.vs.archiveHour + '.mp4';
	var posterFileName = cameraId + '-'+e10.widgets.vs.archiveDate+'-' + e10.widgets.vs.archiveHour + '.jpg';

	var dateSlashes = e10.widgets.vs.archiveDate.split('-').join('/');
	var videoUrl = camUrl + 'video/archive/' + e10.widgets.vs.archiveDate + '/' + bfn + '.mp4';
	var posterUrl = camUrl + 'video/archive/' + e10.widgets.vs.archiveDate + '/' + bfn + '.jpg';

	var c = '';

	c += "<video controls style='width: 100%;' preload='none' poster='"+posterUrl+"' src='"+videoUrl+"'>"; //
	//c += "<source src='"+videoUrl+"' type='video/mp4'>";
	c += "</video>";

	e.empty().html (c);
};

e10client.prototype.widgets.vs.setDay = function (e)
{
	e10.widgets.vs.archiveDate = e.val();
	e10.widgets.vs.setVideos();
};

e10client.prototype.widgets.vs.setHour = function (e)
{
	e10.widgets.vs.archiveHour = e.val();
	e10.widgets.vs.setVideos();
};


