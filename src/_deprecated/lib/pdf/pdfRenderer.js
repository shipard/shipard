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

	let pdfOptions = {
		path: opts['dstFileName'],
		format: opts['pdfOptions']['paperFormat'],
		printBackground: true,
		landscape: false,
		displayHeaderFooter: false,
		margin: {
			top: opts['pdfOptions']['paperMarginTop'],
			bottom: opts['pdfOptions']['paperMarginBottom'],
			left: opts['pdfOptions']['paperMarginLeft'],
			right: opts['pdfOptions']['paperMarginRight']
		}
	};

	if ('headerTemplate' in opts['pdfOptions'])
	{
		pdfOptions.headerTemplate = opts['pdfOptions']['headerTemplate'];
		pdfOptions.displayHeaderFooter = true;
	}
	if ('footerTemplate' in opts['pdfOptions'])
	{
		pdfOptions.footerTemplate = opts['pdfOptions']['footerTemplate'];
		pdfOptions.displayHeaderFooter = true;
	}

	if (opts['pdfOptions']['paperOrientation'] === 'landscape')
		pdfOptions.landscape = true;

	const page = await browser.newPage();
	await page.goto(opts['url']/*, {waitUntil: 'networkidle0'}*/);
	await page.pdf(pdfOptions);

	if ('wsEndpointUrl' in opts) {
		await page.close();
		browser.disconnect();
	}
	else {
		await browser.close();
	}
})();

