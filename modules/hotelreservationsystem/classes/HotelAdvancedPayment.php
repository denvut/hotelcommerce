<?php
class HotelAdvancedPayment extends ObjectModel
{
	public $id;
	public $id_product;
	public $payment_type;
	public $value;
	public $id_currency;
	public $tax_include;
	public $calculate_from;
	public $active;
	public $date_add;
	public $date_upd;

	public static $definition = array(
		'table' => 'htl_advance_payment',
		'primary' => 'id',
		'fields' => array(
			'id_product' => 			array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'payment_type' => 			array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'value' => 					array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'id_currency' => 			array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'tax_include' => 			array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'calculate_from' =>  		array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
			'active' =>         		array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
			'date_add' =>  				array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
			'date_upd' =>  	 			array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
	));

	/**
	 * [getIdAdvPaymentByIdProduct :: To get Advance payment Information By id_product]
	 * @param  [int] $id_product [id of the product which Advance payment Information you want]
	 * @return [array|false]     [Returns array if information of advance payment of that id_product found otherwise returs false]
	 */
	public function getIdAdvPaymentByIdProduct($id_product)
	{
		$result = Db::getInstance()->getRow("SELECT * FROM `"._DB_PREFIX_."htl_advance_payment` WHERE `id_product`=".$id_product);
		
		if ($result)
			return $result;
		return false;
	}

	/**
	 * [getProductMinAdvPaymentAmount :: To get minimum advance payment amount paid by the customer for a particular product for a given quantities product]
	 * @param  [int]  $id_product         [ID of the product which minimum advance payment amount paid by the customer for given quantities you want]
	 * @param  [int]  $prod_qty           [Product quantity]
	 * @param  float $adv_global_percent [How much percent customer has to pay of total order in case of partial payment]
	 * @param  boolean $adv_global_tax_inc [How much percent customer has to pay of total order in case of partial payment will be calculated from tax included price or tax excluded price]
	 * @return [float]                     [Returns the amount paid by the customer in advance for the product for given quantities]
	 */
	public function getProductMinAdvPaymentAmount($id_product, $prod_qty, $adv_global_percent = 0, $adv_global_tax_inc = 0)
	{
		if (!$adv_global_percent)
			$adv_global_percent = Configuration::get('WK_ADVANCED_PAYMENT_GLOBAL_MIN_AMOUNT');

		if (!$adv_global_tax_inc)
			$adv_global_tax_inc = Configuration::get('WK_ADVANCED_PAYMENT_INC_TAX');

		$price_with_tax = Product::getPriceStatic($id_product, true, null, 6, null, false, true, $prod_qty);
		$price_without_tax = Product::getPriceStatic($id_product, false, null, 6, null, false, true, $prod_qty);

		$prod_adv = $this->getIdAdvPaymentByIdProduct($id_product);

		if ($prod_adv) 
		{
			if ($prod_adv['active']) 
			{
				if ($prod_adv['calculate_from']) // Advanced payment is calculated by product advanced payment setting
				{
					if ($prod_adv['payment_type'] == 1) // Percentage
					{
						if ($prod_adv['tax_include']) 
							$prod_price = $price_with_tax * $prod_qty;
						else
							$prod_price = $price_without_tax * $prod_qty;

						$adv_amount = ($prod_price*$prod_adv['value'])/100 ;
					}
					else
					{
						$prod_adv['value'] = Tools::convertPrice($prod_adv['value']);

						if ($prod_adv['tax_include']) //Fixed
						{
							if ($price_with_tax < $prod_adv['value']) 
								$adv_amount = $price_with_tax * $prod_qty;
							else
								$adv_amount = $prod_adv['value'] * $prod_qty;
						}
						else
						{
							if ($price_without_tax < $prod_adv['value']) 
								$adv_amount = $price_without_tax * $prod_qty;
							else
								$adv_amount = $prod_adv['value'] * $prod_qty;
						}
					}
				}
				else // Advanced payment is calculated by Global advanced payment setting
				{
					if ($adv_global_tax_inc) 
						$prod_price = $price_with_tax * $prod_qty;
					else
						$prod_price = $price_without_tax * $prod_qty;

					$adv_amount = ($prod_price*$adv_global_percent)/100 ;
				}
			}
			else
			{
				$prod_price = $price_with_tax * $prod_qty;
				$adv_amount = $prod_price;
			}
		}
		else
		{
			if ($adv_global_tax_inc) 
				$prod_price = $price_with_tax * $prod_qty;
			else
				$prod_price = $price_without_tax * $prod_qty;

			$adv_amount = ($prod_price*$adv_global_percent)/100 ;
		}

		return $adv_amount;
	}

	/**
	 * [getMinAdvPaymentAmount description :: To get minimum advance payment amount paid by the customer for all products in the cart]
	 * @return [float] [Returns the amount paid by the customer in advance for the product for given quantities]
	 */
	public function getMinAdvPaymentAmount()
	{
		$context = Context::getContext();
		
		$cart_product = $context->cart->getProducts();
		$adv_amount = 0;

		$adv_global_percent = Configuration::get('WK_ADVANCED_PAYMENT_GLOBAL_MIN_AMOUNT');
		$adv_global_tax_inc = Configuration::get('WK_ADVANCED_PAYMENT_INC_TAX');

		foreach ($cart_product as $prod_key => $cart_prod) 
		{
			$prod_qty = Cart::getProductQtyInCart($context->cart->id, $cart_prod['id_product']);

			$adv_amount += $this->getProductMinAdvPaymentAmount($cart_prod['id_product'], $prod_qty, $adv_global_percent ,$adv_global_tax_inc);
		}

		return $adv_amount;
	}

	public function _checkFreeAdvancePaymentOrder()
	{
		$advance_payment_active = Configuration::get('WK_ALLOW_ADVANCED_PAYMENT');
        if ($advance_payment_active) {
            $adv_amount = $this->getMinAdvPaymentAmount();
	        $vouchersTotal = Context::getContext()->cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
	        $freeAdvancePayment = ($adv_amount - $vouchersTotal) <= 0 ? true : false;
	        return $freeAdvancePayment;
        }
		return false;
	}
}