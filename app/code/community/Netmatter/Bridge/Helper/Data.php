<?php

/**
* @class Netmatter_Bridge_Helper_Data
*/
class Netmatter_Bridge_Helper_Data extends Mage_Core_Helper_Abstract
{
	private $db = array(); ///< Instances to the db

	/**
	* Retrieves the table prefix
	*
	* @return string
	*/
	public function getTablePrefix()
	{
		return Mage::getConfig()->getTablePrefix();
	}

	/**
	* Retrieves the integration version
	*
	* @return string the integration version
	*/
	public function getIntegrationVersion()
	{
		return '1.6.5';
	}

	public function isEnabled()
	{
		return Mage::getStoreConfig('netmatter/settings/enabled',Mage::app()->getStore()) === '1';
	}

	public function getConfigClientName()
	{
		return Mage::getStoreConfig('netmatter/settings/client_name',Mage::app()->getStore());
	}

	public function getConfigSource()
	{
		return Mage::getStoreConfig('netmatter/settings/source',Mage::app()->getStore());
	}

	public function getConfigAuthenticationToken()
	{
		return Mage::getStoreConfig('netmatter/settings/authentication_token',Mage::app()->getStore());
	}

	public function initBridge()
	{
		require (Mage::getModuleDir('', 'Netmatter_Bridge') . '/plugin/bridge.php');

		//Set version details
		$bridge->setIntegrationVersion($this->getIntegrationVersion());
		$bridge->setHostVersion($this->getHostVersion());

		$bridge->setCredentials(
			$this->getConfigClientName(),
			$this->getConfigSource(),
			$this->getConfigAuthenticationToken()
		);

		return $bridge;
	}

	/**
	* Checks if current installation is single website or not
	*
	* @param array $websites the array with the websites
	* @return boolean
	*/
	public function isSingleWebsite($websites)
	{
		if (isset($websites[0]))
		{
			unset($websites[0]);
		}

		return count($websites) === 1;
	}

	/**
	* Retrieves a list with the ids of the websites
	*
	* @return array array($website_id => $first_store_id)
	*/
	public function getWebsites()
	{
		$websites = array();

		$result = $this->db()->query("SELECT MIN(store_id) AS store_id, website_id FROM " . $this->getTablePrefix() . "core_store GROUP BY website_id;");
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$websites[$row['website_id']] = $row['store_id'];

		return $websites;
	}

	/**
	* Retrieves a the website id for the given store_id
	*
	* @param int $store_id the id of the store
	* @return int the website_id
	*/
	public function getWebsiteIdByStoreId($store_id)
	{
		$result = $this->db()->query("SELECT website_id FROM " . $this->getTablePrefix() . "core_store WHERE store_id = :store_id;", array('store_id' => $store_id));
		return ($row = $result->fetch(PDO::FETCH_ASSOC)) !== false;
	}

	/**
	* Retrieves a list with the ids of the stores
	*
	* @return array containing the stores ids
	*/
	public function getStores()
	{
		$stores = array();

		$result = $this->db()->query("SELECT store_id, code FROM " . $this->getTablePrefix() . "core_store;");
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$stores[$row['code']] = $row['store_id'];

		return $stores;
	}

	/*
	* Retrieves the "default" tax class for the products
	*
	* @return array
	*/
	public function getProductDefaultTaxClass()
	{
		return Mage::getModel('tax/class')
								->getCollection()
								->addFieldToFilter('class_type', 'PRODUCT')
								->getFirstItem()->getData();
	}

	public function getProductTaxClassByName($name)
	{
		$tax_class = Mage::getModel('tax/class')
								->getCollection()
								->addFieldToFilter('class_type', 'PRODUCT')
								->addFieldToFilter('class_name', $name)
								->getFirstItem();

		if ($tax_class === null)
		{
			return null;
		}

		return $tax_class->getData();
	}

	public function getAttributeSetAttributes($attribute_set_id)
	{
		$ret = array();
		$result = $this->db()->query("SELECT attribute_id FROM " . $this->getTablePrefix() . "eav_entity_attribute WHERE attribute_set_id = :attribute_set_id", array('attribute_set_id' => $attribute_set_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$ret[$row['attribute_id']] = 1;

		return $ret;
	}

	public function syncProductAttributeSet($mg_product, $attribute_set_name)
	{
		$attribute_set_id = $this->getAttributeSetIdByName($attribute_set_name, true); //Create if id doesnt exist
		$mg_product->setAttributeSetId($attribute_set_id);
	}

	public function syncProductAttributes($mg_product, $attributes, $attribute_types)
	{
		//Read available product attributes
		$available_attributes = $this->getProductAttributes();

		//Read all attributes for that attribute_set_id and add any needed
		$attribute_set_id = $mg_product->getAttributeSetId();
		$set_attributes = $this->getAttributeSetAttributes($attribute_set_id);

		//Create non existing attributes
		foreach ($attributes AS $attribute_key => $attribute_value)
		{
			list($attribute_name, $attribute_code, $store_id) = $this->parseAttributeName($attribute_key);
			$string_attribute_value = (string)$attribute_value;

			//Check if product has already this value configured and its correct
			if (!isset($available_attributes[$attribute_code]))
			{
				$new_attribute_data = array(
					'frontend_label' => $attribute_name
				);

				//Check if we have specific attribute format
				if (isset($attribute_types[$attribute_key]))
				{
					if ($attribute_types[$attribute_key] === 'option')
					{
						$new_attribute_data['backend_type'] = 'int';
						$new_attribute_data['frontend_input'] = 'select';
						$new_attribute_data['is_global'] = '1';
						$new_attribute_data['is_configurable'] = '1';
						$new_attribute_data['apply_to'] = array('simple');
					}
					elseif (in_array($attribute_types[$attribute_key], array('identity', 'physical')))
					{
						$new_attribute_data['backend_type'] = 'varchar';
						$new_attribute_data['frontend_input'] = 'text';
						$new_attribute_data['is_global'] = '1';
					}
				}
				else
				{
					$new_attribute_data['backend_type'] = 'varchar';
					$new_attribute_data['frontend_input'] = 'text';
				}

				$new_attribute_id = $this->createProductAttribute($attribute_code, $new_attribute_data);

				$available_attributes[$attribute_code] = array(
					'id' => $new_attribute_id,
					'frontend_input' => $new_attribute_data['frontend_input'],
					'backend_type' => $new_attribute_data['backend_type']
				);
			}

			$attribute_backend_type = $available_attributes[$attribute_code]['backend_type'];
			$attribute_frontend_input = $available_attributes[$attribute_code]['frontend_input'];

			//If required add new attribute to set
			if (!isset($set_attributes[$attribute_code]))
			{
				$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
				$attribute_group_id = $setup->getAttributeGroupId(Mage_Catalog_Model_Product::ENTITY, $attribute_set_id, 'General');
				$attribute_id = $setup->getAttributeId('catalog_product', $attribute_code);
				$setup->addAttributeToSet('catalog_product', $attribute_set_id, $attribute_group_id, $attribute_id, 0);
			}

			//Check attribute type and set the correct value
			if ($attribute_backend_type === 'int' && $attribute_frontend_input === 'select')
			{
				if (isset($string_attribute_value{0}))
				{
					$attribute_option = $this->getCreateAttributeOption($available_attributes[$attribute_code]['id'], $string_attribute_value);
					$this->setProductAttribute($mg_product, $attribute_code, $attribute_option['option_id'], $store_id);
				}
				else //Unset empty values
				{
					$this->setProductAttribute($mg_product, $attribute_code, null, $store_id);
				}
			}
			elseif ($attribute_backend_type === 'int' && $attribute_frontend_input === 'boolean')
			{
				if (!isset($string_attribute_value{0}) || (intval($attribute_value) < 1 && !in_array(strtolower($attribute_value), array('t', 'y', 'true', 'yes', 'on'))))
				{
					$this->setProductAttribute($mg_product, $attribute_code, 0, $store_id);
				}
				else
				{
					$this->setProductAttribute($mg_product, $attribute_code, 1, $store_id);
				}
			}
			elseif (in_array($attribute_backend_type, array('varchar', 'text')))
			{
				$this->setProductAttribute($mg_product, $attribute_code, $attribute_value, $store_id);
			}
			elseif ($attribute_backend_type === 'int')
			{
				$this->setProductAttribute($mg_product, $attribute_code, intval($attribute_value), $store_id);
			}
			elseif (in_array($attribute_backend_type, array('float', 'decimal')))
			{
				$this->setProductAttribute($mg_product, $attribute_code, number_format($attribute_value, 4), $store_id);
			}
		}
	}
	
	/**
	 * Returns the destination label, code and store-view_id
	 * 
	 * @param string $attribute_key
	 * @return array($attr_name, $attr_code, $scope)
	 */
	private function parseAttributeName($attribute_key)
	{
		// identify per-site stuff
		if (preg_match('/^(.*[^-])-(\d+)$/', $attribute_key, $matches))
		{
			// TODO: only use live store?
			$attr_label = str_replace('--', '-', $matches[1]);
			$store_id = $matches[2];
		}
		else
		{
			$attr_label = str_replace('--', '-', $attribute_key);
			$store_id = null;
		}
		
		// standardise underscores/camel-casing to spaces
		$attr_label = preg_replace(array('/_/', '/([A-Z])/'), array(' ', ' \\1'), trim($attr_label));
		// remove extra whitespace
		$attr_label = ucwords(trim(preg_replace('/\s+/', ' ', $attr_label)));
		
		$attr_code = preg_replace('/\s+/', '_', strtolower($attr_label));
		
		return array($attr_label, $attr_code, $store_id);
	}
	
	/**
	 * Sets default attributes to $mg_product, or directly sets per-store ones
	 * 
	 * @param Mage_Catalog_Model_Product $mg_product
	 * @param string $attribute_code
	 * @param mixed $attribute_value
	 * @param int|null $store_id
	 */
	private function setProductAttribute($mg_product, $attribute_code, $attribute_value, $store_id)
	{
		if (is_null($store_id))
		{
			$mg_product->setData($attribute_code, $attribute_value);
		}
		else
		{
			$p = Mage::getModel('catalog/product');
			$p->setStoreId($store_id);
			$p->setId($mg_product->getId());
			$p->setData($attribute_code, $attribute_value);
			$p->getResource()->saveAttribute($p, $attribute_code);
		}
	}

	/**
	* Retrieves the current website root category id
	*
	* @param string $store the store to use
	* @return int
	*/
	public function getRootCategoryId($store = 'default')
	{
		return Mage::app()->getStore($store)->getRootCategoryId();
	}

	public function getTopRootCategoryId()
	{
		$category_select = Mage::getModel('catalog/category')
								->getCollection()
								->addAttributeToFilter('parent_id', 0)
								->addAttributeToFilter('attribute_set_id', 0)
								->addAttributeToFilter('position', 0)
								->addAttributeToFilter('level', 0)
								->getSelect();

		$result = $this->db()->query($category_select);
		if ($row = $result->fetch(PDO::FETCH_ASSOC))
			return $row['entity_id'];

		throw new Exception('Could not get top root category id');
	}

	public function getFileContents($url)
	{
		//Init curl
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, str_replace(' ', '%20', $url));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);

		//Read contents
		$contents = curl_exec($curl);

		//Close curl
		curl_close($curl);

		return $contents;
	}

	public function getTempDirectory()
	{
		$tmp_dir = Mage::getBaseDir('tmp');

		while (true)
		{
 			$dirname = uniqid((string)time(), true);
			if (!file_exists($tmp_dir . DS . $dirname))
			{
				break;
			}
		}

		return $tmp_dir . DS . $dirname;
	}

	public function syncProductImages($mg_product, $images)
	{
		//The supported images mime types
		$mime_types = array(
			'image/jpeg' => 'jpg',
			'image/gif'  => 'gif',
			'image/png'  => 'png'
		);

		$mg_product->getResource()->getAttribute('media_gallery')->getBackend()->afterLoad($mg_product);
		$media_gallery_images = $mg_product->getMediaGalleryImages();

		//Calculate existing images md5 hash
		$existing_images = array();
		foreach ($media_gallery_images AS $img)
		{
			$existing_images[md5_file($img['path'])] = (array)$img->getData();
		}

		$media_changes = array(
			'create' => array(),
			'update' => array()
		);

		$i = -1;

		$main_image_position = null;

		//Loop though images and check
		foreach ($images AS $image)
		{
			$i++;

			//Check if label has been set
			if (!isset($image['label']{0}))
			{
				 $image['label'] = '';
			}

			if (isset($image['contents']) && !isset($image['md5'])) //We can calculcate the md5
			{
				$image['md5'] = md5($image['contents']);
			}

			if ($main_image_position === null || (isset($image['is_main_image']) && $image['is_main_image'] === true))
			{
				$main_image_position = (string)$i;
			}

			//Check if md5 hash has been defined. If check all current images for matching
			if (isset($image['md5']))
			{
				if (isset($existing_images[$image['md5']]))
				{
					$media_changes['update'][$existing_images[$image['md5']]['id']] = array(
						'label' => $image['label'],
						'position' => $i
					);
					continue;
				}
			}

			//We can load images either using a url or reading directly the contents from the message
			if (isset($image['contents'])) //From contents
			{
				$image_contents = @base64_decode((string)$image['contents'], true);
			}
			else
			if (isset($image['url'])) //From url
			{
				$image_contents = $this->getFileContents($image['url']);
			}
			else //Not a valid source to read image contents
			{
				continue; //Contents or url not set, skip to next image
			}

			$image['md5'] = md5($image_contents);

			//Check if we already have this image under different name
			if (isset($existing_images[$image['md5']]))
			{
				$media_changes['update'][$existing_images[$image['md5']]['id']] = array(
					'label' => $image['label'],
					'position' => $i
				);
				continue;
			}

			//Make image filename
			if (isset($image['filename']))
			{
				$image_filename = $image['filename'];
			}
			else
			if (isset($image['url'])) //From url
			{
				$image_filename = basename($image['url']);
			}
			else //The md5 hash
			{
				$image_filename = $image['md5'];
			}

			//Write contents to a file, just to determine the mime type
			$tmpfilename = tempnam(Mage::getBaseDir('tmp'), 'prefix');
			file_put_contents($tmpfilename, $image_contents);

			//Read contents minetype in order to create the filename extension
			$image_mime_type = mime_content_type($tmpfilename);

			//Remove file
			unlink($tmpfilename);

			//Check if mime type is valid
			if (!isset($mime_types[$image_mime_type]))
			{
				throw new Exception('Invalid mime type on image: ' . $image['name']);
			}

			//From mime types, extract extension
			$image_extension = $mime_types[$image_mime_type];

			//Remove extension from image
			$image_filename = preg_replace('/\.[^\.]+$/', '', $image_filename) . '.' . $image_extension;

			$media_changes['create'][] = array(
				'filename' => $image_filename,
				'contents' => $image_contents,
				'label' => $image['label'],
				'position' => $i
			);
		}

		//Update existing images
		$media_gallery = $mg_product->getData('media_gallery');

		foreach ($media_gallery['images'] AS $key => $media_gallery_image)
		{
			$image_id = $media_gallery_image['value_id'];
			if (isset($media_changes['update'][$image_id]))
			{
				$media_gallery['images'][$key]['label'] = $media_changes['update'][$image_id]['label'];
				$media_gallery['images'][$key]['position'] = $media_changes['update'][$image_id]['position'];
				$mg_product->setData('media_gallery', $media_gallery);
			}

			if ($media_gallery_image['position'] === $main_image_position)
			{
				$mg_product->setImage($media_gallery_image['file']);
				$mg_product->setSmallImage($media_gallery_image['file']);
				$mg_product->setThumbnail($media_gallery_image['file']);
			}
		}

		//Create new images
		foreach ($media_changes['create'] AS $image_to_create)
		{
			//We must create a new tmp file with the correct extension, so adding to image gallery will work
			$tmpdirectory = $this->getTempDirectory();
			mkdir($tmpdirectory);
			$tmpfilename = $tmpdirectory . DS . $image_to_create['filename'];
			@file_put_contents($tmpfilename, $image_to_create['contents']);

			//Add the image to the media gallery of the product
			$mg_product->addImageToMediaGallery($tmpfilename, array(), true, false);

			//Remove temp directory
			rmdir($tmpdirectory, true);

			//Add extra attrs
			$media_gallery = $mg_product->getData('media_gallery');
			$media_gallery_last_index = count($media_gallery['images']) - 1;

			$media_gallery['images'][$media_gallery_last_index]['label'] = $image_to_create['label'];
			$media_gallery['images'][$media_gallery_last_index]['position'] = $image_to_create['position'];
			$mg_product->setData('media_gallery', $media_gallery);
		}
	}

	/**
	* Shortcut function to add a category
	*
	* @param array $data the new category's data
	* @return category
	*/
	public function createCategory(array $data)
	{
		if (!isset($data['parent_id']))
		{
			$data['parent_id'] = $this->getTopRootCategoryId();
		}

		$category = Mage::getModel('catalog/category');
		$category->setName($data['name']);
		$category->setIsActive(1);
		$category->setDisplayMode('PRODUCTS');
		$category->setAttributeSetId(Mage::getModel('eav/entity')->setType('catalog_category')->getTypeId());
		$category->setIsAnchor(isset($data['anchor']) ? $data['anchor'] : 1);
		$category->setParentId($data['parent_id']);
		$parentCategory = Mage::getModel('catalog/category')->load($data['parent_id']);

		$category->setPath($parentCategory->getPath());

		foreach (array('meta_title', 'description', 'meta_keywords', 'meta_description') AS $attr)
		{
			if (isset($data[$attr]))
			{
				$category->setData($attr, $data[$attr]);
			}
		}

		if (isset($data['url_key']))
		{
			$category->setUrlKey($data['url_key']);
		}

		if (isset($data['redirect']))
		{
			$category->setRedirect($data['redirect']);
		}

		$category->save();

		return $category;
	}

	public function getStoresRootCategoryIds()
	{
		$ret = array();

		$result = $this->db()->query("SELECT root_category_id, website_id FROM " . $this->getTablePrefix() . "core_store_group WHERE website_id <> 0;");
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$ret[$row['website_id']] = $row['root_category_id'];
		}

		return $ret;
	}

	/**
	* Syncs the categoreis of the given product
	*/
	public function syncProductCategories($mg_product, $category_data)
	{
		//Read current product category ids and their path
		$product_categories_select = $mg_product->getCategoryCollection()
										->getSelect()
										->reset(Zend_Db_Select::COLUMNS)
										->columns(array('e.entity_id', 'e.path'));

		$current_product_categories = array();
		$result = $this->db()->query($product_categories_select);
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$current_product_categories[$row['entity_id']] = $row['path'];
		}

		//For all stores get root category ids
		$stores_root_category_ids = $this->getStoresRootCategoryIds();

		$splitter_character = ' > ';

		//Loop through category fullnames and extract all category names
		$category_names = array();

		foreach ($category_data AS $store_id => $store_categories)
		{
			foreach ($store_categories AS $store_category => $store_category_data)
			{
				$fullname_parts = preg_split('/' . $splitter_character . '/', $store_category);
				foreach ($fullname_parts AS $fullname_part)
				{
					$category_names[$fullname_part] = 1;
				}
			}
		}

		//Read categories from db for the names
		$category_select = Mage::getModel('catalog/category')
								->setStoreId(0)
								->getCollection()
								->addAttributeToFilter('name', array('in' => array_keys($category_names)))
								->getSelect()
								->reset(Zend_Db_Select::COLUMNS)
								->columns(array('e.entity_id', 'e.parent_id', 'at_name.value'));

		$db_categories = array();

		$result = $this->db()->query($category_select);
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$db_categories[strtolower($row['value'])][$row['parent_id']] = $row['entity_id'];
		}

		$new_product_categories = array();
		$root_categories_ids = array();

		$top_category_id = $this->getTopRootCategoryId();

		foreach ($stores_root_category_ids AS $store_id => $store_root_category_id)
		{
			if (isset($category_data[$store_id]))
			{
				$store_categories = $category_data[$store_id];
			}
			else
			if (isset($category_data["0"]))
			{
				$store_categories = $category_data["0"];
			}
			else
			{
				continue; //Skip to next store
			}

			$new_product_categories[] = $store_root_category_id; //Auto append store category id

			foreach ($store_categories AS $store_category_fullname => $store_category_data)
			{
				$fullname_parts = preg_split('/' . $splitter_character . '/', $store_category_fullname);
				$row_category_id = $store_root_category_id;
				$i = 0;
				foreach ($fullname_parts AS $category_name)
				{
					$category_name_lower = strtolower($category_name);

					$parent_id = $row_category_id;
					if (isset($db_categories[$category_name_lower][$parent_id])) //Category already exist
					{
						$row_category_id = $db_categories[$category_name_lower][$parent_id];
					}
					else //Create new category
					{
						$new_category = $this->createCategory(array(
							'name' => $category_name,
							'parent_id' => $parent_id
						));
						$row_category_id = $new_category->getId();
						$db_categories[$category_name_lower][$parent_id] = $row_category_id;
					}

					if ($i === 0)
					{
						$root_categories_ids[$row_category_id] = 1;
					}

					$i++;
				}

				//Add cateogry to product
				$new_product_categories[] = $row_category_id;
			}
		}

		//We need to remove all children categories that belong to root categories that were processed
		foreach ($stores_root_category_ids AS $store_id => $store_root_category_id)
		{
			foreach ($current_product_categories AS $current_category_id => $current_category_path)
			{
				if (preg_match('/^' . $top_category_id . '\/' . $store_root_category_id . '(\/|$)/', $current_category_path) > 0)
				{
					unset($current_product_categories[$current_category_id]);
				}
			}
		}

		//Create new product_categories array
		$new_product_categories = array_merge(array_keys($current_product_categories), $new_product_categories);

		//Check if old_categories and new_categories are different
		$old_product_categories = $mg_product->getCategoryIds();
		sort($old_product_categories);
		sort($new_product_categories);

		if ($old_product_categories !== $new_product_categories)
		{
			$mg_product->setCategoryIds($new_product_categories);
		}
	}

	/**
	* Sets the status of the given order
	*
	* @param $order the magento order object
	* @param $status the order's new status
	*/
	public function setOrderStatus($order, $status)
	{
		//Check current order status. Do not continue if it is the same
		if ($order->getStatus() === $status)
		{
			return;
		}

		//Find state based on status
		$status_details = Mage::getModel('sales/order_status')
								->getCollection()
								->joinStates()
								->addFieldToFilter('main_table.status', $status)
								->getFirstItem();

		//Update order's status. Magento does have a separate function for completed or closed
		switch ($status_details['state'])
		{
			//Order is cancelled
			case 'cancelled':
				if (!$order->canCancel())
				{
					throw new Exception('Order cannot be cancelled');
				}

				$order->cancel();

				break;

			//Order is complete
			case 'complete':

				//Disable order observer. Wrong order status is sent to bridge when payment is not captured yet
				Mage::register('netmatter_bridge_disable_order_observer', 1);

				//Check if order can be invoiced
				if ($order->canInvoice())
				{
					//Create invoice
					$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

					$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
					$invoice->register();

					//Save
					$transaction = Mage::getModel('core/resource_transaction')
										->addObject($invoice)
										->addObject($invoice->getOrder());
					$transaction->save();
				}

				//Set again state/status manually
				$order->setData('state', 'complete');
				$order->setStatus($status);
				$history = $order->addStatusHistoryComment('', false);
				$history->setIsCustomerNotified(true);

				//Re-enable
				Mage::register('netmatter_disable_order_observer', 0);

				//Trigger order to be resend
				Mage::helper('bridge/data')->sendOrder($order);

				break;

			//Order is closed. Not supported [Closed orders are orders that have had a credit memo assigned to it and the customer has been refunded for their order]
			case 'closed':

				break;

			//Any other status
			default:

				$order->setState($status_details['state'], $status);

				break;
		}

		//Save order
		$order->save();
	}

	/**
	* Calculates a hash from the given shipment details
	*
	* @param array $shipment the shipment to use
	* @return string
	*/
	public function calculateShipmentHash(array $shipment)
	{
		$x = array(
			'rows' => $shipment['rows'],
			'shippedOn' => $shipment['shippedOn']
		);

		return sha1(serialize($x));
	}

	/**
	* Retrieves a shipment by the order and hash
	*
	* @param Order $order the order to use
	* @param string $hash the hash code to search
	* @return Shipment|null
	*/
	public function getShipmentByOrderHash($order, $hash)
	{
		$shipments = $order->getShipmentsCollection();
		foreach ($shipments AS $shipment)
		{
			$shash = $this->getShipmentHash($shipment);
			if ($shash === $hash)
			{
				return $shipment;
			}
		}

		return null;
	}

	/**
	* Retrieves the shipment's hash for the given shipment
	*
	* @param Shipment $shipment the shipment to use
	* @return string|null
	*/
	public function getShipmentHash($shipment)
	{
		$prefix = 'Netmatter Hash: ';

		//Hash is stored within shipment's comments
		foreach ($shipment->getCommentsCollection() AS $comment)
		{
			$comment = $comment->getData('comment');
			preg_match('/^Netmatter Hash: ([a-zA-Z0-9]+)$/', $comment, $matches);
			if (count($matches) === 0)
			{
				return null;
			}

			return $matches[1];
		}

		return null;
	}

	/**
	* Syncs the shipments for the given order
	*
	* @param Order $order the order to sync the shipments
	* @param array $shipments the shipments data
	*/
	public function syncShipments($order, array $shipments)
	{
		//Check if we can create shipments for the given order
		if (!$order->canShip())
		{
			return; //We cannot modify shipments
		}

		//Read shipments for the given order
		$mg_shipment_collection = Mage::getResourceModel('sales/order_shipment_collection');
		$mg_shipment_collection->addAttributeToFilter('order_id', $order->getId());

		//Retrieve order products
		$magento_order_items = array();
		foreach ($order->getAllItems() AS $item)
		{
			// we need to ignore non-shipping rows so they don't overwrite the correct item_id
			if ($item->getQtyToShip() > 0)
			{
				$magento_order_items[$item['sku']] = $item['item_id'];
			}
		}

		//Check if all request products exist
		foreach ($shipments AS $key => $shipment)
		{
			foreach ($shipment['rows'] AS $row)
			{
				if (!isset($magento_order_items[$row['sku']]))
				{
					//At least one product not found. Cannot continue
					unset($shipments[$key]);
					break;
				}
			}
		}

		//Sync shipments
		foreach ($shipments AS $shipment)
		{
			//Check if shipment already exists (Hash checking)
			$shipment_hash = $this->calculateShipmentHash($shipment);
			if ($this->getShipmentByOrderHash($order, $shipment_hash) !== null)
			{
				continue;
			}

			//Make qty array
			$qty = array();
			foreach ($shipment['rows'] AS $row)
			{
				$qty[$magento_order_items[$row['sku']]] = $row['quantity'];
			}

			if (empty($qty)) //Could not found any products to ship
			{
				continue;
			}

			//Create new shipment
			$new_shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($qty);
			if ($new_shipment)
			{
				$new_shipment->addComment('Netmatter Hash: ' . $shipment_hash);

				$new_shipment->register();
				$new_shipment->getOrder()->setIsInProcess(true);
				$transaction = Mage::getModel('core/resource_transaction')
								->addObject($new_shipment)
								->addObject($new_shipment->getOrder());
				$transaction->save();
			}
		}
	}

	/**
	* Sets the tracking codes for the given order
	*
	* @param $order the magento order object
	* @param array $tracking_codes an array containing the tracking codes
	* @param array $shipment_hashes an array containing the shipment hases
	*/
	public function setOrderTrackingCodes($order, array $tracking_codes, array $shipment_hashes = null)
	{
		//Read shipments for the given order
		$shipment_collection = Mage::getResourceModel('sales/order_shipment_collection');
		$shipment_collection->addAttributeToFilter('order_id', $order->getId());

		//Append the tracking codes to all shipments, if they exist
		foreach($shipment_collection AS $mg_shipment)
		{
			//Extract tracking codes from shipment
			$mg_tracking_codes = array();
			foreach ($mg_shipment->getAllTracks() AS $tracknum)
			{
				$mg_tracking_codes[] = $tracknum->getNumber();
			}

			$mg_shipment_hash = $this->getShipmentHash($mg_shipment);

			foreach ($tracking_codes AS $shipping_method => $tracking_code)
			{
				//Check if tracking code has been already set
				if (in_array($tracking_code, $mg_tracking_codes))
				{
					continue;
				}

				//Check hash
				if (isset($shipment_hashes[$tracking_code]) && isset($mg_shipment_hash{0}) && $shipment_hashes[$tracking_code] !== $mg_shipment_hash)
				{
					continue;
				}

				//Add tracking line
				$track = Mage::getModel('sales/order_shipment_track')
								->setData('title', $shipping_method)
								->setData('number', $tracking_code)
								->setData('carrier_code', 'custom')
								->setData('order_id', $order->getId());

				$mg_shipment->addTrack($track);
				$mg_shipment->save();

				$mg_shipment->sendEmail(true);
			}
		}
	}

	/**
	* Retrieves a list with the ids of the available groups
	*
	* @return array containing the groups
	*/
	public function getGroups()
	{
		$groups = array();
		$result = $this->db()->query("SELECT customer_group_id, customer_group_code FROM " . $this->getTablePrefix() . "customer_group;");
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$groups[$row['customer_group_code']] = $row['customer_group_id'];

		return $groups;
	}

	/**
	* Retrieves the attributes that the product's can have
	*
	* @return an array containing the product attributes
	*/
	public function getProductAttributes()
	{
		$attributes = array();
		$result = $this->db()->query("SELECT attribute_id, attribute_code, frontend_input, backend_type FROM " . $this->getTablePrefix() . "eav_attribute WHERE entity_type_id = 4;");
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$attributes[$row['attribute_code']] = array(
				'id' => $row['attribute_id'],
				'frontend_input' => $row['frontend_input'],
				'backend_type' => $row['backend_type']
			);
		}

		return $attributes;
	}

	public function getAttributeSets($entity_type_id = null)
	{
		$ret = array();
		$attribute_set_collection = Mage::getModel("eav/entity_attribute_set")->getCollection();
		foreach ($attribute_set_collection AS $attribute_set)
		{
			if ($entity_type_id !== null && (string)$attribute_set->getEntityTypeId() !== (string)$entity_type_id)
			{
				continue;
			}
			$ret[$attribute_set->getAttributeSetName()] = $attribute_set->getAttributeSetId();
		}

		return $ret;
	}

	/**
	* Retrieves the id of the product entity type
	*
	* @return int
	*/
	public function getCatalogProductEntityTypeId()
	{
		return Mage::getModel('catalog/product')->getResource()->getEntityType()->getId();
	}

	/**
	* Retrieves the default attribute set id of the products
	*
	* @return int
	*/
	public function getProductDefaultAttributeSetId()
	{
		return Mage::getModel('catalog/product')->getDefaultAttributeSetId();
	}

	/**
	* Creates an attribute set from the given name
	*
	* @param string $attribute_set_name the name of the attribute set
	* @return int the attribute_set id
	*/
	public function createAttributeSet($attribute_set_name)
	{
		return (string)Mage::getModel('catalog/product_attribute_set_api')
							->create($attribute_set_name, $this->getProductDefaultAttributeSetId());
	}

	public function getAttributeSetIdByName($attribute_set_name, $create = null)
	{
		$attribute_sets = $this->getAttributeSets();
		if (isset($attribute_sets[$attribute_set_name]))
		{
			return $attribute_sets[$attribute_set_name];
		}

		//Attribute set does not exists, and no create flag isset
		if ($create !== true)
		{
			return null;
		}

		//Create attribute set
		return $this->createAttributeSet($attribute_set_name);
	}

	public function createProductAttribute($attribute_code, $attribute_data)
	{
		$default_attribute_data = array(
			'attribute_code' => $attribute_code,
			'is_global' => '0',
			'frontend_input' => 'text',
			'backend_type' => 'varchar',
			'default_value_yesno' => '0',
			'default_value_text' => '',
			'default_value_textarea' => '',
			'default_value_date' => '',
			'is_unique' => '0',
			'is_required' => '0',
			'apply_to' => array(),
			'is_configurable' => '0',
			'is_searchable' => '0',
			'is_visible_in_advanced_search' => '0',
			'is_comparable' => '0',
			'is_wysiwyg_enabled' => '0',
			'is_used_for_price_rules' => '0',
			'is_visible_on_front' => '0',
			'is_html_allowed_on_front' => '0',
			'used_for_sort_by' => '0',
			'used_in_product_listing' => '0',
			'frontend_label' => 'New Attribute'
		);

		$attribute_data = array_merge($default_attribute_data, $attribute_data);

		$model = Mage::getModel('catalog/resource_eav_attribute');
		$model->addData($attribute_data);
		$model->setEntityTypeId(Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId());
		$model->setIsUserDefined(1);

		return $model->save()->getId();
	}

	/**
	* Retrieve an array containing the product ids that have the given sku
	*
	* @param $product_sku the sku to use
	* @return an array contanining the ids
	*/
	public function getProductIdsBySku($product_sku)
	{
		$ids = array();
		$result = $this->db()->query("SELECT entity_id FROM " . $this->getTablePrefix() . "catalog_product_entity WHERE sku = :sku;", array('sku' => $product_sku));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$ids[] = $row['entity_id'];

		return $ids;
	}

	/**
	* Retrieve an array containing the available options for the given attribute
	*
	* @param $attribute_id the id of the attribute
	* @param $store_id the id of the store
	* @return an array contanining the attribute's options
	*/
	public function getAttributeOptions($attribute_id, $store_id = null)
	{
		$stores = array(0);
		if ($store_id !== null)
			$stores[] = $store_id;

		$options = array();
		$result = $this->db()->query("SELECT option_id, value FROM " . $this->getTablePrefix() . "eav_attribute_option_value WHERE option_id IN (SELECT option_id FROM " . $this->getTablePrefix() . "eav_attribute_option WHERE attribute_id = :attribute_id) AND store_id IN (" . implode(',', $stores) . ");", array('attribute_id' => $attribute_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$options[$row['value']] = $row['option_id'];

		return $options;
	}

	/**
	* Retrieve or creates an attribute option value
	*
	* @param $attribute_id the id of the attribute
	* @param $store_id the id of the store
	* @return an array contanining the attribute's options
	*/
	public function getCreateAttributeOption($attribute_id, $value, $store_id = null)
	{
		$stores = array(0);
		if ($store_id !== null)
			$stores[] = $store_id;

		$options = array();
		$result = $this->db()->query("SELECT option_id, value FROM " . $this->getTablePrefix() . "eav_attribute_option_value WHERE option_id IN (SELECT option_id FROM " . $this->getTablePrefix() . "eav_attribute_option WHERE attribute_id = :attribute_id) AND store_id IN (" . implode(',', $stores) . ") AND value = :value;", array('attribute_id' => $attribute_id, 'value' => $value));
		if ($row = $result->fetch(PDO::FETCH_ASSOC))
			return $row;

		//Create the option
		$this->addAttributeOption($attribute_id, $value);

		//Recurse call to get the newly created value
		return $this->getCreateAttributeOption($attribute_id, $value, $store_id);
	}

	/**
	* Adds an options to an attribute drop down
	*
	* @param $attribute_id the id of the attribute
	* @param $attribute_value the option's value
	*/
	public function addAttributeOption($attribute_id, $option_value)
	{
		$option['attribute_id'] = $attribute_id;
		$option['value']['0_' . $option_value][0] = $option_value;

		$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
		$setup->addAttributeOption($option);
	}

	/**
	* Checks if the given value is an integer or not
	*
	* @param $value the value to check
	* @return true if value is an integer, false if not
	*/
	public function isInteger($value)
	{
		return (!($type === 'boolean' || filter_var($value, FILTER_VALIDATE_INT) === false));
	}

	/**
	* Retrieve product(s) stock by sku
	*
	* @param $sku the sku or array or sku
	* @return an array containing sku, product_id and stock
	*/
	public function getProductStockBySku($sku)
	{
		//Make sure given parameter is array
		$sku = (array)$sku;

		//Make in query
		$in_query = implode(',', array_fill(0, count($sku), '?'));

		//Make statement
		$stmt = $this->db()->prepare("SELECT sku, entity_id, qty FROM " . $this->getTablePrefix() . "catalog_product_entity LEFT JOIN " . $this->getTablePrefix() . "cataloginventory_stock_item ON product_id = entity_id WHERE sku IN (" . $in_query . ");");

		//Bind values
		foreach ($sku as $key => $value)
		{
			$stmt->bindValue($key + 1, $value, PDO::PARAM_STR);
		}

		//Execute query
		$stmt->execute();

		//Fetch results
		$stock = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$stock[$row['sku']] = array('product_id' => $row['entity_id'], 'qty' => $row['qty']);
		}

		return $stock;
	}

	/**
	* Sets the stock level for the given product
	*
	* @param int $product_id
	* @param int $qty The new stock level
	*/
	public function setProductStockById($product_id, $qty)
	{
		$multiwarehouse = is_array($qty);

		//Multiple stock items
		$total_qty = 0;
		if (!$multiwarehouse)
		{
			$total_qty = $qty;
		}

		//Check for multi warehouse
		if ($multiwarehouse && Mage::getModel('advancedinventory/stock')->getMultiStockEnabledByProductId($product_id))
		{
			foreach ($qty AS $store_id => $value)
			{
				$advanced_inventory_stocks = Mage::getModel('advancedinventory/stock')->getStocksByProductIdAndStoreId($product_id, $store_id);

				foreach ($advanced_inventory_stocks AS $advanced_inventory_stock)
				{
					$advanced_stock_item = Mage::getModel('advancedinventory/stock')->getStockByProductIdAndPlaceId($product_id, $advanced_inventory_stock->getPlaceId());
					if ($advanced_stock_item->getId() === null) //Skip places not found
					{
						continue;
					}

					$total_qty += $value;

					$advanced_stock_item->setData('quantity_in_stock', $value);

					$advanced_stock_item->save();
				}
			}
		}

		//Retrieve stock item
		$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product_id);
		if (!$stockItem->getId())
		{
			$stockItem->setData('product_id', $product_id);
			$stockItem->setData('stock_id', $this->getDefaultStockId());

			//Save and reload
			$stockItem->save();
			$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product_id);
		}

		$product_backorders = ($stockItem->getUseConfigBackorders() == 1 && Mage::getStoreConfig('cataloginventory/item_options/backorders') == 1) ||
													($stockItem->getUseConfigBackorders() == 0 && $stockItem->getBackorders() > 0);

		$new_is_in_stock_flag = ($total_qty > 0 || $product_backorders === true) ? 1 : 0;

		//Check if stock needs to be updated
		if (netmatter_float_equals((float)$stockItem->getQty(), (float)$total_qty) &&
			$stockItem->getManageStock() == 1 &&
			$stockItem->getIsInStock() == $new_is_in_stock_flag)
		{
			return;
		}

		$stockItem->setData('qty', $total_qty);
		$stockItem->setData('manage_stock', 1);
		$stockItem->setData('use_config_manage_stock', 0);
		$stockItem->setData('is_in_stock', $new_is_in_stock_flag);
		$stockItem->save();
	}

	/**
	* Sets the stock level for the given product
	*
	* @param $product the magento product
	* @param $qty the stock level
	* @return the product
	*/
	public function setProductStock($product, $qty)
	{
		$product->setStockData(array(
			'use_config_manage_stock' => 0,
			'manage_stock' => 1,
			'is_in_stock' => $qty > 0 ? 1 : 0,
			'qty' => $qty
			)
		);

		return $product;
	}

	/**
	* Retrieves the default stock id
	*
	* @return integer the default stock id
	*/
	public function getDefaultStockId()
	{
		//Find stock with name "Default"
		$result = $this->db()->query("SELECT stock_id FROM " . $this->getTablePrefix() . "cataloginventory_stock WHERE stock_name = 'Default';");
		$row = $result->fetch(PDO::FETCH_ASSOC);
		if ($row !== false)
		{
			return $row['stock_id'];
		}

		//Get the minimum stock id
		$result = $this->db()->query("SELECT MIN(stock_id) AS min_stock_id FROM " . $this->getTablePrefix() . "cataloginventory_stock;");
		$row = $result->fetch(PDO::FETCH_ASSOC);

		return $row !== false ? $row['min_stock_id'] : 1;
	}

	/**
	* Retrieves the product's parent product id
	*
	* @param $product_id the id of the product
	* @return the product's parent id if exists, or null if product does not have a parent product
	*/
	public function getProductParentId($product_id)
	{
		$result = $this->db()->query("SELECT parent_id FROM " . $this->getTablePrefix() . "catalog_product_relation WHERE child_id = :child_id;", array('child_id' => $product_id));
		$row = $result->fetch(PDO::FETCH_ASSOC);
		return $row !== false ? $row['parent_id'] : null;
	}

	/**
	* Checks if a product with the given id exists or not
	*
	* @param $product_id the id of the product
	* @return true or false
	*/
	public function productExists($product_id)
	{
		$result = $this->db()->query("SELECT 1 FROM " . $this->getTablePrefix() . "catalog_product_entity WHERE entity_id = :entity_id;", array('entity_id' => $product_id));
		return ($row = $result->fetch(PDO::FETCH_ASSOC)) !== false;
	}

	/**
	* Disables a product based on its id
	*
	* @param $product_id the id of the product
	* @param $website_id the id of the website
	*/
	public function disableProduct($product_id, $website_id = 0)
	{
		Mage::getModel('catalog/product_status')->updateProductStatus($product_id, $website_id, Mage_Catalog_Model_Product_Status::STATUS_DISABLED);
	}

	/**
	* Retrieves super links that a product has
	*
	* @param $product_id the id of the product
	* @return an array containing the product's super links
	*/
	public function getProductSuperLinks($product_id)
	{
		$super_links = array();
		$result = $this->db()->query("SELECT link_id, parent_id FROM " . $this->getTablePrefix() . "catalog_product_super_link WHERE product_id = :product_id;", array('product_id' => $product_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
			$super_links[$row['link_id']] = $row['parent_id'];

		return $super_links;
	}

	/**
	* Retrieves the prices array of the given product id by simply quering the database so we can update prices only if price have changed
	*
	* @param $product_id the id of the product
	* @return an array containing the product's prices
	*/
	public function getSimpleProductPrices($product_id)
	{
		//Get attributes ids
		$attributes = $this->getProductAttributes();
		$filter_attributes = array(
			$attributes['cost']['id'] => 'cost',
			$attributes['msrp']['id'] => 'msrp',
			$attributes['price']['id'] => 'price',
			$attributes['special_price']['id'] => 'special_price',
			$attributes['special_to_date']['id'] => 'special_to_date',
			$attributes['special_from_date']['id'] => 'special_from_date'
		);

		//Make query
		$query = "SELECT 'price' AS type, 1 AS qty, attribute_id AS id, store_id AS website_id, value FROM " . $this->getTablePrefix() . "catalog_product_entity_decimal WHERE entity_id = :product_id and attribute_id in (" . implode(', ', array_filter(array_keys($filter_attributes))) . ")
			UNION
			SELECT 'tier' AS type, FLOOR(qty) AS qty, customer_group_id AS id, website_id, value FROM " . $this->getTablePrefix() . "catalog_product_entity_tier_price WHERE entity_id = :product_id";

		if ($this->supportsGroupPrices())
		{
			$query .= " UNION SELECT 'group' AS type, 1 AS qty, customer_group_id AS id, website_id, value FROM " . $this->getTablePrefix() . "catalog_product_entity_group_price WHERE entity_id = :product_id";
		}

		//Read prices
		$prices = array();
		$result = $this->db()->query($query, array('product_id' => $product_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			if (!isset($prices[$row['website_id']]['group']))
			{
				$prices[$row['website_id']]['group'] = array();
			}

			if (!isset($prices[$row['website_id']]['tier']))
			{
				$prices[$row['website_id']]['tier'] = array();
			}

			switch ($row['type'])
			{
				case 'group':
					$prices[$row['website_id']]['group'][$row['id']]["1"] = $row['value'];
					break;

				case 'tier':
					if ($row['id'] > 0) //Specific group
					{
						$prices[$row['website_id']]['group'][$row['id']][$row['qty']] = $row['value'];
					}
					else //Retail
					{
						$prices[$row['website_id']]['tier'][$row['qty']] = $row['value'];
					}

					break;

				default:
					$prices[$row['website_id']][$filter_attributes[$row['id']]] = $row['value'];
			}
		}

		return $prices;
	}

	/**
	* Retrieves a Magento product by its id for the given store_id
	*
	* @param $product_id the id of the product
	* @param $store_id the id of the store. Leave to null for default
	* @return the Magento product
	*/
	public function getProduct($product_id, $store_id = null)
	{
		$model = Mage::getModel('catalog/product');
		if ($store_id !== null)
		{
			$model->setStoreId($store_id);
		}

		$product = $model->getCollection()->addAttributeToSelect('*')->addAttributeToFilter('entity_id', $product_id)->getFirstItem();
		if ($product->getId() !== $product_id) //Check for some custom magento
		{
			$product = $model->load($product_id);
		}

		return $product;
	}
	
	/**
	 * Returns the sites that pricing is enabled for
	 * 
	 * @param array $linked_websites array($first_store_id => $website_id)
	 * @param array $websites array($website_id => $first_store_id)
	 * @return array($foo => $bar)
	 */
	public function getPricingWebsites($linked_websites, $websites) 
	{
		$pricing_websites = $linked_websites;
		
		//Check for single website. If it we have to set the 0 website instead of 1
		if ($this->isSingleWebsite($websites))
		{
			$linked_websites_keys = array_keys($linked_websites);
			$pricing_websites = array($linked_websites_keys[0] => 0);
		}
		else
		{
			//Always set the zero website
			$pricing_websites[0] = 0;
		}
		
		return array_unique($pricing_websites);
	}

	/**
	* Retrieves the super pricing for the given product
	*
	* @param $product_id the id of the product
	* @return array the super pricing
	*/
	public function getProductSuperAttributePricing($product_id)
	{
		$pricing = array();
		$result = $this->db()->query("SELECT * FROM " . $this->getTablePrefix() . "catalog_product_super_attribute_pricing p JOIN " . $this->getTablePrefix() . "catalog_product_super_attribute a ON p.product_super_attribute_id = a.product_super_attribute_id WHERE product_id = :product_id;", array('product_id' => $product_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$pricing[$row['value_id']] = $row;
		}

		return $pricing;
	}

	/**
	* Quickly reads the price for the given product for the given store_id
	*
	* Reads store : 0 if no custom price for the given store exists
	*
	* @param $product_id the id of the product
	* @param $store_id the id of the store
	* @return the product's price
	*/
	public function getProductPrice($product_id, $store_id)
	{
		$attributes = $this->getProductAttributes();

		$values = array(
			'store_id' => $store_id,
			'entity_id' => $product_id,
			'attribute_id' => $attributes['price']['id']
		);

		$result = $this->db()->query("SELECT value FROM " . $this->getTablePrefix() . "catalog_product_entity_decimal WHERE store_id IN (0, :store_id) AND value IS NOT NULL AND value <> '' AND entity_id = :entity_id AND attribute_id = :attribute_id ORDER BY store_id DESC LIMIT 1;", $values);
		$row = $result->fetch(PDO::FETCH_ASSOC);
		return $row !== false ? $row['value'] : 0.00;
	}

	/**
	* Retrieves an array containing the int attributes and values for the given entity
	*
	* @param $entity_id the id of the entity
	* @retun array
	*/
	public function getIntAttributesForEntity($entity_id)
	{
		$attributes = array();
		$result = $this->db()->query("SELECT attribute_id, store_id, (SELECT website_id FROM " . $this->getTablePrefix() . "core_store WHERE store_id = p.store_id) AS website, value FROM " . $this->getTablePrefix() . "catalog_product_entity_int AS p WHERE entity_id = :entity_id;", array('entity_id' => $entity_id));
		while ($row = $result->fetch(PDO::FETCH_ASSOC))
		{
			$attributes[$row['attribute_id']] = $row;
		}

		return $attributes;
	}

	/**
	* Sets the price for the given product_id on the given product super link id for the given website
	*
	* @param $product_super_link_id the super link id
	* @param $product_id the product's id
	* @param $new_price the new product's price
	* @param $website_id the id of the website
	* @param $store_id the id of the store
	*/
	public function setSuperLinkPrice($product_super_link_id, $product_id, $new_price, $website_id, $store_id)
	{
		//Read data
		$pricing_list = $this->getProductSuperAttributePricing($product_super_link_id);
		$child_attributes = $this->getIntAttributesForEntity($product_id);

		$matched_price = null;

		//Find which attribute_id and value_index is the correct
		foreach ($pricing_list as $pricing)
		{
			foreach ($child_attributes as $child_attribute)
			{
				if ($pricing['attribute_id'] == $child_attribute['attribute_id'] && $pricing['value_index'] == $child_attribute['value'])
				{
					if ($matched_price=== null || $website_id == $pricing['website_id'])
					{
						$matched_price = $pricing;
						break;
					}
				}
			}
		}

		if ($matched_price === null) //We cannot automatically set. Customer must makes the variation manually first
		{
			return;
		}

		//Calculate new price based on conf product's price
		$conf_price = $this->getProductPrice($product_super_link_id, $store_id);
		$new_price -= $conf_price;

		//Check if price needs to be updated
		if ($matched_price['website_id'] == $website_id && $matched_price['is_percent'] == 0 && $matched_price['pricing_value'] == $new_price)
		{
			return; //No need to update
		}

		//Update pricing
		if (isset($matched_price['value_id']{0}) && $matched_price['website_id'] == $website_id)
		{
			$this->db()->query("UPDATE " . $this->getTablePrefix() . "catalog_product_super_attribute_pricing SET pricing_value = :pricing_value WHERE value_id = :value_id;", array('pricing_value' => $new_price, 'value_id' => $matched_price['value_id']));
		}
		else //Insert pricing
		{
			$insert_values = array(
				':product_super_attribute_id' => $matched_price['product_super_attribute_id'],
				':value_index' => $matched_price['value_index'],
				':pricing_value' => $new_price,
				':website_id' => $website_id,
			);

			$this->db()->query("INSERT INTO " . $this->getTablePrefix() . "catalog_product_super_attribute_pricing(product_super_attribute_id, value_index, is_percent, pricing_value, website_id) VALUES (:product_super_attribute_id, :value_index, 0, :pricing_value, :website_id);", $insert_values);
		}
	}

	/**
	* Checks if the current version supports group prices or not
	*
	* @return boolean
	*/
	public function supportsGroupPrices()
	{
		return $this->db()->isTableExists($this->getTablePrefix() . 'catalog_product_entity_group_price');
	}

	/**
	* Sets the prices for the given product
	*
	* @param int $product_id the id of the product
	* @param array $prices an array containing the prices
	* @param array $websites array($store_id => $website_id)
	*/
	public function setSimpleProductPrices($product_id, $prices, array $websites)
	{
		//Retrieve product's current prices
		$current_prices = $this->getSimpleProductPrices($product_id);

		foreach ($websites AS $store_id => $website_id)
		{
			$attributes_data = array(
				'msrp' => isset($prices['rrp-' . $website_id]['1']) ? $prices['rrp-' . $website_id]['1'] : '',
				'cost' => isset($prices['cost-' . $website_id]['1']) ? $prices['cost-' . $website_id]['1'] : '',
			);

			//Retail price
			if (isset($prices['retail-' . $website_id]['1']))
			{
				$attributes_data['price'] = $prices['retail-' . $website_id]['1'];
			}

			//Check for sale price
			if (!array_key_exists('sale-' . $website_id, $prices))
			{
				// it's not mapped - don't change anything
			}
			elseif (isset($prices['sale-' . $website_id]['1']))
			{
				$attributes_data['special_price'] = $prices['sale-' . $website_id]['1'];
			}
			elseif (isset($prices['sale-' . $website_id]['price']))
			{
				$sale_obj = $prices['sale-' . $website_id];
				$attributes_data['special_price'] = $sale_obj['price'];
				$attributes_data['special_from_date'] = isset($sale_obj['start']) ? $sale_obj['start'] : null;
				$attributes_data['special_to_date'] = isset($sale_obj['end']) ? $sale_obj['end'] : null;
			}
			else //Remove sale price
			{
				$attributes_data['special_price'] = '';
				$attributes_data['special_to_date'] = '';
				$attributes_data['special_from_date'] = '';
			}

			foreach ($attributes_data as $key => $value)
			{
				if ((isset($current_prices[$website_id][$key]) && $current_prices[$website_id][$key] == $value) || (!isset($current_prices[$website_id][$key]) && $value === ''))
				{
					unset($attributes_data[$key]);
				}
			}

			//Check if update is required
			if (!empty($attributes_data))
			{
				Mage::getSingleton('catalog/product_action')->updateAttributes(array($product_id), $attributes_data, $store_id);
			}

			//Check for product super links
			$product_super_links = $this->getProductSuperLinks($product_id);
			if (count($product_super_links) === 0)
			{
				continue;
			}

			//In case that retail price or sale is updated and the product belongs to configurable products, we must properly set the values
			$new_price = null;
			if (isset($prices['sale-' . $website_id]['1'])) //Use sale over price
			{
				$new_price = $prices['sale-' . $website_id]['1'];
			}
			elseif (isset($prices['retail-' . $website_id]['1']))
			{
				$new_price = $prices['retail-' . $website_id]['1'];
			}

			if ($new_price === null)
			{
				continue;
			}

			//Get product's super links
			foreach ($product_super_links as $product_super_link)
			{
				$this->setSuperLinkPrice($product_super_link, $product_id, $new_price, $website_id, $store_id);
			}
		}

		//Check for group and tier prices
		$group_prices = array();
		$tier_prices = array();

		$groups = $this->getGroups();

		foreach ($websites AS $store_id => $website_id)
		{
			//Check for retail price
			if (isset($prices['retail-' . $website_id]['1']))
			{
				foreach ($prices['retail-' . $website_id] AS $tier_qty => $price)
				{
					if ($tier_qty == 1)
					{
						continue;
					}

					$tier_prices[] = array(
						'website_id'	=> $website_id,
						'cust_group' 	=> Mage_Customer_Model_Group::CUST_GROUP_ALL,
						'price'			=> $price,
						'price_qty'		=> $tier_qty
					);
				}
			}

			//Check for groups prices
			foreach ($groups AS $group_id)
			{
				if (isset($prices['group_' . $group_id . '-' . $website_id]['1']))
				{
					foreach ($prices['group_' . $group_id . '-' . $website_id] AS $tier_qty => $price)
					{
						if ($tier_qty == 1) //Price for 1 qty is stored under group prices
						{
							$group_prices[] = array(
								'website_id'	=> $website_id,
								'cust_group' 	=> $group_id,
								'price'			=> $price,
							);
						}
						else //Prices for more than 1 qty are stored as tier price
						{
							$tier_prices[] = array(
								'website_id'	=> $website_id,
								'cust_group' 	=> $group_id,
								'price'			=> $price,
								'price_qty'		=> $tier_qty
							);
						}
					}
				}
			}
		}


		//Create compare arrays
		$mg_group_price = array();
		$mg_tier_price = array();
		foreach ($current_prices as $website_id => $pricelists)
		{
			foreach ($pricelists['group'] as $group_id => $values)
			{
				foreach ($values as $qty => $price)
				{
					if ($qty == 1)
					{
						$mg_group_price[] = array(
												'website_id'	=> $website_id,
												'cust_group' 	=> $group_id,
												'price'			=> $price
											);
					}
					else
					{
						$mg_tier_price[] = array(
												'website_id'	=> $website_id,
												'cust_group' 	=> $group_id,
												'price'			=> $price,
												'price_qty'		=> $qty
											);
					}
				}
			}

			foreach ($pricelists['tier'] as $qty => $price)
			{
				$mg_tier_price[] = array(
										'website_id'	=> $website_id,
										'cust_group'	=> Mage_Customer_Model_Group::CUST_GROUP_ALL,
										'price'			=> $price,
										'price_qty'		=> $qty
									);
			}
		}

		array_multisort($mg_tier_price);
		array_multisort($tier_prices);
		array_multisort($mg_group_price);
		array_multisort($group_prices);

		$tier_prices_different = $mg_tier_price != $tier_prices;
		$group_prices_different = $mg_group_price != $group_prices;

		// Only update if necessary
		if ($tier_prices_different || $group_prices_different)
		{
			$supportsGroupPrices = $this->supportsGroupPrices();
			
			$mg_product = Mage::getModel('catalog/product')->setStoreId(0)->load($product_id);

			$mg_product->unsTierPrice();
			if ($supportsGroupPrices === true)
			{
				$mg_product->unsGroupPrice();
			}

			//We need to double save to prevent integrity errors. Magento lol. Also in later version we need to fully reload product
			$mg_product->save();
			$mg_product = Mage::getModel('catalog/product')->setStoreId(0)->load($product_id);

			$mg_product->setTierPrice($tier_prices);
			if ($supportsGroupPrices === true)
			{
				$mg_product->setGroupPrice($group_prices);
			}

			$mg_product->save();
		}
	}

	/**
	* Retrieve the instance to the db
	*
	* @return the db instance
	*/
	public function db($write = false)
	{
		$key = $write === true ? 'write' : 'read';

		if (!isset($this->db[$key]))
		{
			$this->db[$key] = Mage::getSingleton('core/resource')->getConnection('core_' . $key);
		}

		return $this->db[$key];
	}

	/**
	* Retrieves the host's version
	*
	* @retun string
	*/
	public function getHostVersion()
	{
		return 'Magento ' . Mage::getVersion();
	}

	/**
	* Sends an order to the bridge
	*
	* @param Mage_Sales_Model_Order $magento_order
	*/
	public function sendOrder($magento_order)
	{
		/* @var $bridge Netmatter_Bridge_Bridge */
		$bridge = $this->initBridge();

		//Create a new Bridge DTO Object
		$order_dto = $bridge->createOrder();

		//Calculate date_placed
		$magento_order_created_at = $magento_order->getCreatedAt();
		$year = substr($magento_order_created_at, 0, 4);
		$month = substr($magento_order_created_at, 5, 2);
		$day = substr($magento_order_created_at, 8, 2);
		$hour = substr($magento_order_created_at, 11, 2);
		$minute = substr($magento_order_created_at, 14, 2);
		$second = substr($magento_order_created_at, 17, 2);

		$date_placed = mktime($hour, $minute, $second, $month, $day, $year);

		//Set main values
		$order_dto->setId($magento_order->getId())
				  ->setPublicId($magento_order->getIncrementId())
				  ->setChannelId($magento_order->getStoreId())
				  ->setOrderStatus($magento_order->getStatus())
				  ->setTotal((float)$magento_order->getGrandTotal())
				  ->setDatePlaced($date_placed);

		//Add billing information
		$magento_billing_data = $magento_order->getBillingAddress()->getData(); //Array
		$billing_street = preg_split('/\n/', $magento_billing_data['street']);
		$order_dto->addBilling()
			  ->setFirstname(isset($magento_billing_data['firstname']{0}) ? $magento_billing_data['firstname'] : $magento_order->getCustomerFirstname())
			  ->setLastname(isset($magento_billing_data['lastname']{0}) ? $magento_billing_data['lastname'] : $magento_order->getCustomerLastname())
			  ->setCompany($magento_billing_data['company'])
			  ->setStreet($billing_street[0])
			  ->setSuburb(implode("\n", array_slice($billing_street, 1, count($billing_street))))
			  ->setCity($magento_billing_data['city'])
			  ->setCounty($magento_billing_data['region'])
			  ->setPostcode($magento_billing_data['postcode'])
			  ->setCountryIsoCode($magento_billing_data['country_id'])
			  ->setTelephone($magento_billing_data['telephone'])
			  ->setEmailAddress(isset($magento_billing_data['email']{0}) ? $magento_billing_data['email'] : $magento_order->getCustomerEmail());

		//Add delivery information
		$magento_shipping_address = $magento_order->getShippingAddress();

		//Check if shipping details exist, if not use billing data again
		$magento_shipping_data = $magento_shipping_address !== false ? $magento_shipping_address->getData() : $magento_billing_data;
		$shipping_street = preg_split('/\n/', $magento_shipping_data['street']);
		$order_dto->addDelivery()
				  ->setFirstname($magento_shipping_data['firstname'])
				  ->setLastname($magento_shipping_data['lastname'])
				  ->setCompany($magento_shipping_data['company'])
				  ->setStreet($shipping_street[0])
				  ->setSuburb(implode("\n", array_slice($shipping_street, 1, count($shipping_street))))
				  ->setCity($magento_shipping_data['city'])
				  ->setCounty($magento_shipping_data['region'])
				  ->setPostcode($magento_shipping_data['postcode'])
				  ->setCountryIsoCode($magento_shipping_data['country_id'])
				  ->setTelephone($magento_shipping_data['telephone'])
				  ->setEmailAddress(isset($magento_shipping_data['email']{0}) ? $magento_shipping_data['email'] : $magento_order->getCustomerEmail());

		//Set payment details
		$magento_order_payment = $magento_order->getPayment();
		$magento_order_payment_data = $magento_order_payment->getData();
		$payment = $order_dto->addPayment();
		$payment->setMethod($magento_order_payment_data['method'])
				->setBaseCurrency($magento_order->getBaseCurrencyCode())
				->setCurrency($magento_order->getOrderCurrencyCode());

		//Set payment details data
		switch ($magento_order_payment_data['method'])
		{
			case 'paypal_direct':
			case 'paypal_express':
			case 'paypal_standard':
				$payment->setMethod('paypal');

				$paypal_details = $payment->addPaypalDetails();

				$paypal_details->setPayerId($magento_order_payment_data['additional_information']['paypal_payer_id'])
								->setPayerEmailAddress($magento_order_payment_data['additional_information']['paypal_payer_email']);

				if (Mage_Paypal_Model_Info::isPaymentSuccessful($magento_order_payment))
				{
					if ($payment_last_trans_id = $magento_order_payment->getLastTransId())
					{
						$paypal_details->setTxId($payment_last_trans_id);
					}

					$paypal_details->setStatus('OK')->setStatusLabel('Payment Received');

					$payment->setAmount((float)$magento_order_payment->getAmountPaid());
					$payment->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());
					$order_dto->setIsPaid(true);
				}

				break;

			//Barclays epdq
			case 'ops_cc':
				$payment->setMethod('epdq');

				//Check for payment id
				if (isset($magento_order_payment_data['additional_information']['paymentId']))
				{
					$epdq_details = $payment->addEpdqDetails();

					$epdq_details->setTxId($magento_order_payment_data['additional_information']['paymentId']);

					if ($order_is_paid === true) //Success
					{
						$epdq_details->setStatus('OK')->setStatusLabel('Payment Received');
						$epdq_details->setCcBrand($magento_order_payment_data['additional_information']['CC_BRAND'])
										->setAavCheck($magento_order_payment_data['additional_information']['additionalScoringData']['AAVCHECK'])
										->setCvcCheck($magento_order_payment_data['additional_information']['additionalScoringData']['CVCCHECK']);

						$payment->setAmount((float)$magento_order_payment->getAmountPaid());
						$payment->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());
						$order_dto->setIsPaid(true);
					}
					else
					{
						$epdq_details->setStatus('FAILED')->setStatusLabel('Payment failed. Status: ' . $magento_order_payment_data['additional_information']['status']);
					}
				}

				break;

				//Sagepay
				case 'sagepaydirectpro':
				case 'sagepayserver':
				case 'sagepayserver_moto':
				case 'sagepaypaypal':
				case 'sagepayform':

					$payment->setMethod('sagepay');

					$sagepay_data = Mage::getModel('sagepaysuite2/sagepaysuite_transaction')->getCollection()->addFieldToFilter('order_id', $magento_order->getId())->getFirstItem()->getData();

					if (isset($sagepay_data['id']) && isset($sagepay_data['vps_tx_id']))
					{
						$sagepay_details = $payment->addSagepayDetails();
						$sagepay_details->setTxId($sagepay_data['vps_tx_id'])->setStatus('OK')->setStatusLabel('Payment success');
						$sagepay_details->setCv2Result($sagepay_data['cv2result'])
										->setAddressResult($sagepay_data['address_result'])
										->setPostcodeResult($sagepay_data['postcode_result'])
										->setAvsCv2Check($sagepay_data['avscv2'])
										->setAuthCode($sagepay_data['tx_auth_no'])
										->setThreeDSecureStatus($sagepay_data['threed_secure_status']);

						$payment->setAmount((float)$magento_order_payment->getAmountPaid());
						$payment->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());
						$order_dto->setIsPaid(true);
					}
					else
					{
						//There is a weird issue with Sagepay plugin. Maybe it updates the status before it actually stores the data in the datbase
						//So an order is paid but we cant get sagepay details
						$payment->setAmount((float)$magento_order_payment->getAmountPaid());
						$payment->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());
						$order_is_paid = netmatter_float_equals($magento_order->getGrandTotal(), $magento_order_payment->getAmountPaid());
						$order_dto->setIsPaid($order_is_paid);
					}

				break;

				//Securetradingxpay
				case 'securetradingxpay':

					$cc_approval = strlen($magento_order_payment_data['cc_approval']) >= 10 && substr($magento_order_payment_data['cc_approval'], 0, 10) === 'AUTH CODE:';

					if (isset($magento_order_payment_data['cc_trans_id']{0}) && $cc_approval === true)
					{
						$default_details = $payment->addDefaultDetails();
						$default_details->setValue('card_type', $magento_order_payment_data['cc_type']);
						$default_details->setValue('cc_trans_id', $magento_order_payment_data['cc_trans_id']);
						$default_details->setStatus('OK')->setStatusLabel('Payment Received');

						//All the amount is paid
						$payment->setAmount($magento_order_payment_data['amount_authorized']);
						$payment->setBaseAmount($magento_order_payment_data['base_amount_authorized']);

						//Mark order as paid
						$order_dto->setIsPaid(true);
					}

				break;

				//Charity Clear
				case 'CharityClearHosted_standard':
					$payment->setMethod('charity_clear');

					$charity_clear_data = Mage::getModel('CharityClearHosted/CharityClearHosted_Trans')->getCollection()->addFieldToFilter('orderid', $magento_order->getIncrementId())->getFirstItem()->getData();

					$success = strlen($charity_clear_data['message']) >= 9 && substr($charity_clear_data['message'], 0, 9) === 'AUTHCODE:';

					if (isset($magento_order_payment_data['last_trans_id']{0}) && $success === true && (string)$charity_clear_data['responsecode'] === '0')
					{
						$default_details = $payment->addDefaultDetails();
						$default_details->setStatus('OK')->setStatusLabel('Payment Received');

						//All the amount is paid
						$payment->setAmount($magento_order_payment_data['amount_paid']);
						$payment->setBaseAmount($magento_order_payment_data['base_amount_paid']);

						//Mark order as paid
						$order_dto->setIsPaid(true);
					}

					break;

				//Amazon payments
				case 'amazonpayments_advanced':
					$payment->setMethod('amazon_payments');
					if (isset($magento_order_payment_data['additional_information']['amazon_order_reference_id']{0}))
					{
						$default_details = $payment->addDefaultDetails();
						$default_details->setStatus('OK')->setStatusLabel('Payment Received');

						//All the amount is paid
						$payment->setAmount($magento_order_payment_data['amount_paid']);
						$payment->setBaseAmount($magento_order_payment_data['base_amount_paid']);

						//Mark order as paid
						$order_dto->setIsPaid(true);
					}

					break;

				//M2E Plugin
				case 'm2epropayment':

					//Set default
					$payment->setMethod('m2epropayment');

					if (isset($magento_order_payment_data['additional_data']))
					{
						$additional_data = unserialize($magento_order_payment_data['additional_data']);
						$sum = 0;

						if (isset($additional_data['payment_method']))
						{
							if ($additional_data['payment_method'] === 'PayPal')
							{
								$payment->setMethod('paypal');
							}
							else
							{
								if (isset($additional_data['payment_method']{0}))
								{
									$payment->setMethod($additional_data['payment_method']);
								}
								else
								{
									$payment->setMethod($additional_data['component_mode']);
								}
							}
						}

						if (count($additional_data['transactions']) > 0)
						{
							foreach ($additional_data['transactions'] AS $transaction)
							{
								$sum += $transaction['sum'];
							}
						}
						else
						{
							$sum = $magento_order_payment_data['base_amount_paid'];
						}

						$order_is_paid = netmatter_float_equals($magento_order->getGrandTotal(), $sum);
						if ($order_is_paid)
						{
							$default_details = $payment->addDefaultDetails();
							if (count($additional_data['transactions']) > 0)
							{
								$default_details->setTxId($additional_data['transactions'][count($additional_data['transactions']) - 1]['transaction_id']);
							}
							else
							{
								$default_details->setTxId($additional_data['channel_order_id']);
							}
							$default_details->setStatus('OK')->setStatusLabel('Payment Received');

							//All the amount is paid
							$payment->setAmount($magento_order_payment_data['amount_paid']);
							$payment->setBaseAmount($magento_order_payment_data['base_amount_paid']);

							//Mark order as paid
							$order_dto->setIsPaid(true);
						}
					}

					break;
				
				case 'worldpay_cc':
					$order_is_paid = netmatter_float_equals($magento_order->getGrandTotal(), $magento_order_payment->getAmountPaid());
					$order_dto->setIsPaid($order_is_paid);
					
					if ($order_is_paid)
					{
						$payment->setAmount((float)$magento_order_payment->getAmountPaid())
							->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());

						$payment->addDefaultDetails()
							->setValue('card_type', $magento_order_payment_data['cc_type'])
							->setValue('cc_trans_id', $magento_order_payment_data['cc_trans_id'])
							->setStatus('OK')
							->setStatusLabel('Payment Received');
					}
					
					break;
				
				case 'realex':
					$payment->setAmount((float)$magento_order_payment->getAmountPaid())
						->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());

					$order_dto->setIsPaid(true);
					
					break;

				case 'free':
					$payment->setAmount(0)
						->setBaseAmount(0);

					$order_dto->setIsPaid(true);

					break;

				//Default
				default:
					$payment->setAmount((float)$magento_order_payment->getAmountPaid())
						->setBaseAmount((float)$magento_order_payment->getBaseAmountPaid());

					$order_dto->setIsPaid($magento_order->getBaseTotalDue() == 0);
		}

		//Set customer details
		$customer_firstname = $magento_order->getCustomerFirstname();
		$customer_lastname = $magento_order->getCustomerLastname();
		$customer_street = preg_split('/\n/', $magento_billing_data['street']);

		$order_dto->addCustomer()
				  ->setId((int)$magento_order->getCustomerId())
				  ->setFirstname(isset($customer_firstname{0}) ? $customer_firstname : $magento_billing_data['firstname'])
				  ->setLastname(isset($customer_lastname{0}) ? $customer_lastname : $magento_billing_data['lastname'])
				  ->setEmailAddress($magento_order->getCustomerEmail())
				  ->setTelephone($magento_billing_data['telephone'])
				  ->setCompany($magento_billing_data['company'])
				  ->setStreet($customer_street[0])
				  ->setSuburb(implode("\n", array_slice($customer_street, 1, count($customer_street))))
				  ->setCity($magento_billing_data['city'])
				  ->setCounty($magento_billing_data['region'])
				  ->setPostcode($magento_billing_data['postcode'])
				  ->setCountryIsoCode($magento_billing_data['country_id']);

		//Retrieve magento order items
		$magento_order_items = $magento_order->getAllItems();

		//Retrieve magento order tax rates, and tax codes for each item
		$magento_order_tax_rates = array();
		$magento_order_items_tax = array();
		$magento_order_tax_rates_lines = Mage::getModel('tax/sales_order_tax')->getCollection()->loadByOrder($magento_order)->toArray();
		foreach ($magento_order_tax_rates_lines['items'] as $magento_order_tax_rates_line)
		{
			$magento_order_tax_rates[$magento_order_tax_rates_line['tax_id']] = $magento_order_tax_rates_line;

			//Get items tax info
			$magento_order_items_tax_lines = Mage::getModel('tax/sales_order_tax_item')->getCollection()->addFieldToFilter('tax_id', $magento_order_tax_rates_line['tax_id'])->toArray();

			foreach ($magento_order_items_tax_lines['items'] as $magento_order_items_tax_line)
			{
				$magento_order_items_tax[$magento_order_items_tax_line['item_id']] = array(
					'code' => $magento_order_tax_rates_line['code'],
					'percent' => $magento_order_tax_rates_line['percent']
				);
			}
		}

		//Try to get the correct tax rate for shipping method
		$shipping_amount = $magento_order->getShippingAmount();
		if ($shipping_amount > 0)
		{
			$shipping_tax_rate = (float)(($magento_order->getShippingInclTax() - $magento_order->getShippingAmount()) / $magento_order->getShippingAmount() * 100.0);
		}
		else
		{
			$shipping_tax_rate = 0.0;
		}

		$shipping_tax_code = null;
		foreach ($magento_order_tax_rates as $magento_order_tax_rate)
		{
			if (netmatter_float_equals((float)$magento_order_tax_rate['percent'], (float)$shipping_tax_rate))
			{
				$shipping_tax_code = $magento_order_tax_rate['code'];
				break;
			}
		}

		$magento_order_discounts = array();

		//Add order lines
		foreach ($magento_order_items as $item)
		{
			//When a configurable product is bought, 2 item lines are added, so we only need the conf line and not the simple
			if ($item->getParentItemId() > 0)
			{
				continue;
			}

			$item_dto = $order_dto->addLineItem();

			//Find item's tax info
			if (isset($magento_order_items_tax[$item->getId()]))
			{
				$item_tax_code = $magento_order_items_tax[$item->getId()]['code'];
			}
			else
			{
				$item_tax_code = 'TAX_CODE_NOT_SET';

				foreach ($magento_order_tax_rates_lines['items'] as $magento_order_tax_rates_line)
				{
					if (netmatter_float_equals($item['tax_percent'], $magento_order_tax_rates_line['percent']))
					{
						$item_tax_code = $magento_order_tax_rates_line['code'];
						break;
					}
				}
			}

			$item_sku = '';
			$item_name = '';
			$item_options = array();
			$item_product_options = $item->getProductOptions();

			//Based on type we have to extract name and sku
			switch ($item->getProductType())
			{
				//Item is configurable product
				case 'configurable':
					$item_sku = $item_product_options['simple_sku'];
					$item_name = $item_product_options['simple_name'];

					break;

				default:
					$item_sku = $item->getSku();
					$item_name = $item->getName();
			}

			//Add product options if set
			if (isset($item_product_options['options']))
			{
				foreach ($item_product_options['options'] as $item_product_options_option)
				{
					$item_options[$item_product_options_option['label']] = $item_product_options_option['print_value'];
				}
			}

			$item_dto->setProductId($item->getProductId())
					 ->setName($item_name)
					 ->setSku($item_sku)
					 ->setQuantity($item->getQtyOrdered())
					 ->setRowNet($item->getRowTotal())
					 ->setRowGross($item->getRowTotalInclTax())
					 ->setRowTax($item->getRowTotalInclTax() - $item->getRowTotal())
					 ->setTaxCode($item_tax_code);

			foreach ($item_options AS $key => $value)
			{
				$item_dto->addOption($key, $value);
			}

			//Check if item has a discount
			$item_discount_amount = $item->getDiscountAmount();

			if ($item_discount_amount > 0)
			{
				//Based on discount method, magento calculates values different. We have to find if the discount amount contains tax or not, in order to calculate the correct net and tax discount amounts
				$weee_helper = Mage::helper('weee');

				//Check if item discount contains vat or not
				if (method_exists($weee_helper, 'getRowWeeeAmountAfterDiscount'))
				{
					$item_total_calculated = $item->getRowTotal() + $item->getTaxAmount() + $item->getHiddenTaxAmount() + Mage::helper('weee')->getRowWeeeAmountAfterDiscount($item) - $item->getDiscountAmount();
				}
				else
				{
					$item_total_calculated = $item->getRowTotal() + $item->getTaxAmount() + $item->getHiddenTaxAmount() + $item->getWeeeTaxAppliedRowAmount() - $item->getDiscountAmount();
				}

				$item_discount_contains_tax = round($item->getRowTotalInclTax() - $item_discount_amount, 2) === round($item_total_calculated, 2);

				if ($item_discount_contains_tax === true) //Discount contains tax
				{
					$net_discount = $item_discount_amount / (1.0 + (float)$item->getTaxPercent() / 100.0);
					$tax_discount = $item_discount_amount - $net_discount;
				}
				else
				{
					$net_discount = $item_discount_amount;
					$tax_discount = $item_discount_amount * ((float)$item->getTaxPercent() / 100.0);
				}

				$magento_order_discounts[$item_tax_code][$item->getSku()] = array(
					'net' => $net_discount,
					'tax' => $tax_discount
				);
			}

			//Check if item is bundle
			if ($item->getProductType() === 'bundle')
			{
				$bundle_product = Mage::getModel('catalog/product')->load($item->getProductId());
				if ($bundle_product->getSkuType() === '0')
				{
					//Get bundle product's products
					$bundleSelectionsCollection = $bundle_product->getTypeInstance(true)->getSelectionsCollection(
						$bundle_product->getTypeInstance(true)->getOptionsIds($bundle_product), $bundle_product
					);

					$bundled_items = array();
					foreach($bundleSelectionsCollection as $option)
					{
						$bundled_items[$option->option_id][$option->selection_id] = array(
							'product_id' => $option->product_id,
							'sku' => $option->sku,
							'name' => $option->name
						);
					}

					$bundle_data = unserialize($item->getData('product_options'));

					//Add bundle options as separate order lines
					foreach ($bundle_data['bundle_options'] AS $bundle_option)
					{
						foreach ($bundle_option['value'] AS $bundle_option_value_key => $bundle_option_value)
						{
							$option_value_id = $bundle_data['info_buyRequest']['bundle_option'][$bundle_option['option_id']];
							if (is_array($option_value_id))
							{
								$option_value_id = $option_value_id[$bundle_option_value_key];
							}

							$bundled_item = $bundled_items[$bundle_option['option_id']][$option_value_id];

							$bundle_item_dto = $order_dto->addLineItem();
							$bundle_item_dto->setProductId($bundled_item['product_id'])
											 ->setName($bundled_item['name'])
											 ->setSku($bundled_item['sku'])
											 ->setQuantity($bundle_option_value['qty'] * $item->getQtyOrdered())
											 ->setRowNet(0.00)
											 ->setRowGross(0.00)
											 ->setRowTax(0.00)
											 ->setTaxCode($item_tax_code);
						}
					}
				}
			}
		}

		//Check for shipping discount
		$shipping_discount_amount = $magento_order->getShippingDiscountAmount();
		if ($shipping_discount_amount > 0)
		{
			$shipping_discount_tax_code = isset($shipping_tax_code) ? $shipping_tax_code : 'order-discount';

			$magento_order_discounts[$shipping_discount_tax_code]['shipping_cost'] =  array(
				'net' => $magento_order->getShippingDiscountAmount(),
				'tax' => ($magento_order->getShippingInclTax() - $magento_order->getShippingAmount()) - $magento_order->getShippingTaxAmount()
			);
		}

		//Combine discounts with same tax codes
		$order_discounts = array();
		foreach($magento_order_discounts AS $tax_code => $magento_order_discount)
		{
			$discount_label = trim($magento_order->getDiscountDescription());
			if ($discount_label === '')
			{
				$discount_label = 'Order discount';
			}

			//Calculate net, tax and gross
			$net = 0.0;
			$tax = 0.0;
			foreach ($magento_order_discount as $d)
			{
				$net += $d['net'];
				$tax += $d['tax'];
			}

			if (!isset($order_discounts[$tax_code]))
			{
				$order_discounts[$tax_code] = array(
					'label' => $discount_label,
					'net' => 0.00,
					'tax' => 0.00,
					'gross' => 0.00
				);
			}

			$order_discounts[$tax_code]['net'] += $net;
			$order_discounts[$tax_code]['tax'] += $tax;
			$order_discounts[$tax_code]['gross'] += $net + $tax;
			if ($order_discounts[$tax_code]['label'] !== $discount_label)
			{
				$order_discounts[$tax_code]['label'] .= ', ' .  $discount_label;
			}
		}

		//Append discounts to DTO
		foreach ($order_discounts as $order_discount_tax_code => $order_discount)
		{
			$discount = $order_dto->addDiscount();
			$discount->setTaxCode($order_discount_tax_code);
			$discount->setLabel($order_discount['label']);
			$discount->setNet($order_discount['net'])->setTax($order_discount['tax'])->setGross($order_discount['gross']);
		}

		//Add shipping
		$order_shipping_dto = $order_dto->addShipping();
		$order_shipping_dto->setMethod($magento_order->getShippingMethod())
							->setMethodLabel($magento_order->getShippingDescription())
							->setNet($magento_order->getShippingAmount())
							->setTax($magento_order->getShippingInclTax() - $magento_order->getShippingAmount())
							->setGross($magento_order->getShippingInclTax())
							->setTaxCode($shipping_tax_code);

		//Check for gift message
		$gift_message_id = $magento_order->getGiftMessageId();
		if ($gift_message_id > 0)
		{
			$gift_message = Mage::getModel('giftmessage/message')->load($gift_message_id);
			$order_shipping_dto->setGiftMessage($gift_message->getMessage(), $gift_message->getRecipient(), $gift_message->getSender());
		}

		//Send the order to the Bridge
		return $bridge->sendOrder($order_dto) === true;
	}

	/**
	* Checks if the given product id exists for the given website id
	*
	* @param $product_id the id of the product
	* @param $website_id the id of the store
	* @return boolean
	*/
	public function productWebsiteExists($product_id, $website)
	{
		$result = $this->db()->query("SELECT 1 FROM " . $this->getTablePrefix() . "catalog_product_website WHERE product_id = :product_id AND website_id = :website_id;", array('product_id' => $product_id, 'website_id' => $website_id));
		$row = $result->fetch(PDO::FETCH_ASSOC);
		return $row !== false;
	}
}
