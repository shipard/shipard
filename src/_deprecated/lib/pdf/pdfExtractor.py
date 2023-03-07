#! /usr/bin/python3
# -*- coding: utf-8 -*-

import argparse
import os
import json
import time
from PyPDF4 import PdfFileReader, PdfFileWriter

def getAttachments(reader):
	catalog = reader.trailer["/Root"]
	attachments = {}
	if ('/Names' in catalog and '/EmbeddedFiles' in catalog['/Names'] and '/Names' in catalog['/Names']['/EmbeddedFiles']):
		fileNames = catalog['/Names']['/EmbeddedFiles']['/Names']
		for f in fileNames:
			if isinstance(f, str):
				name = f
				dataIndex = fileNames.index(f) + 1
				fDict = fileNames[dataIndex].getObject()
				fData = fDict['/EF']['/F'].getData()
				attachments[name] = fData
	return attachments


if __name__ == "__main__":
	parser = argparse.ArgumentParser()
	parser.add_argument('fileName', help='Path to pdf file.')
	args = parser.parse_args()

	fr = PdfFileReader(args.fileName,'rb')
	dictionary = getAttachments(fr)

	fileNamePrefix = str(time.time())
	pdfInfo = {'attachments': []}
	for fName, fData in dictionary.items():
		fileInfo = {'baseFileName': fName, 'fullFileName': 'tmp/'+fileNamePrefix+'_'+fName}
		with open(fileInfo['fullFileName'], 'wb') as outfile:
			outfile.write(fData)
		pdfInfo['attachments'].append(fileInfo)

	tt = json.dumps(pdfInfo)
	with open(args.fileName+'.json', 'wb') as outfile:
		outfile.write(tt.encode())
