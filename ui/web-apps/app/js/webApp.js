
/*
if ('serviceWorker' in navigator) {
	console.log("Will the service worker register?");
	navigator.serviceWorker.register('/sw.js')
		.then(function(reg){
			console.log("Yes, it did.");
		}).catch(function(err) {
		console.log("No it didn't. This happened: ", err)
	});
}
*/


// -- key/tag login form - disable saving passwords
$(function()
{
	$('#authKey').hide().show().focus().prop('type', 'password');
	$('#authKey').on('focus', function()
	{
		$(this).prop('type', 'password');
	});
	$('#e10-lf-authKey').on('submit', function()
	{
		$('#authKey').hide().prop('type', 'text');
	});

	wssInit();
	initMQTT ();
});
