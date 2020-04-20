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

$writerLogger = new \Zend\Log\Writer\Stream(BP . '/var/log/LS-Address-Change.log');
$lsLogger = new \Zend\Log\Logger();
$lsLogger->addWriter($writerLogger);

$writer = new \Zend\Log\Writer\Stream(BP . '/var/log/LS-Address-Update.log');
$logger = new \Zend\Log\Logger();
$logger->addWriter($writer);

try {

    $now = new \DateTime();

    // Object Manager
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

    // Step 0
    $file = fopen("customer_address_check_data.csv","r");

    $noNameChangeArray = [];
    $nameChangeArray = [];

    while(!feof($file))
    {
        $fileRow = fgetcsv($file);

        $websiteId = $fileRow[0];;
        $email = $fileRow[1];
        $adId = $fileRow[2];
        $firstname = $fileRow[3];
        $lastname = $fileRow[4];
        $magentoName = trim(substr($firstname . $lastname,0,36));

        $mageErpCustomerNumber = null;
        $mageCustomerId = null;

        if(!empty($email)) {
            $customerObject = $objectManager->create('Magento\Customer\Model\CustomerFactory')->create();
            $customerObject->setWebsiteId($websiteId)->loadByEmail($email);
            $mageCustomerId = $customerObject->getId();
            $mageErpCustomerNumber = $customerObject->getErpCustomerNumber();
        } else {
            continue;
        }

        // Step 0.1
        $m3Name = null;

        if(!empty($mageErpCustomerNumber)) {

            $m3Query = "SELECT OPCUNM, OPADID from M3FDBGRD.MVXJDTA.OCUSAD WHERE OPADID NOT LIKE '#%' AND OPADRT = 1 AND OPADID LIKE 'E%' AND OPADID IN ('".$adId."') and OPCUNO = '".$mageErpCustomerNumber."';";

            $postData = null;
            $postData['query'] = $m3Query;
            $erpHelper = $objectManager->create('LeanSwift\Econnect\Helper\Erpapi');
            $erpResponse = $erpHelper->doRequest($postData, \LeanSwift\Iconex\Helper\Query::GENERIC_QUERY_METHOD);

            if (@array_key_exists('result', $erpResponse['data'])) {
                if (!empty($erpResponse['data']['result'])) {
                    $erpResponseData = $erpResponse['data']['result'];
                    foreach ($erpResponseData as $resData) {
                        $m3Name = $resData['OPCUNM'];
                        if(empty($m3Name)) {
                            $log = 'Empty Response from M3 - Step 0 -'.$mageCustomerId.'-'.$adId;
                            $lsLogger->info($log);
                            continue;
                        }
                    }
                } else {
                    $log = 'No Response from M3 - Step 0 -'.$mageCustomerId.' -- '.$email.' -- '.$mageErpCustomerNumber.' -- '.$adId;
                    $lsLogger->info($log);
                }
            } else {
                $log = 'No Response from M3 - Step 0 -'.$mageCustomerId.' -- '.$email.' -- '.$mageErpCustomerNumber.' -- '.$adId;
                $lsLogger->info($log);
            }
        } else {
            $log = 'ERP Customer number not found - Step 0 -'.$mageCustomerId.' -- '.$email.' -- '.$mageErpCustomerNumber.' -- '.$adId;
            $lsLogger->info($log);
        }

        if(strcmp($m3Name, $magentoName) == 0) {
            //$noNameChangeArray[$mageCustomerId.'-'.$adId] = $email;
            $log = 'No Name Change -- '.$mageCustomerId.' -- '.$email.' -- '.$mageErpCustomerNumber.' -- '.$adId;
            $lsLogger->info($log);
        } else {
            //$nameChangeArray[$mageCustomerId.'-'.$adId] = $email;
            $log = 'Yes Name Change -- '.$mageCustomerId.' -- '.$email.' -- '.$mageErpCustomerNumber.' -- '.$adId;
            $lsLogger->info($log);
        }
    }

    echo "<pre>";
    /*print_r($noNameChangeArray);
    print_r($nameChangeArray);*/
    echo "DONE";
    exit();

    fclose($file);
    exit();

     // Resource Collection
    $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection = $resource->getConnection();

    // Sales Order Resource Collection
    $OrderFactory = $objectManager->create('Magento\Sales\Model\ResourceModel\Order\CollectionFactory');
    $orderCollection = $OrderFactory->create()->addFieldToSelect(array('*'));
    //$orderCollection->addFieldToFilter('created_at', ['lteq' => $now->format('Y-m-d')])
    //    ->addFieldToFilter('created_at', ['gteq' => $now->format('2020-03-02')])
    $orderCollection->addFieldToFilter('customer_id', '3092');

    /*echo count($orderCollection);
    exit();*/

    $orderIds = [];
    $orderAddress = [];
    $erpAdIdArray = [];
    $listOfAddressEntityIds = [];
    $erpAdIdValidation = [];

    foreach($orderCollection as $order) {

        // Step 1
        $erpCustomerNumber = null;
        $customerId = null;
        $customerId = $order->getCustomerId();
        $orderId = $order->getId();
        $orderIds[$orderId] = $order->getIncrementId();

        if(!empty($customerId)) {
            $customerObject = $objectManager->create('Magento\Customer\Model\CustomerFactory')->create()->load($customerId);
            $erpCustomerNumber = $customerObject->getErpCustomerNumber();
        }

        // New Query
        $soSql = "select firstname, lastname, CONCAT(TRIM(SUBSTRING(CONCAT(firstname,lastname), 1, 36)), trim(SUBSTRING_INDEX(SUBSTRING_INDEX(street, '\n', 1 ), 0, 36)), case when INSTR(street, '\n') != 0 then trim(SUBSTRING(SUBSTRING_INDEX(street, '\n', -1 ), 1, 36)) else '' end, trim(SUBSTRING(city, 1, 20)), trim(SUBSTRING(postcode, 1, 10))) as m3input from sales_order so left join sales_order_address soa on so.entity_id = soa.parent_id and so.increment_id = '". $order->getIncrementId()  ."' where address_type = 'shipping' order by increment_id desc";
        $result = $connection->fetchAll($soSql);

        // Step 2
        if(!empty($result)) {
            $orderAddress[$order->getId()] =  $result[0];

            // Parse Result
            $orderAddressFname = $result[0]['firstname'];
            $orderAddressLname = $result[0]['lastname'];
            $m3Input = $result[0]['m3input'];

            $m3Query = "SELECT CONCAT(OPCUNM,OPCUA1,OPCUA2,OPTOWN,OPPONO), OPADID from M3FDBGRD.MVXJDTA.OCUSAD WHERE OPADID NOT LIKE '#%' AND OPADRT = 1 AND OPADID LIKE 'E%' AND OPCUNO = '".$erpCustomerNumber."' AND CONCAT(OPCUNM,OPCUA1,OPCUA2,OPTOWN,OPPONO) =  '".$m3Input."';";

            $postData = null;
            $postData['query'] = $m3Query;
            $erpHelper = $objectManager->create('LeanSwift\Econnect\Helper\Erpapi');
            $erpResponse = $erpHelper->doRequest($postData, \LeanSwift\Iconex\Helper\Query::GENERIC_QUERY_METHOD);

            $tempArray = [];
            $erpAdId = null;

            if (@array_key_exists('result', $erpResponse['data'])) {

                if (!empty($erpResponse['data']['result'])) {

                    $erpResponseData = $erpResponse['data']['result'];
                    foreach ($erpResponseData as $adIdArray) {
                        $tempArray[] = $adIdArray['OPADID'];
                    }
                    // Input for Step 3
                    $erpAdId = "'" . implode("', '", $tempArray) . "'";
                    $erpAdIdArray[$order->getId()] = $erpAdId;
                } else {
                    $erpAdIdArray[$order->getId()] = 'No Response from M3 - CUNO - '. $erpCustomerNumber .' - '.$m3Input;
                }
            } else {
               $erpAdIdArray[$order->getId()] = 'No Response from M3 - CUNO - '. $erpCustomerNumber .' - '.$m3Input;
            }
        }

        // Validation
        if(in_array($erpAdId, $erpAdIdValidation)) {
            $log = 'OID -- ' . $orderId . ' -- OFN -- ' . $orderAddressFname .' -- OLN -- ' .$orderAddressLname .' -- ADID -- ' .$erpAdId .' -- Skipped';
            $logger->info($log);

            continue;
        }

        // Step 3
        $i = 1;
        if(!empty($erpAdId) && !empty($customerId)) {
            $finalSql = "SELECT cae.firstname, cae.lastname, cae.entity_id FROM customer_address_entity AS cae LEFT JOIN `customer_address_entity_varchar` AS `varchar_address` ON cae.entity_id = varchar_address.entity_id AND (varchar_address.attribute_id = '162' and value like 'E%') WHERE parent_id = '" .$customerId. "' AND value IN (".$erpAdId.")";
            $resultArray = $connection->fetchAll($finalSql);

            if(!empty($resultArray)) {
                foreach ($resultArray as $customerAddressArray) {

                    $resultArrayFname = $customerAddressArray['firstname'];
                    $resultArrayLname = $customerAddressArray['lastname'];
                    $addressEntityId = $customerAddressArray['entity_id'];

                    if(strcmp($orderAddressFname, $resultArrayFname) == 0 && strcmp($orderAddressLname, $resultArrayLname) == 0) {
                        //echo "NOT Eligible for Name Change";
                        $listOfAddressEntityIds[$i .'-'. $orderId] = 'NOT Eligible for Name Change -'. $addressEntityId;
                        $i++;

                        $log = 'OID -- ' . $orderId . ' -- OFN -- ' . $orderAddressFname .' -- OLN -- ' .$orderAddressLname .' -- ADID -- ' .$erpAdId .' -- CFN -- ' .$resultArrayFname .' -- CLN -- ' .$resultArrayLname .' -- Updated - NO -- EID -- '. $addressEntityId;
                        $logger->info($log);

                    } else {
                        //echo "Eligible for Name Change";
                        $updateSQL = "UPDATE `customer_address_entity` SET `firstname` = '". $orderAddressFname ."', `lastname` = '". $orderAddressLname ."' WHERE `customer_address_entity`.`entity_id` = $addressEntityId";
                        $connection->query($updateSQL);

                        // Final result to know how many lines affected.
                        $listOfAddressEntityIds[$i .'-'. $orderId] = $addressEntityId;
                        $i++;

                        $log = 'OID -- ' . $orderId . ' -- OFN -- ' . $orderAddressFname .' -- OLN -- ' .$orderAddressLname .' -- ADID -- ' .$erpAdId .' -- CFN -- ' .$resultArrayFname .' -- CLN -- ' .$resultArrayLname .' -- Updated - YES -- EID -- '. $addressEntityId;
                        $logger->info($log);
                    }
                }
            }
        } else {
            $listOfAddressEntityIds[$i .'-'. $orderId] = 'Step 3 Condition Failed';
            $i++;

            $log = 'OID -- ' . $orderId . ' -- OFN -- ' . $orderAddressFname .' -- OLN -- ' .$orderAddressLname .' -- ADID - NOT IN M3 -- Updated - NO';
            $logger->info($log);
        }

        // Store adid on this array to avoid the redundant data update.
        $erpAdIdValidation[] =  $erpAdId;
    }

    echo "<pre>";
 //   print_r($orderAddress);
    //print_r($erpAdIdArray);
    print_r($listOfAddressEntityIds);
    exit();

    //echo "<pre>";
    //print_r($orderAddress);
    //print_r($erpResponseData);
    //print_r($tempArray);
    //print_r($resultArray);
    //exit();

} catch(\Exception $e) {
    echo $e->getMessage();
    exit;
}

echo "DONE";
exit();