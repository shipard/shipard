#! /usr/bin/python3
# -*- coding: utf-8 -*-

import argparse
import os
import json
from PyPDF4 import PdfFileReader, PdfFileWriter
from PyPDF4.generic import DecodedStreamObject, NameObject, DictionaryObject, createStringObject, ArrayObject

def appendAttachment(myPdfFileWriterObj, fname, fdata):
	file_entry = DecodedStreamObject()
	file_entry.setData(fdata)
	file_entry.update({NameObject("/Type"): NameObject("/EmbeddedFile")})

	efEntry = DictionaryObject()
	efEntry.update({ NameObject("/F"):file_entry })

	filespec = DictionaryObject()
	filespec.update({NameObject("/Type"): NameObject("/Filespec"),NameObject("/F"): createStringObject(fname),NameObject("/EF"): efEntry})

	if "/Names" not in myPdfFileWriterObj._root_object.keys():
		embeddedFilesNamesDictionary = DictionaryObject()
		embeddedFilesNamesDictionary.update({NameObject("/Names"): ArrayObject([createStringObject(fname), filespec])})

		embeddedFilesDictionary = DictionaryObject()
		embeddedFilesDictionary.update({NameObject("/EmbeddedFiles"): embeddedFilesNamesDictionary})
		myPdfFileWriterObj._root_object.update({NameObject("/Names"): embeddedFilesDictionary})
	else:
		myPdfFileWriterObj._root_object["/Names"]["/EmbeddedFiles"]["/Names"].append(createStringObject(fname))
		myPdfFileWriterObj._root_object["/Names"]["/EmbeddedFiles"]["/Names"].append(filespec)


if __name__ == "__main__":
	parser = argparse.ArgumentParser()
	parser.add_argument('config', help='Path to config file.')
	args = parser.parse_args()
	with open(args.config) as f:
			config = json.load(f)
	fr = PdfFileReader(config['dstFileName'],'rb')
	fw = PdfFileWriter()
	fw.appendPagesFromReader(fr)
	for key in config['pdfInfo']:
		fw.addMetadata({key: config['pdfInfo'][key]})

	for oneAttachment in config['pdfAttachments']:
		with open(oneAttachment['srcFileName'], 'rb') as oneAttachmentFile:
			attachmentData = oneAttachmentFile.read()
			appendAttachment(fw, oneAttachment['attFileName'], attachmentData)

	with open(config['dstFileName'] + '.finalized.pdf','wb') as file:
		fw.write(file)
