

function nacistZAresu (e, srcId)
{
	//alert ('ares! ' + srcId);

	var originalButtonContent = e.html ();
	e.html ("<i class='icon-repeat rotate'></i> Zjišťuji informace...").css('cursor','wait');
	$('body').css('cursor','wait');
	var ic = $('#' + srcId).val ();

	urlPath = httpApiRootPath + "/api/call/e10pro.hosting.client.nacistZAresu?ic=" + ic;
	var jqxhr = $.getJSON(urlPath, function(data) {
		//alert (JSON.stringify (data));
		$('body').css('cursor','default');
		e.css('cursor','default').html (originalButtonContent);

		$('#regOrgName').val (data.ares.name).focus ();
		$('#regOrgStreet').val (data.ares.street);
		$('#regOrgCity').val (data.ares.city);
		$('#regOrgZIPCode').val (data.ares.zip);

  }).error(function() {$('body').css('cursor','default');alert("error: content not loaded (" + urlPath + ")");});

}