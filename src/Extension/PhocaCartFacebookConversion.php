<?php
/**
 * @package   Phoca Cart
 * @author    Jan Pavelka - https://www.phoca.cz
 * @copyright Copyright (C) Jan Pavelka https://www.phoca.cz
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPLv2 and later
 * @cms       Joomla
 * @copyright Copyright (C) Open Source Matters. All rights reserved.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 */

namespace Joomla\Plugin\System\PhocaCartFacebookConversion\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Menu\AdministratorMenuItem;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Phoca\PhocaCart\User\AdvancedACL;
use Joomla\Event\DispatcherInterface;
use PhocacartCategory;
use PhocacartCurrency;
use PhocacartManufacturer;
use PhocacartOrder;
use PhocacartPrice;
use PhocacartUtils;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * PhocaCart System plugin
 *
 * @since  5.0.0
 */
final class PhocaCartFacebookConversion extends CMSPlugin
{
    protected $phocaCartSessionData = [];

    public function __construct(DispatcherInterface $dispatcher, array $config) {
        parent::__construct($dispatcher, $config);
    }

    public function onAfterRoute() {

        // We don't get this information in onBeforeCompileHead because the session is destroyed in info view
        $app = Factory::getApplication();
        $view = $app->input->get('view', '');
        $option = $app->input->get('option', '');
        $track_purchase = $this->params->get('track_purchase', 1);
        if ($track_purchase == 1 && $view == 'info' && $option == 'com_phocacart') {
            $session     = Factory::getSession();
            $this->phocaCartSessionData['infoaction']  = $session->get('infoaction', 0, 'phocaCart');
            $this->phocaCartSessionData['infomessage'] = $session->get('infomessage', array(), 'phocaCart');
            $this->phocaCartSessionData['infodata']    = $session->get('infodata', array(), 'phocaCart');
        }

    }


    public function onBeforeCompileHead() {

        if ($this->getApplication()->isClient('administrator') === true) {
            return;
        }

        // Get the document object.
        $document = $this->getApplication()->getDocument();

        if ($document->getType() !== 'html') {
            return;
        }

        // Are we in a modal?
        if ($this->getApplication()->getInput()->get('tmpl', '', 'cmd') === 'component') {
            return;
        }

        $app = Factory::getApplication();
        $view = $app->input->get('view', '');
        $option = $app->input->get('option', '');
        $id = (int)$app->input->get('id', 0);
        $search = $app->input->get('search', '');

        require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';

		$currency               = PhocacartCurrency::getCurrency();
        $currencyCode           = $currency->code;
        $currencyExchangeRate   = $currency->exchange_rate;


        $use_product_sku = 'false';
        $id_sku = $this->params->get('id_sku', 1);
        if ($id_sku == 2) {
            $use_product_sku = 'true';
        }
        $track_add_to_cart = $this->params->get('track_add_to_cart', 1);
        $track_initiate_checkout = $this->params->get('track_initiate_checkout', 1);
        // 1 ... always, 2 ... per session, 3 ... only when total items change
        $initialize_checkout_rule = $this->params->get('initialize_checkout_rule', 3);
        $track_view_content = $this->params->get('track_view_content', 1);
        $track_search = $this->params->get('track_search', 1);
        $track_add_payment_info = $this->params->get('track_add_payment_info', 1);
        $track_purchase = $this->params->get('track_purchase', 1);

        $total_netto_brutto = $this->params->get('total_netto_brutto', 2);
        $pixel_id = $this->params->get('pixel_id', '');

        // -----
        // DEBUG
        // -----
        $debug = false;
        $debugCall = '';
        if ($debug){
            $debugCall = ' let debugData = (typeof eventData !== \'undefined\' && eventData) 
                ? eventData 
                : (typeof data !== \'undefined\' && data) 
                ? data 
                : \'\';
                
                if (eventName) {
                    console.log(eventName);
                    debugData = {"Event Name": eventName, ...debugData };
                }
                this.sendDebugData(debugData);';
        }
        if ($pixel_id == '') {
            Log::add(Text::_('PLG_SYSTEM_PHOCACART_FACEBOOK_CONVERSION_ERROR_NO_META_PIXEL_ID'), Log::WARNING, 'jerror');
            return;
        }

        $js = '
 class PhocaCartFbConversion {
 
    constructor(config = {}) {
        this.config = {
            pixelId: config.pixelId || \'\',
            currency: config.currency || \''.$currencyCode.'\',
            useProductSku: config.useProductSku || false,
            ...config
        };
        
        this.initialized = false;
        this.init();
    }
    
    init() {
        if (!this.config.pixelId) {
            console.warn(\'PhocaCartFbConversion: No Pixel ID provided\');
            return;
        }
        
        this.loadMetaPixel();
        this.bindEvents();
        this.trackPageView();'. "\n";

if ($track_initiate_checkout == 1 && $view == 'checkout' && $option == 'com_phocacart') {
    $js .= ' this.trackInitiateCheckout();' . "\n";
}
if ($track_view_content == 1 && $view == 'item' && $option == 'com_phocacart' && $id > 0) {
    $js .= ' this.trackViewContent();' . "\n";
}
if ($track_search == 1 && $view == 'items' && $option == 'com_phocacart' && $search != '') {
    $js .= ' this.trackSearch();' . "\n";
}

if ($track_purchase == 1 && $view == 'info' && $option == 'com_phocacart' && !empty($this->phocaCartSessionData['infodata'])){
    $js .= ' this.trackPurchase();' . "\n";

}

$js .= '        this.initialized = true;
    }'. "\n";

// loadMetaPixel
$js .= ' loadMetaPixel() {
        !function(f,b,e,v,n,t,s){
            if(f.fbq)return;n=f.fbq=function(){
                n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)
            };
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version=\'2.0\';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)
        }(window,document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');

        fbq(\'init\', this.config.pixelId);

        // Noscript fallback
        const noscript = document.createElement(\'noscript\');
        const img = document.createElement(\'img\');
        img.height = 1;
        img.width = 1;
        img.style.display = \'none\';
        img.src = `https://www.facebook.com/tr?id=${this.config.pixelId}&ev=PageView&noscript=1`;
        noscript.appendChild(img);
        document.head.appendChild(noscript);
    }'. "\n";

// bindEvents
$js .= ' bindEvents() {'. "\n";

// EVENT ADD TO CART
if ($track_add_to_cart == 1) {
     $js .= ' document.addEventListener(\'PhocaCart.addToCart\', (event) => { this.trackAddToCart(event.detail); });'. "\n";
}
// EVENT SEARCH
// if we are not in search website (items view phoca cart) then we don't run event, because it always comes to reload a page (independent to ajax settings) and the search website will track it itself
if ($track_search == 1 && $view == 'items' && $option == 'com_phocacart') {
     $js .= ' document.addEventListener(\'PhocaCart.search\', (event) => { this.trackSearch(event.detail); });'. "\n";
}
// EVENT ADD PAYMENT INFO
if ($track_add_payment_info == 1) {
     $js .= ' document.addEventListener(\'PhocaCart.addPaymentInfo\', (event) => { this.trackAddPaymentInfo(event.detail); });'. "\n";
}

$js .= '}'. "\n";






// ************
// PAGE VIEW
// ************
$js .= ' trackPageView() {'. "\n" .
        ' if (typeof fbq !== \'undefined\') {
            const eventName = \'PageView\';
            fbq(\'track\', eventName);
        }
    }'. "\n";

// ************
// INITIATE CHECKOUT
// ************
if ($track_initiate_checkout == 1 && $view == 'checkout' && $option == 'com_phocacart') {

    $cart  = new \PhocacartCartRendercheckout();
    $cart->setInstance(2);//order
    $cart->setType();
    $cart->setFullItems();

    $fullItems = $cart->getFullItems();
    $total = $cart->getTotal();
    $products = [];
    $contentproducts = [];
    $i = 0;
    $countItems = 0;
    if (!empty ($fullItems[1])) {
        foreach ($fullItems[1] as $k => $v) {
            if ($id_sku == 2) {
                $products[$i]              = htmlspecialchars($v['sku']);
                $contentproducts[$i]['id'] = $v['sku'];

            } else {
                $products[$i]              = (int)$v['id'];
                $contentproducts[$i]['id'] = (int)$v['id'];
            }

            $contentproducts[$i]['quantity']   = $v['quantity'];
            $contentproducts[$i]['item_price'] = $total_netto_brutto == 2 ? $v['brutto'] * $currencyExchangeRate : $v['netto'] * $currencyExchangeRate;

            $i++;
            $countItems = $countItems + $v['quantity'];
        }

        $productsString = implode(',', $products);

        if ($total_netto_brutto == 2) {
            $totalValue = $total[1]['brutto'] * $currencyExchangeRate;
        } else {
            $totalValue = $total[1]['netto'] * $currencyExchangeRate;
        }

        $contents = [];
        if (!empty($contentproducts)) {
            foreach ($contentproducts as $k => $v) {
                $contents[] = '{ id: \'' . $v['id'] . '\', quantity: ' . (int)$v['quantity'] . ', item_price: ' . (float)$v['item_price'] . ' }';
            }
        }
    } else {
        // Possible to do, do not render when the checkout is empty
        $productsString = '';
        $i = 0;
        $totalValue = 0;
        $contents = [];
    }

        $js .= ' trackInitiateCheckout() {'. "\n";



if ($initialize_checkout_rule == 2) {
    $js .= ' if (!sessionStorage.getItem(\'phCarttrackInitiateCheckoutSession\')) {'. "\n";
} else if ($initialize_checkout_rule == 3) {
     $js .= ' const numItemsCheckout = '.$countItems.';
     if (sessionStorage.getItem(\'phCarttrackInitiateCheckoutSessionNumItems\') != numItemsCheckout) {'. "\n";
}



$js .='        const data = {
          content_ids: ['.$productsString.'],
          content_type: \'product\',
          num_items: '.$countItems.',
          value: '.$totalValue.',
          currency: \''.$currencyCode.'\',
          contents: ['.implode(',', $contents).']
        };
        if (typeof fbq !== \'undefined\') {
             const eventName = \'InitiateCheckout\';
             fbq(\'track\', eventName, data);'.$debugCall.'
        }'. "\n";

if ($initialize_checkout_rule == 2) {
    $js .= '    sessionStorage.setItem(\'phCarttrackInitiateCheckoutSession\', \'true\');
 }';// possible debug: else { console.log("checkot initilized yet"); }
} else if ($initialize_checkout_rule == 3) {
    $js .= '    sessionStorage.setItem(\'phCarttrackInitiateCheckoutSessionNumItems\', numItemsCheckout);
 }';// possible debug: else { console.log("checkot initilized yet" + numItemsCheckout); }
}

$js .= '   }'. "\n";

}

// ************
// ADD TO CART - category, items, item, quick view
// ************
if ($track_add_to_cart == 1) {
    $js .= ' trackAddToCart(data) {
        if (typeof fbq === \'undefined\') return;
        const eventData = this.formatEventData(data, \'AddToCart\');
        const eventName = \'AddToCart\';
        fbq(\'track\', eventName, eventData);'.$debugCall.'
    }'. "\n";
}

// ************
// ADD PAYMENT INFO
// ************
if ($track_add_payment_info == 1) {
    $js .= ' trackAddPaymentInfo(data) {
        if (typeof fbq === \'undefined\') return;
           const eventData = {
              currency: "EUR"
           };

           if (data && data.items && data.items[0]) {
                //if (data.items[0].id) { eventData.id = items[0].id}
                if (data.items[0].title) { eventData.payment_method_title = data.items[0].title};
           }
            const eventName = \'AddPaymentInfo\';
            fbq(\'track\', eventName, eventData);'.$debugCall.'
    }'. "\n";
}

// ************
// ADD PURCHASE INFO
// ************
if ($track_purchase == 1 && $view == 'info' && $option == 'com_phocacart' && !empty($this->phocaCartSessionData['infodata'])){

    $infoData = isset($this->phocaCartSessionData['infodata']) ? $this->phocaCartSessionData['infodata'] : [];
    $infoAction = isset($this->phocaCartSessionData['infoaction']) ? $this->phocaCartSessionData['infoaction'] : 0;


    $pC 				= [];
    $pCp 				= PhocacartUtils::getComponentParameters();
    $pC['store_title'] 	= $pCp->get('store_title', '');


    $forceCurrency = 0;
    /*if ($p['currency_id'] != ''){
        $forceCurrency = (int)$p['currency_id'];
    }*/

    if (!isset($infoData['user_id'])) { $infoData['user_id'] = 0;}

    if (isset($infoData['order_id']) && (int)$infoData['order_id'] > 0 && isset($infoData['order_token']) && $infoData['order_token'] != '') {
        $order = PhocacartOrder::getOrder($infoData['order_id'], $infoData['order_token'], $infoData['user_id']);

        // $infoAction == 5 means that the order is cancelled, so no conversion
        if (isset($order['id']) && (int)$order['id'] > 0 && isset($infoAction) && $infoAction != 5) {
            $orderProducts = PhocacartOrder::getOrderProducts($order['id']);
            $orderUser = PhocacartOrder::getOrderUser($order['id']);
            $orderTotal = PhocacartOrder::getOrderTotal($order['id'], ['sbrutto', 'snetto', 'pbrutto', 'pnetto', 'tax']);


            if (!empty($orderProducts)) {

                $price = new PhocacartPrice();

                $deliveryPrice = 0;
                if (isset($orderTotal['sbrutto']['amount']) && $orderTotal['sbrutto']['amount'] > 0) {
                    $deliveryPrice = $price->getPriceFormatRaw($orderTotal['sbrutto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
                } else if (isset($orderTotal['snetto']['amount']) && $orderTotal['snetto']['amount'] > 0) {
                    $deliveryPrice = $price->getPriceFormatRaw($orderTotal['snetto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
                }

                $value = $price->getPriceFormatRaw($order['total_amount'], 0, 0, 0, 2, '.', '');



                $s   = [];
                $s[] = 'const eventData = {';
                $s[] = ' transaction_id: "'.$order['order_number'].'",';
                $s[] = ' affiliation: "'.$pC['store_title'].'",';
                $s[] = ' value: '.$value.',';
                $s[] = ' currency: "'.$order['currency_code'].'",';
                $s[] = ' content_type: "product",';
                //if (isset($orderTotal['tax']['amount']) && $orderTotal['tax']['amount'] != '') {
                //	$s[] = ' "tax": ' . $orderTotal['tax']['amount'] . ',';
                //}
                //$s[] = ' "shipping": '.$deliveryPrice.',';

                $productArray = [];
                foreach ($orderProducts as $k => $v) {
                    $productString = ($id_sku == 2)  ?  htmlspecialchars($v['sku']) : (int)$v['id'];
                    $productArray[] = '\''. $productString. '\'';
                }
                $s[] = ' content_ids: ['.implode(',', $productArray).'],';
                $s[] = ' contents: [';

                $i = 0;
                foreach ($orderProducts as $k => $v) {
                    $productPrice = $price->getPriceFormatRaw($v['brutto'], 0, 0, $forceCurrency, 2, '.', '');

                    /*
                    $brand        = PhocacartManufacturer::getManufacturers((int)$v['product_id']);

                    $productBrand = '';
                    if (isset($brand[0]->title)) {
                        $productBrand = $brand[0]->title;
                    }

                    $category = PhocacartCategory::getCategoryTitleById((int)$v['product_id']);
                    $productCategory = '';
                    if (isset($category->title)) {
                        $productCategory = $category->title;
                    }

                    $attributes = PhocacartOrder::getOrderAttributesByOrderedProductId((int)$v['id']);

                    $productAttribute = '';
                    if (!empty($attributes)) {
                        $j = 0;
                        foreach ($attributes as $k2 => $v2) {

                            if ($j > 0) {
                                $productAttribute .= ', ';
                            }

                            $divider = '';
                            if (isset($v2['attribute_title']) && $v2['attribute_title'] != '') {

                                $productAttribute .= $v2['attribute_title'];
                                $divider =': ';

                            }
                            if (isset($v2['option_title']) && $v2['option_title'] != '') {
                                $productAttribute .= $divider . $v2['option_title'];
                            }
                            $j++;
                        }
                    }*/

                    $s[] = ' {';
                    $s[] = ' "id": "' . (int)$v['product_id'] . '",';
                    /*$s[] = ' "name": "' . addslashes($v['title']) . '",';
                    $s[] = ' "list_name": "' . Text::_('PLG_PCV_GOOGLE_CONVERSION_PURCHASE') . '",';
                    if ($productBrand != ''){
                        $s[] = ' "brand": "'.$productBrand.'",';
                    }
                    if ($productCategory != ''){
                        $s[] = ' "category": "'.$productCategory.'",';
                    }

                    if ($productAttribute != ''){
                        $s[] = ' "variant": "'. addslashes($productAttribute) .'",';
                    }

                    $s[] = ' "list_position": '.$i.',';*/
                    $s[] = ' "quantity": '.(int)$v['quantity'].',';
                    $s[] = ' "item_price": '.$productPrice.'';
                    $s[] = ' },';
                    $i++;
                }

                $s[] = ' ]';
                $s[] = '};';

                $js .= ' trackPurchase(data) {
                    if (typeof fbq === \'undefined\') return;
                        const eventName = \'Purchase\';';
                $js .= implode("\n", $s);

                 $js .= '   fbq(\'track\', eventName, eventData);'.$debugCall.'
            
                }'. "\n";
            }
        }
    }
}

// ************
// VIEW CONTENT - detail page - item view
// ************
if ($track_view_content == 1 && $view == 'item' && $option == 'com_phocacart' && $id > 0) {

    $product = \PhocacartProduct::getProduct($id);

    $productString = ($id_sku == 2)  ?  htmlspecialchars($product->sku) : (int)$product->id;

    $price 				= new \PhocacartPrice;// Can be used by options
    $priceItems = array();
    $priceItems	= $price->getPriceItems($product->price, $product->taxid, $product->taxrate, $product->taxcalculationtype, $product->taxtitle, $product->unit_amount, $product->unit_unit, 1, 1, $product->group_price, $product->taxhide);

    // Possible to do
    // Can change price and also SKU OR EAN (Advanced Stock and Price Management)
    //$price->getPriceItemsChangedByAttributes($priceItems, $this->t['attr_options'], $price, $x);

    if ($total_netto_brutto == 2) {
        $value = $priceItems['bruttocurrency'];
    } else {
        $value = $priceItems['nettocurrency'];
    }

    $js .= ' trackViewContent() {
            if (typeof fbq === \'undefined\') return;

            const data = {
              content_ids: ['.$productString.'],
              content_type: \'product\',
              value: '.$value.',
              currency: \''.$currencyCode.'\',
              contents: [{ id: '.(int)$product->id.', quantity: 1 }]
            };
            if (typeof fbq !== \'undefined\') {
                const eventName = \'ViewContent\';
                fbq(\'track\', eventName, data);'.$debugCall.'
            }
        }'. "\n";
}
// ************
// SEARCH - items view with search keyword
// 1) Can be based on search website load
// 2) But even based on search item
//
// 1) we have data from search form
//    a) if ajax is used - track the search, because form will be not reloaded to search website (which is tracked)
//    b) if ajax not used - don't track anything because it will be tracked after submit the form and displaying search website
//
// 2) we track the search website allways
// BE AWARE - search catch by submit from can be done by module, so it can be on different websites, not only on com_phocacart_items
// ************

// if we are not on search website (items view) no matter if ajax is enabled or not - it will be allways submitted (page reload) to items view
// so ignore ajax behaviour as it will be never ajax so standard reload will track the search
if ($track_search == 1 && $view == 'items' && $option == 'com_phocacart') {

    $js .= ' trackSearch(data) {
        
        if (typeof fbq === \'undefined\') return;
        
        if (data && data.items && data.items[0] && data.items[0].search_string) {
            const searchString = data.items[0].search_string;
            const ajaxSearchingFilteringItems = data.params && data.params[0] ? data.params[0].ajaxSearchingFilteringItems : 0;

            if (searchString && ajaxSearchingFilteringItems === 1) {
                const eventData = {
                    search_string: searchString
                };
                const eventName = \'Search\';
                fbq(\'track\', eventName, eventData);' . $debugCall . '
                return;
                
            } else if (searchString && ajaxSearchingFilteringItems !== 1) {
                return;  // Do nothing as per your requirement
            }
        }'. "\n";
}
// Everytime search website will be loaded
if ($track_search == 1 && $view == 'items' && $option == 'com_phocacart' && $search != '') {

    $js .= '   const eventData = {
            search_string: \''. htmlspecialchars($search).'\'
        };
        const eventName = \'Search\';
        fbq(\'track\', eventName, eventData);'.$debugCall.'';
}
if ($track_search == 1 && $view == 'items' && $option == 'com_phocacart') {
    $js .= '}'. "\n";
}


// formatEventData
$js .= ' formatEventData(data, eventType) {
        
        const formatted = {
            currency: this.config.currency
        };

        // Handle different data structures
        if (data.items && Array.isArray(data.items)) {
       
            formatted.content_ids = data.items.map(item =>
                this.config.useProductSku ? item.sku : item.id
            );'. "\n";

if ($total_netto_brutto == 2) {
     $js .= ' if (data.items[0].basepricebrutto) {
                formatted.value = data.items[0].quantity * parseFloat(data.items[0].basepricebrutto).toFixed(2);
            }'. "\n";
 }  else {
     $js .= ' if (data.items[0].basepricenetto) {
                formatted.value = data.items[0].quantity * parseFloat(data.items[0].basepricenetto).toFixed(2);
            }'. "\n";
 }
       $js .= 'if (data.items[0].title) {
            formatted.content_name = data.items[0].title;
        }
           
            formatted.content_type = \'product\';
        }

        return formatted;
    }';

if ($debug) {
    $js .= ' sendDebugData(data) {
        fetch(\'http://localhost/send_debug.php\', {
            method: \'POST\',
            headers: {
                \'Content-Type\': \'application/json\'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(responseData => {
            console.log(\'Server Response:\', responseData);
        })
        .catch(error => {
            console.error(\'Error:\', error);
        });
    }'. "\n";
}


$js .= '}';


$js .= '
 document.addEventListener(\'DOMContentLoaded\', function() {
    const phocaCartFbConversionConfig = {
        pixelId: \''.$pixel_id.'\',
        currency: \''.$currencyCode.'\',
        useProductSku: '.$use_product_sku.',
    };
    window.PhocaCartFbConversion = new PhocaCartFbConversion(phocaCartFbConversionConfig);
});

// Export for module systems
if (typeof module !== \'undefined\' && module.exports) {
    module.exports = PhocaCartFbConversion;
}'. "\n";


        $document->getWebAssetManager()
            ->addInlineScript(
                $js,
                ['name' => 'inline.plg.system.phocacart.facebookconversion'],
                ['type' => 'module'],
                ['accessibility']
            );
    }
}
