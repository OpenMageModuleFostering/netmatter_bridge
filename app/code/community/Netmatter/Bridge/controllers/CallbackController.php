<?php
/**
* This file contains an callback controler that listens for
* requests from the Bridge and dispatches them to
*
* @category Netmatter
* @package Netmatter_Bridge
* @author Netmatter Team
* @copyright Copyright (c) 2008-2014 Netmatter Ltd. (http://www.netmatter.co.uk)
* @see Netmatter/etc/config.xml For events that are captured
*/
class Netmatter_Bridge_CallbackController extends Mage_Core_Controller_Front_Action
{
	/** @var Netmatter_Bridge_Bridge */
	private $bridge;

	/** @var Netmatter_Bridge_Helper_Data */
	private $functions;

	/**
	* This method can be accessed by hitting
	*
	* Url:  /netmatter/callback
	*
	* It expects POST data from the bridge, and can internally redirect
	* to the correct action.
	*
	* @return void
	*/
	public function indexAction()
	{
		//Include helper functions
		$this->functions = Mage::helper('bridge/data');

		//Check if integration is enabled
		if (!$this->functions->isEnabled())
		{
			echo '{"errors": "Plugin not enabled"}';
			return;
		}

		Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

		//Include standard plugin
		$bridge = $this->functions->initBridge();

		//Define callback functions
		$bridge->registerCallback('product_stock_modified',	array($this, 'callbackBridgeProductStockModified'));
		$bridge->registerCallback('order_status_modified',	array($this, 'callbackBridgeOrderStatusModified'));
		$bridge->registerCallback('product_modified',		array($this, 'callbackBridgeProductModified'));
		$bridge->registerCallback('configuration_get',		array($this, 'callbackBridgeConfigurationGet'));

		//Listen for requests
		$bridge->listen();
	}

	/**
	* Callback method that handles the Product Stock Modified event
	*
	* It updates the quantities of the given product
	*
	* @param array $product an array containing id and stock
	* @return empty array on success, an array containing the errors on failure
	*/
	public function callbackBridgeProductStockModified(array $product)
	{
		//Retrieve product ids based on given sku
		$ids = $this->functions->getProductIdsBySku($product['sku']);

		//Make checks
		$count_ids = count($ids);
		if ($count_ids > 1)
		{
			return array('numfound' => count($ids));
		}

		//Update product stock
		$this->functions->setProductStockById($ids[0], $product['stock']);

		//Success
		return array();
	}

	/**
	* Callback method that handles the Order status modified event
	*
	* It updates the status of the given order
	*
	* @param array $order an array containing id and statusId
	* @return empty array on success, an array containing the errors on failure
	*/
	public function callbackBridgeOrderStatusModified(array $order)
	{
		$mg_order = Mage::getModel("sales/order")->load($order['id']);

		//Check if order exists
		if ($mg_order->getId() === null)
		{
			return array('numfound' => 0);
		}

		$tracking_codes = array();

		if (isset($order['shipments']))
		{
			foreach ($order['shipments'] AS $key => $shipment)
			{
				if (!isset($shipment['shippedOn'])) //Check for the shippedOn flag
				{
					unset($order['shipments'][$key]);
					continue;
				}

				$tracking_codes[$shipment['shippingMethod']] = $shipment['reference'];
			}

			if (count($order['shipments']) > 0)
			{
				$this->functions->syncShipments($mg_order, $order['shipments']);
			}
		}

		//Update order's status
		$this->functions->setOrderStatus($mg_order, $order['statusId']);

		//Set order's tracking codes
		if (count($tracking_codes) > 0)
		{
			$shipment_hashes = array();
			foreach ($order['shipments'] AS $shipment)
			{
				if (!isset($shipment['reference']))
				{
					continue;
				}

				$shipment_hashes[$shipment['reference']] = $this->functions->calculateShipmentHash($shipment);
			}

			$this->functions->setOrderTrackingCodes($mg_order, $tracking_codes, $shipment_hashes);
		}

		//Success
		return array();
	}

	/**
	* Callback method that handles the Product Modified event
	*
	* It updates the given product
	*
	* @param array $product an array containing product details
	* @return empty array on success, an array containing the errors on failure
	*/
	public function callbackBridgeProductModified(array $product)
	{
		//Retrieve product ids based on given sku
		$ids = $this->functions->getProductIdsBySku($product['sku']);

		//Make checks
		$count_ids = count($ids);
		if ($count_ids > 1)
		{
			return array('numfound' => count($ids));
		}

		//Retrieve available websites
		$websites = $this->functions->getWebsites();
		$website_ids = array_keys($websites);

		//Linked key is present, so linked is true
		if (!isset($product['linked']))
 		{
			foreach ($websites as $website_id => $store_id)
			{
				$product['linked'][$website_id] = 'Yes';
			}
		}

		$linked_websites = array();
		foreach ($product['linked'] as $website_id =>  $value)
		{
			if (!in_array($website_id, $website_ids)) //Website not exists
			{
				continue;
			}

			if ($product['linked'][$website_id] === 'Yes')
			{
				$linked_websites[$websites[$website_id]] = $website_id;
			}
		}

		//Check if linked is active for at least one website. If not we should not continue
		if (count($linked_websites) === 0)
		{
			return null;
		}

		//Check if product exists
		if ($count_ids === 1)
		{
			$product['id'] = $ids[0];
		}
		else //Product not exists
		{
			//Check the product
			$product['id'] = $this->createProduct($product);
		}

		$mg_product = $this->functions->getProduct($product['id']);

		$sync_product_categories = isset($product['categories']) && is_array($product['categories']);
		$sync_product_images = isset($product['images']) && is_array($product['images']);

		//Make sure that product is linked on all given websites
		$product_website_ids = $mg_product->getWebsiteIds();
		if ($sync_product_categories)
		{
			$mg_product->getCategoryIds(); //Load categories to product
		}
		if ($sync_product_images)
		{
			$mg_product->getResource()->getAttribute('media_gallery')->getBackend()->afterLoad($mg_product);
		}
		$mg_product_orig_data = $mg_product->getData();
		foreach ($linked_websites AS $website_id)
		{
			if (!in_array($website_id, $product_website_ids))
			{
				$new_website_ids = array_values(array_unique(array_merge($product_website_ids, array_values($linked_websites))));
				$mg_product->setWebsiteIds($new_website_ids);
				break;
			}
		}

		//Sync product attribute set
		if (isset($product['productGroup']{0}))
		{
			$this->functions->syncProductAttributeSet($mg_product, $product['productGroup']);
		}

		//Sync product attributes
		$attributes_type = array();

		$product['attributes']['name'] = $product['name'];
		$product['attributes']['description'] = $product['description']['text'];
		$product['attributes']['short_description'] = $product['shortDescription']['text'];
		$product['attributes']['weight'] = isset($product['weight']) ? $product['weight'] : 0.000;

		if (isset($product['brandId']))
		{
			$product['attributes']['manufacturer'] = $product['brandId'];
		}
		if (isset($product['condition']))
		{
			$product['attributes']['condition'] = $product['condition'];
		}

		//Add identity
		if (isset($product['identity']))
		{
			foreach ((array)$product['identity'] AS $product_identity_key => $product_identity_value)
			{
				if ($product_identity_key === 'sku')
				{
					continue;
				}
				$product['attributes'][$product_identity_key] = $product_identity_value;
				$attributes_type[$product_identity_key] = 'identity';
			}
		}

		//Add physical
		if (isset($product['physical']))
		{
			foreach ((array)$product['physical'] AS $product_physical_key => $product_physical_value)
			{
				$product['attributes'][$product_physical_key] = $product_physical_value;
				$attributes_type[$product_physical_key] = 'physical';
			}
		}

		//Add all options as attributes dropdown
		if (isset($product['options']))
		{
			foreach ($product['options'] AS $product_option_key => $product_option_value)
			{
				$attributes_type[$product_option_key] = 'option';
				$product['attributes'][$product_option_key] = $product_option_value;
			}
		}

		$this->functions->syncProductAttributes($mg_product, $product['attributes'], $attributes_type);

		//Sync product tax code
		if (isset($product['taxCode']))
		{
			if ($product['taxCode'] === 'None') //Magento special tax name
			{
				$mg_product->setTaxClassId(0);
			}
			else
			{
				//Only set if tax code exists
				$product_tax_class = $this->functions->getProductTaxClassByName($product['taxCode']);
				if ($product_tax_class !== null && count($product_tax_class) > 0)
				{
					$mg_product->setTaxClassId($product_tax_class['class_id']);
				}
			}
		}

		//Sync product categories
		if ($sync_product_categories)
		{
			$this->functions->syncProductCategories($mg_product, $product['categories']);
		}

		//Sync product images
		if ($sync_product_images)
		{
			$this->functions->syncProductImages($mg_product, $product['images']);
		}

		//Check if should trigger save on the product
		if ($mg_product->hasDataChanges())
		{
			$product_updates = array_diff_assoc($mg_product->getData(), $mg_product_orig_data);
			$product_updates = $this->array_recursive_diff($mg_product->getData(), $mg_product_orig_data);

			if (count($product_updates) > 0) //We should trigger product save
			{
				$mg_product->save();
			}
		}

		//Set product's stock
		if (isset($product['stock']))
		{
			$this->functions->setProductStockById($product['id'], $product['stock']);
		}

		//Update product prices
		if (isset($product['prices']))
		{
			$pricing_websites = $this->functions->getPricingWebsites($linked_websites, $websites);
			//Format product prices
			$product['prices'] = $this->formatProductPrices($product['prices'], $pricing_websites);

			$this->functions->setSimpleProductPrices($product['id'], $product['prices'], $pricing_websites);
		}

		//Success
		return $product['id'];
	}

	/**
	* Creates a product
	*
	* @param array $product an array containing the new product's details
	* @return the id of the product's cart id
	*/
	public function createProduct(array $product)
	{
		//Retrieve available product attributes
		$attributes = $this->functions->getProductAttributes();

		$cart_product_id = null;
		$mg_product = Mage::getModel('catalog/product');

		if (isset($product['productGroup']{0}))
		{
			$attribute_set_id = $this->functions->getAttributeSetIdByName($product['productGroup'], true); //Create if id doesnt exist
		}
		else
		{
			$attribute_set_id = $this->functions->getProductDefaultAttributeSetId();
		}

		$product_default_tax_class = $this->functions->getProductDefaultTaxClass();

		//Set product main details
		$mg_product->setTypeId('simple')
					->setCreatedAt(strtotime('now'))
					->setSku($product['sku'])
					->setName($product['name'])
					->setPrice(0.00)
					->setStatus(Mage_Catalog_Model_Product_Status::STATUS_DISABLED)
					->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH) //Catalog and search visibility
					->setWebsiteIds($product['website_ids'])
					->setTaxClassId($product_default_tax_class['class_id'])
					->setAttributeSetId($attribute_set_id);

		//Save product
		$mg_product->save();

		//Retrieve new product's id
		$cart_product_id = $mg_product->getId();

		if ($cart_product_id === null) //Check if product is created or not
			throw new Exception('Product could not be created');

		return $cart_product_id;
	}

	/**
	* Formats the given array of prices so it converts retail to retail-1, retail-2 etc
	*
	* It does not override shop specific prices when are present
	*
	* @param array $prices an array containing the prices
	* @param array $shops an array containing the shops
	* @return the formatted array
	*/
	public function formatProductPrices(array $prices, array $shops)
	{
		foreach (array('cost', 'rrp', 'sale', 'retail') AS $pricekey)
		{
			if (isset($prices[$pricekey]))
			{
				foreach ($shops AS $shop => $set)
				{
					if (!isset($prices[$pricekey . '-' . $set]))
					{
						$prices[$pricekey . '-' . $set] = $prices[$pricekey];
					}
				}

				unset($prices[$pricekey]);
			}

			foreach ($shops AS $shop => $set)
			{
				if ($shop === $set)
				{
					continue;
				}

				if (isset($prices[$pricekey . '-' . $shop]))
				{
					$prices[$pricekey . '-' . $set] = $prices[$pricekey . '-' . $shop];
					unset($prices[$pricekey . '-' . $shop]);
				}
			}
		}

		if (isset($prices[$pricekey . '-0']))
		{
			foreach ($prices[$pricekey . '-0'] AS $qty => $value)
			{
				if ($qty > 1)
				{
					unset($prices[$pricekey . '-0'][$qty]);
				}
			}
		}

		//Check for groups prices
		$groups = $this->functions->getGroups();
		foreach ($groups AS $group_id)
		{
			if (isset($prices['group_' . $group_id]))
			{
				foreach ($shops AS $shop => $set)
				{
					if ($shop === 0) //Remove values from groups from zero 0 because magento bugs. If a default price is used, we copy the prices to all the enabled websites
					{
						continue;
					}

					if (!isset($prices['group_' . $group_id . '-' . $set]))
					{
						$prices['group_' . $group_id . '-' . $set] = $prices['group_' . $group_id];
					}
				}

				unset($prices['group_' . $group_id]);
			}

			foreach ($shops AS $shop => $set)
			{
				if ($shop === $set)
				{
					continue;
				}

				if (isset($prices['group_' . $group_id . '-' . $shop]))
				{
					$prices['group_' . $group_id . '-' . $set] = $prices['group_' . $group_id . '-' . $shop];
					unset($prices['group_' . $group_id . '-' . $shop]);
				}
			}
		}

		return $prices;
	}

	function array_recursive_diff($array1, $array2)
	{
		$ret = array();

		foreach ($array1 as $key => $value)
		{
			if (array_key_exists($key, $array2))
			{
				if (is_array($value))
				{
					$a_recursive_diff = $this->array_recursive_diff($value, $array2[$key]);
					if (count($a_recursive_diff))
					{
						$ret[$key] = $a_recursive_diff;
					}
				}
				else
				{
					if ((string)$value !== (string)$array2[$key])
					{
						$ret[$key] = $value;
					}
				}
			}
			else
			{
				$ret[$key] = $value;
			}
		}
		return $ret;
	}

	/**
	* Callback method that handles the Configuration Get event
	*
	* @return array
	*/
	public function callbackBridgeConfigurationGet()
	{
		$magento = array(
			'adminStub'	=> (string)Mage::getConfig()->getNode("admin/routers/adminhtml/args")->frontName,
			'adminUrl'	=> Mage::helper("adminhtml")->getUrl("adminhtml"),
		);

		//Order statuses
		foreach (Mage::getModel('sales/order_status')->getCollection()->joinStates() as $order_status)
		{
			$magento['order_statuses'][] = array(
				'status'	=> $order_status->getStatus(),
				'state'		=> $order_status->getState(),
			);
		}

		//Shipping methods
		$shipping_methods = Mage::getSingleton('shipping/config')->getActiveCarriers();

		foreach ($shipping_methods as $shipping_code => $shipping_model)
		{
			$shipping_title = Mage::getStoreConfig('carriers/' . $shipping_code . '/title');
			$magento['shipping_methods'][] = array(
				'code' => $shipping_code,
				'title' => $shipping_title,
			);
		}

		//Customer groups
		foreach (Mage::getModel('customer/group')->getCollection() as $group)
		{
			$magento['customerGroups'][] = array(
				'id'	=> $group->getId(),
				'name'	=> $group->getCode(),
			);
		}

		//Tax rates
		foreach (Mage::getModel('tax/calculation_rate')->getCollection() as $rate)
		{
			$magento['tax'][] = array(
				'code'	=> $rate->getCode(),
				'country'	=> $rate->getCountryId(),
				'rate'	=> $rate->getRate(),
			);
		}

		$magento['sites'] = $this->getSiteData();

		$modules = (array)Mage::getConfig()->getNode('modules')->children();
		$core_helper = Mage::helper('core');

		foreach ($modules as $mod_name => $module)
		{
			$mod_info = array(
				'name'	=> $mod_name,
				'active'	=> $module->is('active'),
				'output'	=> $core_helper->isModuleOutputEnabled($mod_name),
				'version'	=> $module->version,
			);
			$magento['modules'][] = $mod_info;
		}

		$php = array(
			'version'	=> PHP_VERSION,
			'is64bit'	=> PHP_INT_SIZE === 8,
			'extensions'	=> get_loaded_extensions(),
			'os'	=> php_uname('a'),
			'host'	=> filter_input(INPUT_SERVER, 'HOST') ?: filter_input(INPUT_SERVER, 'HTTP_HOST'),
		);

		$info = array(
			'cart'	=> $magento,
			'php'	=> $php,	// TODO: add to meta?
		);

		return $info;
	}

	private function getSiteData()
	{
		$sites = array();

		foreach (Mage::getModel('core/website')->getCollection() as $website)
		{
			$sites[$website->getId()] = array(
				'id'	=> $website->getId(),
				'name'	=> $website->getName(),
				'defaultStoreId'	=> $website->getDefaultGroupId(),
			);
		}

		foreach (Mage::getModel('core/store_group')->getCollection() as $store_group)
		{
			$sites[$store_group->getWebsiteId()]['stores'][$store_group->getGroupId()] = array(
				'id'	=> $store_group->getGroupId(),
				'name'	=> $store_group->getName(),
				'rootCategoryId'	=> $store_group->getRootCategoryId(),
				'defaultStoreViewId'	=> $store_group->getDefaultStoreId(),
			);
		}

		foreach (Mage::getModel('core/store')->getCollection() as $store_view)
		{
			$sites[$store_view->getWebsiteId()]['stores'][$store_view->getGroupId()]['views'][$store_view->getStoreId()] = array(
				'id'	=> $store_view->getStoreId(),
				'name'	=> $store_view->getName(),
				'isActive'	=> (bool)$store_view->getIsActive(),
			);
		}

		return array_values($sites);
	}
}
