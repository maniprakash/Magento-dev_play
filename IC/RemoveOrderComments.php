<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Magento Document Root
require_once $_SERVER['PWD'] . '/../app/bootstrap.php';

// Initialize Bootstrap class
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// for remove Area code is not set error
$objectManager->get('\Magento\Framework\App\State')->setAreaCode('frontend');

$writerLogger = new \Zend\Log\Writer\Stream(BP . '/var/log/Remove-Order-Comments.log');
$lsLogger = new \Zend\Log\Logger();
$lsLogger->addWriter($writerLogger);

try {

    echo "Entry Restricted";
    exit();

    // Object Manager
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

    // Resource Collection
    $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection = $resource->getConnection();

    $writeFile = fopen("Remove-Order-Comments.csv", "w");
    $data[0] = ['order_id', 'increment_id', 'total_order_comment_count', 'status'];
    fputcsv($writeFile, $data[0]);

    // Sales Order Resource Collection
    $OrderFactory = $objectManager->create('Magento\Sales\Model\ResourceModel\Order\CollectionFactory');
    $orderCollection = $OrderFactory->create()->addFieldToSelect(array('*'));
    $orderCollection->addFieldToFilter('increment_id', array('in' => array('9000000499','9000000505', '9000000607', '2000004087', '9000000625', '9000000655')));

    // SINGLE ID TO DELETE
    //$orderCollection->addFieldToFilter('increment_id', '9000000481');

    // DO NOT RUN THIS SCRIPT - HARMFUL
   /* $orderSQL = "select entity_id, state, status, increment_id, created_at, ext_order_id, ext_customer_id from sales_order where ext_order_id is NULL;";
    $orderCollection = $connection->fetchAll($orderSQL);*/

    foreach($orderCollection as $order) {

        $orderId = null;
        $orderIncrementId =null;
        $status = null;

        $orderId = $order->getId();
        $orderIncrementId = $order->getIncrementId();

       /* $orderId = $order['entity_id'];
        $orderIncrementId = $order['increment_id'];*/

        if (!empty($orderId)) {

            $orderCommentCountSQL = "SELECT count(*) as total_order_comment_count from sales_order_status_history where parent_id ='". $orderId ."' AND entity_name ='order';";
            $result = $connection->fetchAll($orderCommentCountSQL);

            $totalCommentCount = $result[0]['total_order_comment_count'];

            if(!empty($totalCommentCount)) {
           //     $orderCommentDeleteQuery = "DELETE FROM sales_order_status_history WHERE parent_id ='". $orderId ."' and `entity_name` = 'order'";
              //  $connection->query($orderCommentDeleteQuery);
                $status = 'Deleted';
            } else {
                $status = 'Not Deleted';
            }

            $data = [];
            $data = [$orderId, $orderIncrementId, $totalCommentCount, $status];
            fputcsv($writeFile, $data);
        } else {
            echo "Order ID is empty";
        }
    }
} catch (Exception $e) {
}

echo "DONE";
exit();