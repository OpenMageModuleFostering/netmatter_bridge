<?php
/**
* @class Netmatter_Bridge_Model_Order_Observer
*
* Contains observers that are executed to send the order to bridge
*/
class Netmatter_Bridge_Model_Order_Observer
{
	/**
	* Sends the order to the bridge
	*
	* @param $magento_order the order to send
	* @return self
	*/
	private function sendOrder($magento_order)
	{
		//Check flag
		if (Mage::registry('netmatter_bridge_disable_order_observer') === 1)
		{
			return $this;
		}

		Mage::helper('bridge/data')->sendOrder($magento_order);

		return $this;
	}

	/**
	* Handles new order placement event.
	*
	* Passes order information and customer information to the Bridge.
	*
	* This method is called internally when the Magento event, 'sales_order_place_after' is dispatched.
	*
	* @param Varien_Event_Observer A magento event object
	* @return self
	*/
	public function sales_order_place_after(Varien_Event_Observer $observer)
	{
		return $this->sendOrder($observer->getEvent()->getOrder());
	}

	/**
	* Handles order save after event
	*
	* @param Varien_Event_Observer A magento event object
	* @return self
	*/
	public function sales_order_save_after(Varien_Event_Observer $observer)
	{
		$order = $observer->getEvent()->getOrder();

		if ($order->dataHasChangedFor('status') !== true)
		{
			return $this;
		}

		return $this->sendOrder($observer->getEvent()->getOrder());
	}

	/**
	* Handles order payment event
	*
	* Passes order information and customer information to the Bridge.
	*
	* This method is called internally when the Magento event, 'sales_order_payment_pay' is dispatched.
	*
	* @param Varien_Event_Observer A magento event object
	* @return self
	*/
	public function sales_order_payment_pay(Varien_Event_Observer $observer)
	{
		return $this->sendOrder($observer->getEvent()->getPayment()->getOrder());
	}
}
