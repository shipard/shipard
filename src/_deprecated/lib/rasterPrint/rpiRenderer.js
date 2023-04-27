const
	puppeteer = require('puppeteer-core'),
	process = require('process'),
	fs = require('fs');


// -- load config file
const args = process.argv.splice(2);
const optsFileName = args[0];
const opts = JSON.parse(fs.readFileSync(optsFileName).toString());


// -- do it
(async () => {
	let browser = null;

	if ('wsEndpointUrl' in opts)
	{
		browser = await puppeteer.connect({browserWSEndpoint: opts['wsEndpointUrl']});
	}
	else {
		let launchOptions = {};
		if ('browserExecutablePath' in opts)
			launchOptions.executablePath = opts['browserExecutablePath'];

		browser = await puppeteer.launch(launchOptions);
	}

	let imgOptions = {
		path: opts['dstFileName'],
		displayHeaderFooter: false,
		fullPage: true
	};

	let vpOptions = {
		width: 696,
		height:80,
		deviceScaleFactor: 1
	};
	if (opts['pdfOptions']['vpHeight'] !== undefined)
		vpOptions.height = parseInt(opts['pdfOptions']['vpHeight']);
	if (opts['pdfOptions']['vpWidth'] !== undefined)
		vpOptions.width = parseInt(opts['pdfOptions']['vpWidth']);

	const page = await browser.newPage();
	await page.setViewport(vpOptions);

	await page.goto(opts['url']/*, {waitUntil: 'networkidle0'}*/);
	await page.screenshot(imgOptions);

	if ('wsEndpointUrl' in opts) {
		await page.close();
		browser.disconnect();
	}
	else {
		await browser.close();
	}
})();

