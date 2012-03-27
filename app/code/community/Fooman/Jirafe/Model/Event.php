<?php
    /**
     * NOTICE OF LICENSE
     *
     * This source file is subject to the Open Software License (OSL 3.0)
     * that is bundled with this package in the file LICENSE.txt.
     * It is also available through the world-wide-web at this URL:
     * http://opensource.org/licenses/osl-3.0.php
     *
     * @package     Fooman_Jirafe
     * @copyright   Copyright (c) 2012 Jirafe Inc (http://www.jirafe.com)
     * @copyright   Copyright (c) 2012 Fooman Limited (http://www.fooman.co.nz)
     * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
     */

class Fooman_Jirafe_Model_Event extends Mage_Core_Model_Abstract
{
    //TODO: these could move into the php-client
    const JIRAFE_ACTION_ORDER_CREATE    = 'orderCreate';
    const JIRAFE_ACTION_ORDER_UPDATE    = 'orderUpdate';
    const JIRAFE_ACTION_ORDER_IMPORT    = 'orderImport';
    const JIRAFE_ACTION_INVOICE_CREATE  = 'invoiceCreate';
    const JIRAFE_ACTION_INVOICE_UPDATE  = 'invoiceUpdate';
    const JIRAFE_ACTION_SHIPMENT_CREATE = 'shipmentCreate';
    const JIRAFE_ACTION_REFUND_CREATE   = 'refundCreate';
    const JIRAFE_ACTION_NOOP            = 'noop';

    const JIRAFE_ORDER_STATUS_NEW               = 'new';
    const JIRAFE_ORDER_STATUS_PAYMENT_PENDING   = 'pendingPayment';
    const JIRAFE_ORDER_STATUS_PROCESSING        = 'processing';
    const JIRAFE_ORDER_STATUS_COMPLETE          = 'complete';
    const JIRAFE_ORDER_STATUS_CLOSED            = 'closed';
    const JIRAFE_ORDER_STATUS_CANCELLED         = 'canceled';
    const JIRAFE_ORDER_STATUS_HELD              = 'holded';
    const JIRAFE_ORDER_STATUS_PAYMENT_REVIEW    = 'paymentReview';

    protected $_eventPrefix = 'foomanjirafe_event';
    protected $_eventObject = 'jirafeevent';

    protected function _construct ()
    {
        $this->_init('foomanjirafe/event');
    }

    protected function _beforeSave()
    {
        $this->setGeneratedByJirafeVersion((string) Mage::getConfig()->getModuleConfig('Fooman_Jirafe')->version);
        parent::_beforeSave();
    }

    /**
     * there is no afterCommitCallback on earlier
     * versions, use the closest alternative
     * @see afterCommitCallback
     */
    protected function _afterSave()
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->afterCommitCallback();
        }
    }

    public function afterCommitCallback()
    {
        //ping Jirafe
        Mage::getSingleton('foomanjirafe/jirafe')->sendCMB($this->getSiteId());
        return parent::afterCommitCallback();
    }
    
    public function getEventDataFromOrder($order)
    {
        return array (
            'orderId'           => $order->getIncrementId(),
            'status'            => $this->_getOrderStatus($order),
            'customerId'        => md5(strtolower(trim($order->getCustomerEmail()))),
            'visitorId'         => $this->_getJirafeVisitorId($order),
            'time'              => strtotime($order->getCreatedAt()),
            'grandTotal'        => $order->getBaseGrandTotal(),
            'subTotal'          => $order->getBaseSubtotal(),
            'taxAmount'         => $order->getBaseTaxAmount(),
            'shippingAmount'    => $order->getBaseShippingAmount(),
            'discountAmount'    => abs($order->getBaseDiscountAmount()),
            'items'             => $this->_getItems($order)
        );
    }

    public function orderCreateOrUpdate($order)
    {
        $saveEvent = false;
        if ($order->getJirafeIsNew() == 1) {
            $saveEvent = true;
            $this->setAction(Fooman_Jirafe_Model_Event::JIRAFE_ACTION_ORDER_CREATE);
            $eventData = $this->getEventDataFromOrder($order);
            $order->setJirafeIsNew(2);
        } else {
            if($order->getOrigData()) {
                if($order->getState() != $order->getOrigData('state')) {
                    //only create an update event when the state has changed
                    //TODO: check against final spec if there are any other changes we are interested in
                    $saveEvent = true;
                }
            } elseif ($order->getJirafeIsNew() != 2 && $order->getState() != Mage_Sales_Model_Order::STATE_NEW) {
                //During order creation Magento saves a new order twice
                //the above check prevents an order_create AND order_update event for a new order
                $saveEvent = true;
            }
            if($saveEvent) {
                $this->setAction(Fooman_Jirafe_Model_Event::JIRAFE_ACTION_ORDER_UPDATE);
                $eventData = array (
                    'orderId'   =>$order->getIncrementId(),
                    'status'    =>$this->_getOrderStatus($order)
                );
            }
        }
        if($saveEvent) {
            $this->setSiteId(Mage::helper('foomanjirafe')->getStoreConfig('site_id', $order->getStoreId()));
            $this->setEventData(json_encode($eventData));
            try {
                $this->save();
            } catch (Exception $e) {
                Mage::logException($e);
                Mage::helper('foomanjirafe')->debug($e->getMessage());
            }
        }
    }

    public function creditmemoCreateOrUpdate($creditmemo)
    {
        if ($creditmemo->getJirafeIsNew() == 1) {
            $this->setAction(Fooman_Jirafe_Model_Event::JIRAFE_ACTION_REFUND_CREATE);
            $eventData = array(
                'refundId'                  => $creditmemo->getIncrementId(),
                'orderId'                   => $creditmemo->getOrder()->getIncrementId(),
                'time'                      => strtotime($creditmemo->getCreatedAt()),
                'grandTotal'                => $creditmemo->getBaseGrandTotal(),
                'subTotal'                  => $creditmemo->getBaseSubtotal(),
                'taxAmount'                 => $creditmemo->getBaseTaxAmount(),
                'shippingAmount'            => $creditmemo->getBaseShippingAmount(),
                'discountAmount'            => $creditmemo->getBaseDiscountAmount(),
                'items'                     => $this->_getItems($creditmemo)
            );
            $this->setSiteId(Mage::helper('foomanjirafe')->getStoreConfig('site_id', $creditmemo->getStoreId()));
            $this->setEventData(json_encode($eventData));
            try {
                $this->save();
            } catch (Exception $e) {
                Mage::logException($e);
                Mage::helper('foomanjirafe')->debug($e->getMessage());
            }
            $creditmemo->setJirafeIsNew(2);
        }
    }
    
    public function orderImportCreate($siteId, $orders)
    {
        $eventData = array('orders' => array());
        foreach ($orders as $order) {
            Mage::helper('foomanjirafe')->debug('Adding order '.$order->getIncrementId().' to orderImport batch');
            $eventData['orders'][] = $this->getEventDataFromOrder($order);
        }

        try {
            $this->setAction(Fooman_Jirafe_Model_Event::JIRAFE_ACTION_ORDER_IMPORT);
            $this->setSiteId(Mage::helper('foomanjirafe')->getStoreConfig('site_id', $order->getStoreId()));
            $this->setEventData(json_encode($eventData));
            $this->save();
            
            foreach ($orders as $order) {
                $order->setJirafeIsNew(2)->setJirafeExportStatus(1)->save();
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::helper('foomanjirafe')->debug($e->getMessage());
        }
        
    }

    protected function _getJirafeVisitorId($order)
    {
        if ($order->getJirafePlacedFromFrontend()) {
            $visitorId = $order->getJirafeVisitorId();
        } else {
            $visitorId = null;
        }
        unset($order);
        return $visitorId;
    }

    protected function _getItems($salesObject)
    {
        $returnArray = array();
        $isOrder = ($salesObject instanceof Mage_Sales_Model_Order);
        foreach ($salesObject->getAllItems() as $item)
        {
            if (!$item->getParentItemId()) {
                $product = Mage::getModel('catalog/product')->load($item->getProductId());
                $returnArray[] = array(
                    'sku' => $product->getSku(),
                    'name' => $item->getName(),
                    'category' => Mage::helper('foomanjirafe')->getCategory($product),
                    'price' => $item->getBasePrice(),
                    'quantity' => $isOrder ? $item->getQtyOrdered() : $item->getQty()
                );
            }
        }
        return $returnArray;
    }

    protected function _getOrderStatus($order)
    {
        $state = $order->getState();
        unset($order);
        switch ($state) {
            case Mage_Sales_Model_Order::STATE_NEW:
                $status = self::JIRAFE_ORDER_STATUS_NEW;
                break;
            case Mage_Sales_Model_Order::STATE_PENDING_PAYMENT:
                $status = self::JIRAFE_ORDER_STATUS_PAYMENT_PENDING;
                break;
            case Mage_Sales_Model_Order::STATE_PROCESSING:
                $status = self::JIRAFE_ORDER_STATUS_PROCESSING;
                break;
            case Mage_Sales_Model_Order::STATE_COMPLETE:
                $status = self::JIRAFE_ORDER_STATUS_COMPLETE;
                break;
            case Mage_Sales_Model_Order::STATE_CLOSED:
                $status = self::JIRAFE_ORDER_STATUS_CLOSED;
                break;
            case Mage_Sales_Model_Order::STATE_CANCELED:
                $status = self::JIRAFE_ORDER_STATUS_CANCELLED;
                break;
            case Mage_Sales_Model_Order::STATE_HOLDED:
                $status = self::JIRAFE_ORDER_STATUS_HELD;
                break;
            case Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW:
                $status = self::JIRAFE_ORDER_STATUS_PAYMENT_REVIEW;
                break;
        }
        return $status;
    }

}
