e10client.prototype.widgets.macVs = {
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


e10client.prototype.widgets.macVs.init = function (elementId, localServers) {
	e10.widgets.macVs.gridType = $('#e10-widget-vs-type').val ();
	e10.widgets.macVs.widgetId = elementId;
	e10.widgets.macVs.localServers = localServers;

	$('img.e10-camp, #e10-vs-smart-main-img').error(function(){
		this.src = httpApiRootPath+'/www-root/sc/shipard/ph-image-1920-1080-error.svg';
	});

	$('img.e10-camp, #e10-vs-smart-main-img').load(function(){
		$(this).attr ('data-load-in-progress', '0');
	});

	e10.widgets.macVs.smartMainElement = $('#e10-vs-smart-main');
	e10.widgets.macVs.smartActiveCamera = e10.widgets.macVs.smartMainElement.attr ('data-active-cam');

	if (e10.widgets.macVs.gridType === 'videoArchive')
		e10.widgets.macVs.initArchive();
	else
		e10.widgets.macVs.reloadLive();
};


e10client.prototype.widgets.macVs.reloadLive = function ()
{
	if (e10.widgets.macVs.camerasTimer) {
		clearTimeout(e10.widgets.macVs.camerasTimer);
	}
	for (var si in e10.widgets.macVs.localServers) {
		var ws = e10.widgets.macVs.localServers[si];
		//console.log (ws);
		var camUrl = ws.camerasURL;
		var urlPath = ws.camerasURL + "/cameras?callback=?";
		var jqxhr = $.getJSON(urlPath, function (data) {
			var errorMsgElement = $('#e10-widget-vs-error');
			errorMsgElement.css({'display': 'none'});

			var cntSuccess = 0;
			for (var ii in data) {
				if (!data[ii].image)
					continue;
				var imgElement = $('#e10-camp-' + ii);
				if (imgElement.length === 0)
					continue;

				var picFolder = imgElement.attr('data-folder');

				var picFileName = camUrl + 'imgs/-w960/-q70/' + picFolder + "/" + data[ii].image;
				var origPicFileName = camUrl + '/imgs/' + picFolder + "/" + data[ii].image;

				var cameraId = imgElement.attr ('data-camera');

				if (imgElement.attr ('data-load-in-progress') === '1')
					continue;

				imgElement.attr('data-load-in-progress', '1');
				imgElement.attr("src", picFileName).parent().attr("data-pict", origPicFileName);
				if (e10.widgets.macVs.smartMainElement !== null && cameraId === e10.widgets.macVs.smartActiveCamera)
				{
					if ($('#e10-vs-smart-main-img').attr ('data-load-in-progress') !== '1') {
						$('#e10-vs-smart-main-img').attr('data-load-in-progress', '1');
						$('#e10-vs-smart-main-img').attr('src', origPicFileName);
					}
				}

				if (data[ii].error)
					imgElement.addClass('e10-error');
				else
					imgElement.removeClass('e10-error');

				cntSuccess++;
			}
			if (!cntSuccess)
			{
				e10.widgets.macVs.camerasTimer = 0;
				return;
			}
			e10.widgets.macVs.camerasTimer = setTimeout(e10.widgets.macVs.reloadLive, 3000);
		}).error(function () {
			var errorMsgElement = $('#e10-widget-vs-error');
			errorMsgElement.css({'display': 'flex'});
			e10.widgets.macVs.camerasTimer = setTimeout(e10.widgets.macVs.reloadLive, 10000);
		});
	}
};

e10client.prototype.widgets.macVs.initArchive = function ()
{
	var widget = $('#'+e10.widgets.macVs.widgetId);
	var inputDate = widget.find('input[name="e10-widget-vs-day"]');
	var inputHour = widget.find('input[name="e10-widget-vs-day"]');

	e10.widgets.macVs.archiveDate = inputDate.val();
	e10.widgets.macVs.archiveHour = inputHour.val();

	e10.widgets.macVs.setVideos ();
};

e10client.prototype.widgets.macVs.setVideos = function ()
{
	$('#'+e10.widgets.macVs.widgetId).find ('div.e10-camv').each(function ()
			{
				e10.widgets.macVs.setVideo ($(this));
			}
	);
};

e10client.prototype.widgets.macVs.setMainPicture = function (e)
{
	var cameraId = e.attr ('data-camera');
	e10.widgets.macVs.smartActiveCamera = cameraId;
	$('#e10-vs-smart-main-img').attr ('src', e.attr('src'));

	$('#e10-vs-smart-main-img').parent().find('.e10-cam-sensor-display').remove();
	if (e.attr ('data-badges-code') !== undefined)
	{
		var badges = b64DecodeUnicode(e.attr('data-badges-code'));
		$('#e10-vs-smart-main-img').parent().append(badges);
	}
};

e10client.prototype.widgets.macVs.setVideo = function (e)
{
	var camUrl = e.attr ('data-cam-url');
	var cameraId = e.attr ('data-camera');
	var bfn = e.attr ('data-bfn');

	var videoFileName = cameraId + '-'+e10.widgets.macVs.archiveDate+'-' + e10.widgets.macVs.archiveHour + '.mp4';
	var posterFileName = cameraId + '-'+e10.widgets.macVs.archiveDate+'-' + e10.widgets.macVs.archiveHour + '.jpg';

	var dateSlashes = e10.widgets.macVs.archiveDate.split('-').join('/');
	var videoUrl = camUrl + 'cameras/video-archive/' + e10.widgets.macVs.archiveDate + '/' + bfn + '.mp4';
	var posterUrl = camUrl + 'cameras/video-archive/' + e10.widgets.macVs.archiveDate + '/' + bfn + '.jpg';

	var c = '';

	c += "<video controls style='width: 100%;' preload='none' poster='"+posterUrl+"' src='"+videoUrl+"'>"; //
	//c += "<source src='"+videoUrl+"' type='video/mp4'>";
	c += "</video>";

	e.empty().html (c);
};
