<?php

return [
	'routes' => [
		[
			'name' => 'transfer#transfer',
			'url'  => 'ajax/transfer.php',
			'verb' => 'POST',
		],
		[
			'name' => 'transfer#status',
			'url'  => 'ajax/status.php',
			'verb' => 'GET',
		],
		[
			'name' => 'transfer#probe',
			'url'  => 'ajax/probe.php',
			'verb' => 'GET',
		],
	],
];
