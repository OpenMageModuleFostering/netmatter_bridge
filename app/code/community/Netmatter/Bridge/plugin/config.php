<?php
	//The url that the connector will try to communicate to
	$netmatter_bridge_config['bridge_callback'] = 'https://test/callback.php';

	//Queue settings
	$netmatter_bridge_config['queue_dir'] = Mage::getBaseDir('var') . '/bridge/queue'; // Outgoing message queue directory

	//The log settings
	$netmatter_bridge_config['log_type'] = 'files'; //screen|array|file|files|null
	$netmatter_bridge_config['log_file'] = ''; //When logger is file, the filename to write logs to
	$netmatter_bridge_config['log_dir'] = Mage::getBaseDir('var') . '/bridge/log'; //When logger is RotatingFiles, the dir to use
