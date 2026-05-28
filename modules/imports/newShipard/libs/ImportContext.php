<?php

namespace imports\newShipard\libs;

final class ImportContext
{
	public function __construct(
		public readonly \Shipard\CLI\Application $app,
		public readonly ImportConfig $config,
		public readonly HttpClient $httpClient,
		public readonly LocalIdMap $idMap,
	) {}
}
