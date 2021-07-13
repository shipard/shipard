function callWebAction (data, f, errorFunction)
{
	var fullUrl =  httpApiRootPath + '/call-web-action';

	var options = {
		type: 'POST',
		url: fullUrl,
		success: (f !== undefined) ? f : callWebActionSucceeded,
		data: JSON.stringify(data),
		dataType: 'json',
		error: (errorFunction != undefined) ? errorFunction : callWebActionFailed
	};
	$.ajax(options);
}

function callWebActionSucceeded (data)
{
	webActionRun(null, data);
}

function callWebActionFailed(data)
{
	console.log("----ERROR-CALL--WEB--ACTION---");
	console.log (data);
}
