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

$writerLogger = new \Zend\Log\Writer\Stream(BP . '/var/log/LS-Spam-Customer.log');
$lsLogger = new \Zend\Log\Logger();
$lsLogger->addWriter($writerLogger);

try {


    $now = new \DateTime();

    // Object Manager
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

    // Resource Collection
    $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection = $resource->getConnection();

    $registry = $objectManager->get('Magento\Framework\Registry');
    $registry->register('isSecureArea', true);

    echo "Developer Attension Needed";

    exit();
    // Write File
    $writeFile = fopen("Spam-Customer.csv", "w");

    $data[0] = ['magento_customer_id',
        'total_address_count',
        'total_orders_count',
        'created_date',
        'erp_customer_number',
        'email',
        'firstname',
        'lastname',
        'company',
        'street',
        'city',
        'region',
        'country_id',
        'telephone', 'status'];

    fputcsv($writeFile, $data[0]);

    $sql = "SELECT parent_id as magento_customer_id, count(parent_id) as total_address_count, created_at as created_date, firstname, lastname, company, street, city, region, country_id, telephone FROM customer_address_entity where city = 'Minsk' GROUP BY parent_id;";

    $sqlResult = $connection->fetchAll($sql);

    if(!empty($sqlResult)) {
        foreach ($sqlResult as $caResult) {

            $magento_customer_id = $caResult['magento_customer_id'];
            $total_address_count = $caResult['total_address_count'];
            $created_date = $caResult['created_date'];
            $firstname = $caResult['firstname'];
            $lastname = $caResult['lastname'];
            $company = $caResult['company'];
            $street = $caResult['street'];
            $city = $caResult['city'];
            $region = $caResult['region'];
            $country_id = $caResult['country_id'];
            $telephone = $caResult['telephone'];

            $erpCustomerNumber = null;
            $email = null;
            $total_orders_count = null;

            if(!empty($magento_customer_id)) {
                $customerFactory = $objectManager->get('\Magento\Customer\Model\CustomerFactory')->create();
                $customerFactory->load($magento_customer_id);
                $erpCustomerNumber =  $customerFactory->getErpCustomerNumber();
                $email =  $customerFactory->getEmail();

                $salesOrderSql = "select count(*) as total_order_count from sales_order as so where so.customer_id = '".$magento_customer_id."';";
                $salesOrderSqlResult = $connection->fetchAll($salesOrderSql);

                foreach ($salesOrderSqlResult as $soResult) {
                    $total_orders_count = $soResult['total_order_count'];
                }
            } else {
                echo "Empty Customer ID";
            }

            $status = null;

            if(empty($total_orders_count)) {
                 //$status = "Deleted";
            } else {
                //$status = "Skipped";
            }

            $data = [];

            $data = [$magento_customer_id,
                $total_address_count,
                $total_orders_count,
                $created_date,
                $erpCustomerNumber,
                $email,
                $firstname,
                $lastname,
                $company,
                $street,
                $city,
                $region,
                $country_id,
                $telephone,
                $status];

            fputcsv($writeFile, $data);
        }
    }
} catch (Exception $e) {

}

echo "DONE";
exit();
