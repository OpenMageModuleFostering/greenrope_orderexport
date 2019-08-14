<?php
class GreenRope_OrderExport_Model_Observer
{
    /**
     * Exports an order after it is placed
     *
     * @param Varien_Event_Observer $observer observer object
     *
     * @return boolean
     */
    public function exportOrder(Varien_Event_Observer $observer)
    {
		$order = $observer->getEvent()->getOrder();

/* 		Mage::log(
			$order->debug(), //Objects extending Varien_Object can use this
			Zend_Log::DEBUG,  //Log level
			'order_export.log',//Log file name; if blank, will use config value (system.log by default)
			true              //force logging regardless of config setting
		);
 */

        // $dirPath = Mage::getBaseDir('var') . DS . 'export';

        // file_put_contents($dirPath. DS .$order->getIncrementId().'.txt', var_export($order, 1));

		Mage::getModel('greenrope_orderexport/export')->exportOrder($order);
 		return true;

    }
}
?>
