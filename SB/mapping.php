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

$writerLogger = new \Zend\Log\Writer\Stream(BP . '/var/log/LS-Category-Mapping.log');
$lsLogger = new \Zend\Log\Logger();
$lsLogger->addWriter($writerLogger);

try {

    // Object Manager
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

    // Resource Collection
    $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
    $connection = $resource->getConnection();

    $readFile = fopen("SkiBygg-Categories-Concatenated-UTF8.csv", "r");

    $i = 1;
    $j = 1;
    while (!feof($readFile)) {

        $fileRow = fgetcsv($readFile);

        $secondLevelCategoryName = trim($fileRow[1]); //B - 2nd Level
        $fourthLevelCategoryName = trim($fileRow[3]); //D
        $m3SourceValue = $fileRow[24]; //Y - Concatenated

        // Filter 2nd level category
        $categoryFilterFactory = $objectManager->create('Magento\Catalog\Model\ResourceModel\Category\CollectionFactory');
        $categoryFilterCollection = $categoryFilterFactory->create();
        $categoryFilterCollection->addAttributeToSelect('*')
            ->addAttributeToFilter('name', $secondLevelCategoryName)
            ->addAttributeToFilter('level', 3)
            ->load();

        $parent_id = null;
        if(!empty($categoryFilterCollection) && count($categoryFilterCollection) == 1) {
            foreach ($categoryFilterCollection as $res) {
                $parent_id = $res['entity_id'];
            }
        }

        if(empty($parent_id)) {
            continue;
        }

        $categoryFactory = $objectManager->create('Magento\Catalog\Model\ResourceModel\Category\CollectionFactory');
        $collection = $categoryFactory->create();
        $collection->addAttributeToSelect('*')
            ->addAttributeToFilter('name', $fourthLevelCategoryName)
            ->addAttributeToFilter('parent_id', $parent_id)->load();

        if(!empty($collection) && count($collection) > 0) {

            foreach ($collection as $result) {
                $categoryId = $result['entity_id'];
                $name = $result['name'];

                if((trim($name) == $fourthLevelCategoryName) && !empty($m3SourceValue)) {
                    $array['_1587130248610_'.$j] = array('m3_category_source_value' => $m3SourceValue, 'magento_categories' => $categoryId);

                    $log = ' Category Mapping Success -- '. $fourthLevelCategoryName . " -- ".$i;
                    $lsLogger->info($log);

                    $j++;
                } else {
                    $log = ' Category Name Mismatch -- '. $fourthLevelCategoryName . " -- ".$i;
                    $lsLogger->info($log);
                }
            }
        } else {
            $log = ' Category Not Found -- '. $fourthLevelCategoryName . " -- ".$i;
            $lsLogger->info($log);
        }

        $i++;
    }

    if(!empty($array)) {
        $jsonDecode = json_encode($array);
        $finalSql = "UPDATE `core_config_data` SET `value` = '". $jsonDecode ."' WHERE `path` = 'leanswift/category_sync/m3_category_mapping'";
        $connection->query($finalSql);
    }

} catch (Exception $e) {

}
echo "DONE";
exit();
