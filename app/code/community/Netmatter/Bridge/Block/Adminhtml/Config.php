<?php

class Netmatter_Bridge_Block_Adminhtml_Config extends Mage_Core_Block_Template
{
	/**
	* Retrieves the integration's configuration
	*/
	public function getConfig()
	{
		require ('./app/code/local/Netmatter/Bridge/plugin/bridge.php');

		return $netmatter_bridge_config;
	}
}
