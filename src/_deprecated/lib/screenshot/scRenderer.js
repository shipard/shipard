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

	let scOptions = {
		path: opts['dstFileName'],
	};

	let vpOptions = {
		width: 2880,
		height:2160,
		deviceScaleFactor: 1
	};

	if (opts['scOptions']['vpHeight'] !== undefined)
		vpOptions.height = parseInt(opts['scOptions']['vpHeight']);
	if (opts['scOptions']['vpWidth'] !== undefined)
		vpOptions.width = parseInt(opts['scOptions']['vpWidth']);

	const page = await browser.newPage();
	await page.setViewport(vpOptions);

	await page.goto(opts['url']/*, {waitUntil: 'networkidle0'}*/);

	//const pageInfo = await page.$eval('#shp-sc-page-info-result', el => el.value);
	const pageInfo = await page.evaluate(() => {
		const element = document.querySelector('#shp-sc-page-info-result')
		if (element) {
			return element.value
		}
		return '';
	});
	fs.writeFileSync(opts['dstFileNameInfo'], pageInfo);

	await page.screenshot(scOptions);

	if ('wsEndpointUrl' in opts) {
		await page.close();
		browser.disconnect();
	}
	else {
		await browser.close();
	}
})();

