<?php
/**
 * Magento Script v5.0
 *
 *
 * @copyright StyleMeTV Limited
 */

/**
 * Include Magento files
 */
require('app/Mage.php');
umask(0);
Mage::app('default');

abstract class Smtv_Export_Abstract extends Varien_Object {

    static public $storeId = null;
    protected $_canLog = false;
    protected $_configAttr = array('size' => 'size', 'colour' => 'colour', 'color' => 'colour', 'material' => 'colour');

    /**
     * Construct the class & set the store ID
     *
     */
    public function __construct() {
        parent::__construct();
        self::$storeId = Mage::app()->getStore()->getId();
    }

    public function startLogging() {
        $this->_canLog = true;
    }

    /**
     * Strip empty items from the array
     *
     * @param array &$data
     */
    protected function _removeEmptyArrayItems(array &$data) {
        foreach ($data as $key => $value) {
            if (!is_array($value) && trim($value) == '') {
                unset($data[$key]);
            }
        }
    }

    /**
     * Retrieve the read adapter
     *
     */
    static protected function _getReadAdapter() {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    /**
     * Retrieve a table name with the prefix
     *
     * @param string $table
     * @param string $extra = null
     * @return string
     */
    static public function getTableName($table, $extra = null) {
        return Mage::getSingleton('core/resource')->getTableName($table) . $extra;
    }

    /**
     * Retrieve a category attribute model
     *
     * @param string $attributeCode
     */
    public function getCategoryAttribute($attributeCode) {
        $key = '_attribute_model_category_' . $attributeCode;

        if (!$this->getData($key)) {
            $this->setData($key, Mage::getSingleton('eav/config')->getAttribute('catalog_category', $attributeCode));
        }

        return $this->getData($key);
    }

    /**
     * Retrieve a product attribute model
     *
     * @param string $attributeCode
     */
    public function getProductAttribute($attributeCode) {
        $key = '_attribute_model_product_' . $attributeCode;

        if (!$this->getData($key)) {
            $this->setData($key, Mage::getSingleton('eav/config')->getAttribute('catalog_product', $attributeCode));
        }

        return $this->getData($key);
    }

    /**
     * Get all the configurable attributes
     *
     * @return array
     */
    public function getConfigurableAttributes() {
        return $this->_configAttr;
    }

    /**
     * Retrieve the total time of execution
     *
     * @return float
     */
    public function getTotalTime() {
        return $this->_time() - $this->getStartTime();
    }

    /**
     * Retrieve the current time in seconds
     *
     * @return float
     */
    protected function _time() {
        $t = microtime();
        $t = explode(" ", $t);
        $t = $t[1] + $t[0];

        return $t;
    }

    /**
     * Retrieve the current memory usage in mb's
     *
     * @return float
     */
    static protected function _memory() {
        return round(memory_get_usage() / 1048576, 2);
        return memory_get_usage();
    }

    /**
     * Log a message to the screen
     *
     * @param string $msg
     */
    public function log($msg) {
        if ($this->_canLog) {
            echo sprintf('<h2>%s: <span style="color:red;">%s</span> / <span style="color:green;">%s</span></h2>', $msg, $this->getTotalTime(), self::_memory());
            flush();
        }
    }

}

class Smtv_Export_Product_Collection extends Smtv_Export_Abstract {

    /**
     * Retrieve an array of products
     *
     * @return array
     */
    public function getAllProducts() {
        $products = self::_getReadAdapter()
                        ->select()
                        ->from(array('e' => self::getTableName('catalog/product')), array('sku', 'type_id', 'entity_id'));

        $this->_addIntFilterToCollection($products, 'visibility', 'IN (2, 4)');
        $this->_addIntFilterToCollection($products, 'status', '= 1');
        $this->_addVarcharToSelect($products, 'name');
        $this->_addVarcharToSelect($products, 'image');
        $this->_addUrlRewriteToSelect($products);
        $this->_addTextToSelect($products, 'short_description');
        $this->_addTextToSelect($products, 'description');
        $this->_addDecimalToSelect($products, 'price');
        $this->_addDecimalToSelect($products, 'special_price', 'joinLeft');
        $this->_addDatetimeToSelect($products, 'special_from_date', 'joinLeft');
        $this->_addDatetimeToSelect($products, 'special_to_date', 'joinLeft');
        $this->_addIsInStockToSelect($products);
        $this->_addConfigurableAttributesToSelect($products);


        if ($limit = Mage::app()->getRequest()->getParam('limit')) {
            $products->limit($limit);
        }
        $products
                ->group('sku')
                ->order('entity_id');

        if ($this->_canLog) {
            $this->log((string) $products);
        }
        return self::_getReadAdapter()->fetchAll($products);
    }

    /**
     * Retrieve an array of products based on product ID's
     *
     * @param array $productIds
     * @return array
     */
    public function getChildProductsByIds(array $productIds, $parentId) {
        $products = self::_getReadAdapter()
                        ->select()
                        ->from(array('e' => self::getTableName('catalog/product')), array('sku', 'entity_id'))
                        ->where('e.type_id = ?', 'simple')
                        ->where('e.entity_id IN (?)', $productIds)
                        ->limit(count($productIds));

        $this->_addIntFilterToCollection($products, 'status', '= 1');
        $this->_addVarcharToSelect($products, 'name');
        $this->_addVarcharToSelect($products, 'image');
        $this->_addTextToSelect($products, 'short_description');
        $this->_addTextToSelect($products, 'description');
        $this->_addIsInStockToSelect($products);
        $this->_addConfigurableAttributesToSelect($products, $parentId);

        return self::_getReadAdapter()->fetchAll($products);
    }

    /**
     * Join configurable products to the select statement
     *
     * @param $select
     */
    protected function _addConfigurableAttributesToSelect($select, $parentId = null) {
        $configAttributes = $this->getConfigurableAttributes();
        if (!is_null($parentId) && (int)$parentId) {
            $attributes = self::_getReadAdapter()
                        ->select()
                        ->from(array('e' => self::getTableName('catalog/product_super_attribute')), array())
                        ->join(array('eav' => self::getTableName('eav/attribute')),"`e`.`attribute_id` = `eav`.`attribute_id`", array('attribute_code'))
                        ->where('e.product_id = ?', $parentId);
            $attributes = self::_getReadAdapter()->fetchAll($attributes);
            $final = array();
            foreach ($attributes as $attr) {
                $final[$attr['attribute_code']] = isset($configAttributes[$attr['attribute_code']]) ? $configAttributes[$attr['attribute_code']] : $attr['attribute_code'];
            }
            $configAttributes = $final;
        }
        // Add configurable attributes
        foreach($configAttributes as $code => $map_attribute) {
            $attribute = $this->getProductAttribute($code);
            if ($attribute && $attribute->getId()) {
                $this->_addIntToSelect($select, $code, 'joinLeft');
            }
        }
    }

    protected function _addIsInStockToSelect($select) {
        $select->join(
                array('_stock_table' => self::getTableName('cataloginventory/stock_item')),
                "`_stock_table`.`product_id` = `e`.`entity_id` AND `_stock_table`.`stock_id` = 1",
                array('is_in_stock' => 'is_in_stock', 'qty' => 'qty')
        );
    }

    /**
     * Filter the collection by an attribute code and value
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $value
     */
    protected function _addIntFilterToCollection($select, $attributeCode, $value) {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->join(
                    array($alias => self::getTableName('catalog/product', '_int')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId
                    . " AND `{$alias}`.`value` {$value}",
                    ''
            );
        }
    }

    /**
     * Add an integer attribute to the select object
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $join
     */
    protected function _addIntToSelect($select, $attributeCode, $join = 'join') {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->$join(
                    array($alias => self::getTableName('catalog/product', '_int')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId,
                    array($attributeCode => 'value')
            );
        }
    }

    /**
     * Add an decimal attribute to the select object
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $join
     */
    protected function _addDecimalToSelect($select, $attributeCode, $join = 'join') {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->$join(
                    array($alias => self::getTableName('catalog/product', '_decimal')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId,
                    array($attributeCode => 'value')
            );
        }
    }

    /**
     * Add an datetime attribute to the select object
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $join
     */
    protected function _addDatetimeToSelect($select, $attributeCode, $join = 'join') {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->$join(
                    array($alias => self::getTableName('catalog/product', '_datetime')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId,
                    array($attributeCode => 'value')
            );
        }
    }

    /**
     * Add a varchar attribute to the select object
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $join
     */
    protected function _addVarcharToSelect($select, $attributeCode, $join = 'join') {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->$join(
                    array($alias => self::getTableName('catalog/product', '_varchar')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId,
                    array($attributeCode => 'value')
            );
        }
    }

    /**
     * Add a text attribute to the select object
     *
     * @param Zend_Db_Select $select
     * @param string $attributeCode
     * @param string $join
     */
    protected function _addTextToSelect($select, $attributeCode, $join = 'join') {
        $alias = '_' . $attributeCode . '_table';

        if ($attributeId = (int) $this->getProductAttribute($attributeCode)->getId()) {
            $select->$join(
                    array($alias => self::getTableName('catalog/product', '_text')),
                    "`{$alias}`.`entity_id` = `e`.`entity_id` AND `{$alias}`.`attribute_id` = " . $attributeId,
                    array($attributeCode => 'value')
            );
        }
    }

    /**
     * Add the url rewrite to the select object
     *
     * @param Zend_Db_Select $select
     */
    protected function _addUrlRewriteToSelect($select) {
        $select->join(
                array('_url_rewrite_table' => self::getTableName('core/url_rewrite')),
                "`_url_rewrite_table`.`product_id` = `e`.`entity_id` AND `_url_rewrite_table`.`category_id` IS NULL
                            AND `_url_rewrite_table`.`id_path`=CONCAT('product/', `e`.`entity_id`)",
                array('url_path' => 'request_path')
        );
    }

}

class Smtv_Export extends Smtv_Export_Abstract {

    protected $_output = array();

    public function __construct() {
        parent::__construct();

        $this->setData('product_image_url', Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product');
        $this->setData('start_time', $this->_time());
        $this->log('Starting: ' . get_class($this));
    }

    public function getJson() {
        header("Content-Disposition: attachment; filename=smtv.json");
        header('Content-Type: application/json');

        echo Zend_Json::encode($this->_output);
    }

    public function displayOutputArray() {
        echo '<pre>';
        print_r($this->_output);
        echo '</pre>';
    }

    /**
     * Retrieve an array of products
     *
     * @return array
     */
    protected function _getAllProducts() {
        $collection = new Smtv_Export_Product_Collection();
        if ($this->_canLog) {
            $collection->startLogging();
        }
        $products = $collection->getAllProducts();
        unset($collection);
        return $products;
    }

    /**
     * Run the export logic
     *
     */
    public function run() {
        if ($products = $this->_getAllProducts()) {
            $this->log('post::_getAllProducts(' . count($products) . ' products)');

            $it = 0;
            $skip = 500;

            foreach ($products as $it => $product) {
                if (++$it % $skip == 0) {
                    $this->log('pre::_addProductToExport() x ' . $skip);
                }

                $this->_addProductToExport($product);
                unset($products[$it]);
            }
        }
    }

    /**
     * Add a product to the output array
     *
     * @param array $product
     */
    protected function _addProductToExport(array &$product) {
        $data = array(
            'sku' => isset($product['sku']) ? $product['sku'] : null,
            'type_id' => isset($product['type_id']) ? $product['type_id'] : null,
            'name' => isset($product['name']) ? $product['name'] : null,
            'is_in_stock' => isset($product['is_in_stock']) ? $product['is_in_stock'] : null,
            'qty' => $product['qty'] !== false || !is_null($product['qty']) ? (int) $product['qty'] : null,
            'price' => isset($product['price']) ? $product['price'] : null,
            'special_price' => isset($product['special_price']) ? $product['special_price'] : null,
            'special_from_date' => isset($product['special_from_date']) ? $product['special_from_date'] : null,
            'special_to_date' => isset($product['special_to_date']) ? $product['special_to_date'] : null,
            'url_path' => Mage::getUrl('', array('_direct' => isset($product['url_path']) ? $product['url_path'] : null, '_nosid' => true)),
            'short_description' => isset($product['short_description']) ? $this->_cleanTextField($product['short_description']) : null,
            'description' => isset($product['description']) ? $this->_cleanTextField($product['description']) : null,
            'image' => $this->_getProductImages($product['entity_id'], $product['image']),
            'categories' => $this->_getProductCategories($product['entity_id']),
        );
        
        $this->_addConfigurableAttributesToExport($product, $data);

        if ($product['type_id'] == 'configurable') {
            $data['price_variation'] = $this->_addPriceVariation($product['entity_id']);
            $data['options_container'] = $this->_getProductOptionContainer($product['entity_id']);
        }

        $this->_removeEmptyArrayItems($data);

        $this->addRow($data);
    }

    /**
     * Add configurable products data to the array
     *
     * @param array $product
     * @param array $data
     * @return array $data
     */
    protected function _addConfigurableAttributesToExport($product, &$data) {
        foreach ($this->getConfigurableAttributes() as $code => $map_attribute) {
            if (isset($product[$code]) && $product[$code]) {
                $data[$map_attribute] = $this->_getAttributeText($product[$code]);
            }
        }
        return $data;
    }

    protected function _cleanTextField($str) {
        $str = preg_replace("/\r|\n/", '', $str);
        $str = preg_replace("/[ ]{2,}/", ' ', $str);
        $str = preg_replace("/<br[ ]{0,}>/", '<br />', $str);
        $str = str_replace("\u2022\t", '', $str);

        return str_replace('"', '\\"', $str);
    }

    /**
     * Retrieve the attribute text for the option ID
     *
     * @param int $optionUd
     * @return string
     */
    protected function _getAttributeText($optionId) {
        $key = '_option_value_' . $optionId;

        if (!$this->getData($key)) {
            $select = self::_getReadAdapter()
                            ->select()
                            ->from(self::getTableName('eav/attribute', '_option_value'), 'value')
                            ->where('option_id=?', $optionId)
                            ->where('store_id IN (?)', array(0, self::$storeId))
                            ->order('store_id DESC')
                            ->limit(1);

            $this->setData($key, self::_getReadAdapter()->fetchOne($select));
        }

        return $this->getData($key);
    }

    /**
     * Retrieve the final price for the product ID
     *
     * @param int $productId
     * @return float|null
     */
    protected function _getProductPrice($productId) {
        $select = self::_getReadAdapter()
                        ->select()
                        ->from(self::getTableName('catalog/product_index_price'), 'final_price')
                        ->where('entity_id=?', $productId)
                        ->order('customer_group_id ASC')
                        ->limit(1);

        return self::_getReadAdapter()->fetchOne($select);
    }

    /**
     * Retrieve an comma separated list of categories for a product ID
     *
     * @param int $productId
     * @return string
     */
    protected function _getProductCategories($productId) {
        $attributeCode = 'name';
        $category_product_alias = '_' . $attributeCode . '_catprod_table';
        $category_name_alias = '_' . $attributeCode . '_name_table';

        if ($attributeId = (int) $this->getCategoryAttribute($attributeCode)->getId()) {
            $select = self::_getReadAdapter()
                            ->select()
                            ->from(array($category_product_alias => self::getTableName('catalog/category_product')))
                            ->join(
                                    array($category_name_alias => self::getTableName('catalog/category', '_varchar')),
                                    "`{$category_product_alias}`.`category_id` = `{$category_name_alias}`.`entity_id` AND `{$category_product_alias}`.`product_id` = '{$productId}' AND `{$category_name_alias}`.`attribute_id` = " . $attributeId,
                                    array('category_names' => 'value')
            );
            if ($this->_canLog) {
                $this->log('Query to extract categories');
                $this->log((string) $select);
            }
            $cols = self::_getReadAdapter()->fetchAll($select);
            $cols = is_array($cols) ? $cols : array($cols);
            if ($this->_canLog) {
                $this->log('Categories Info');
                $this->log(print_r($cols));
            }
            $names = array();
            foreach ($cols as $col) {
                $names[] = $col['category_names'];
            }
            return implode(',', $names);
        }
        return null;
    }

    /**
     * Retrieve an array of images for a product ID
     *
     * @param int $productId
     * @return array
     */
    protected function _getProductImages($productId, $mainImage) {
        $final = null;
        $baseUrl = $this->getData('product_image_url');
        if ($mainImage) {
            $final[] = $baseUrl . $mainImage;
        }
        if ($mediaGalleryAttribute = $this->getProductAttribute('media_gallery')) {
            $select = self::_getReadAdapter()
                            ->select()
                            ->distinct()
                            ->from(array('gallery' => self::getTableName('catalog/product', '_media_gallery')), 'value')
                            ->join(
                                    array('gallery_value' => self::getTableName('catalog/product', '_media_gallery_value')),
                                    "`gallery_value`.`value_id` = `gallery`.`value_id`",
                                    ''
                            )
                            ->where("gallery.entity_id=?", $productId)
                            ->where('gallery.attribute_id=?', $mediaGalleryAttribute->getId())
                            ->order('position ASC');

            if ($images = self::_getReadAdapter()->fetchCol($select)) {
                foreach ($images as $image) {
                    if ($image != $mainImage) {
                        $final[] = $baseUrl . $image;
                    }
                }
            }
        }
        return $final;
    }

    /**
     * Retrieve an array for the options container for the product ID
     *
     * @param int $productId
     * @return array
     */
    protected function _getProductOptionContainer($productId) {

        if ($children = $this->_getChildProductsByParentId($productId)) {
            $options = array();

            foreach ($children as $child) {
                $option = array(
                    'sku' => isset($child['sku']) ? $child['sku'] : null,
                    'name' => isset($child['name']) ? $child['name'] : null,
                    'is_in_stock' => isset($child['is_in_stock']) ? $child['is_in_stock'] : null,
                    'qty' => $child['qty'] !== false || !is_null($child['qty']) ? (int) $child['qty'] : null,
                    'short_description' => isset($child['short_description']) ? $this->_cleanTextField($child['short_description']) : null,
                    'description' => isset($child['description']) ? $this->_cleanTextField($child['description']) : null,
                    'image' => $this->_getProductImages($child['entity_id'], $child['image'])
                );

                $this->_addConfigurableAttributesToExport($child, $option);

                $this->_removeEmptyArrayItems($option);

                if (count($option) > 0) {
                    $options[] = $option;
                }
            }

            if (count($options) > 0) {
                return $options;
            }
        }

        return null;
    }

    protected function _addPriceVariation($productId) {
        $select = self::_getReadAdapter()
                            ->select()
                            ->distinct()
                            ->from(array('super_attribute' => self::getTableName('catalog/product_super_attribute')), '')
                            ->join(
                                    array('super_attribute_pricing' => self::getTableName('catalog/product_super_attribute_pricing')),
                                    "`super_attribute_pricing`.`product_super_attribute_id` = `super_attribute`.`product_super_attribute_id`",
                                    array('is_percent', 'pricing_value')
                            )
                            ->join(
                                    array('attribute_code' => self::getTableName('eav/attribute')),
                                    "`attribute_code`.`attribute_id` = `super_attribute`.`attribute_id`",
                                    array('attribute_code')
                            )
                            ->join(
                                    array('attribute_value' => self::getTableName('eav/attribute_option_value')),
                                    "`attribute_value`.`option_id` = `super_attribute_pricing`.`value_index`",
                                    array('attribute_value' => 'value')
                            )
                            ->where("super_attribute.product_id=?", $productId);
        $pricing = self::_getReadAdapter()->fetchAll($select);
        if (is_array($pricing) && count($pricing)) {
            $data = array();
            $configAttributes = $this->getConfigurableAttributes();
            foreach ($pricing as $price) {
                if (!isset($configAttributes[$price['attribute_code']])) {
                    continue;
                }
                $data[] = array('is_percent' => $price['is_percent'], 'pricing_value' => $price['pricing_value'], 'attribute_value' => $price['attribute_value'], 'attribute_code' => $configAttributes[$price['attribute_code']]);
            }
            return $data;
        }
        return null;
    }

    /**
     * Add the data array to the output array
     *
     * @param array $data
     * @return $this
     */
    public function addRow(array $data) {
        $this->_output[] = $data;
        return $this;
    }

    /**
     * Retrieve an array of child products from a parent id
     *
     * @param int $productId
     * @return array|false
     */
    public function _getChildProductsByParentId($productId) {
        $select = self::_getReadAdapter()
                        ->select()
                        ->from(self::getTableName('catalog/product_super_link'), 'product_id')
                        ->where('parent_id=?', $productId);

        if ($childIds = self::_getReadAdapter()->fetchCol($select)) {
            $collection = new Smtv_Export_Product_Collection();
            return $collection->getChildProductsByIds($childIds, $productId);
        }

        return false;
    }

}

/**
 *  Start of program logic
 *
 */
try {

    $canLog = Mage::app()->getRequest()->getParam('log');
    $export = new Smtv_Export();

    if ($canLog == '1') {
        header('Content-Type: text/html; charset=utf8');
        $export->startLogging();
    }

    $export->run();

    if (!$canLog) {
        $export->getJson();
    } else {
        $export->displayOutputArray();
    }
} catch (Exception $e) {
    echo sprintf('<h1>%s</h1><pre>%s</pre>', $e->getMessage(), $e->getTraceAsString());
}

