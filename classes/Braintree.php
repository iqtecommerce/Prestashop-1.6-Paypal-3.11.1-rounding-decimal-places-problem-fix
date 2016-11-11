<?php
/**
 * 2007-2016 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2016 PrestaShop SA
 *  @version  Release: $Revision: 13573 $
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

include_once _PS_MODULE_DIR_.'paypal/api/sdk/braintree/lib/Braintree.php';

class PrestaBraintree{

    /**
     * initialize config of braintree
     */
    private function initConfig()
    {
        Braintree_Configuration::merchantId(Configuration::get('PAYPAL_BRAINTREE_MERCHANT_ID'));
        Braintree_Configuration::publicKey(Configuration::get('PAYPAL_BRAINTREE_PUBLIC_KEY'));
        Braintree_Configuration::privateKey(Configuration::get('PAYPAL_BRAINTREE_PRIVATE_KEY'));
        Braintree_Configuration::environment((Configuration::get('PAYPAL_SANDBOX')?'sandbox':'production'));
    }

    /**
     * @param $id_account_braintree
     * @return bool
     */
    public function createToken($id_account_braintree)
    {
        try{
            $this->initConfig();

            $clientToken = Braintree_ClientToken::generate([
                'merchantAccountId'=>$id_account_braintree,
            ]);
            return $clientToken;
        }catch(Exception $e){
            PrestaShopLogger::addLog($e->getCode().'=>'.$e->getMessage());
            return false;
        }
    }

    /**
     * @param $id_account_braintree
     * @return mixed
     */
    public function sale($cart,$id_account_braintree,$token_payment,$device_data)
    {

        $this->initConfig();

        $address_billing = new Address($cart->id_address_invoice);
        $country_billing = new Country($address_billing->id_country);
        $address_shipping = new Address($cart->id_address_delivery);
        $country_shipping = new Country($address_shipping->id_country);

        try{
            $data = [
                'amount'                => $cart->getOrderTotal(),
                'paymentMethodNonce'    => $token_payment,
                'merchantAccountId'     => $id_account_braintree,
                'orderId'               => $cart->id,
                'channel'               => 'PrestaShop_Cart_Braintree',
                'billing' => [
                    'firstName'         => $address_billing->firstname,
                    'lastName'          => $address_billing->lastname,
                    'company'           => $address_billing->company,
                    'streetAddress'     => $address_billing->address1,
                    'extendedAddress'   => $address_billing->address2,
                    'locality'          => $address_billing->city,
                    'postalCode'        => $address_billing->postcode,
                    'countryCodeAlpha2' => $country_billing->iso_code,
                ],
                'shipping' => [
                    'firstName'         => $address_shipping->firstname,
                    'lastName'          => $address_shipping->lastname,
                    'company'           => $address_shipping->company,
                    'streetAddress'     => $address_shipping->address1,
                    'extendedAddress'   => $address_shipping->address2,
                    'locality'          => $address_shipping->city,
                    'postalCode'        => $address_shipping->postcode,
                    'countryCodeAlpha2' => $country_shipping->iso_code,
                ],
                "deviceData"            => $device_data,

                'options' => [
                    'submitForSettlement' => !Configuration::get('PAYPAL_CAPTURE'),
                    'three_d_secure' => [
                        'required' => Configuration::get('PAYPAL_USE_3D_SECURE')
                    ]
                ]
            ];
            $result = Braintree_Transaction::sale($data);
            if(($result instanceof Braintree_Result_Successful) && $result->success)
            {
                return $result->transaction;
            }

        }catch(Exception $e){
            return false;
        }

        return false;
    }

    public function saveTransaction($data)
    {
        Db::getInstance()->Execute('INSERT INTO `'._DB_PREFIX_.'paypal_braintree`(`id_cart`,`nonce_payment_token`,`client_token`,`datas`)
			VALUES (\''.pSQL($data['id_cart']).'\',\''.pSQL($data['nonce_payment_token']).'\',\''.pSQL($data['client_token']).'\',\''.pSQL($data['datas']).'\')');
        return Db::getInstance()->Insert_ID();
    }

    public function updateTransaction($braintree_id,$transaction,$id_order)
    {
        Db::getInstance()->Execute('UPDATE `'._DB_PREFIX_.'paypal_braintree` set transaction=\''.pSQL($transaction).'\', id_order = \''.pSQL($id_order).'\' WHERE id_paypal_braintree = '.(int)$braintree_id);
    }

    public function checkStatus($id_cart)
    {
        $this->initConfig();
        try{
            $collection = Braintree_Transaction::search(
                array(
                    Braintree_TransactionSearch::orderId()->is($id_cart)
                )
            );

            $transaction = Braintree_Transaction::find($collection->_ids[0]);

        }catch(Exception $e){
            PrestaShopLogger::addLog($e->getCode().'=>'.$e->getMessage());
            return false;
        }
        return $transaction;
    }

    public function cartStatus($id_cart)
    {

        $sql = 'SELECT *
        FROM '._DB_PREFIX_.'paypal_braintree
        WHERE id_cart = '.(int)$id_cart;

        $result = Db::getInstance()->getRow($sql);
        if(!empty($result['id_paypal_braintree']))
        {
            if(!empty($result['id_order']))
            {
                return 'alreadyUse';
            }
            return 'alreadyTry';
        }
        else
        {
            return false;
        }

    }

    public function getTransactionId($id_order)
    {
        $result = Db::getInstance()->getValue('SELECT transaction FROM `'._DB_PREFIX_.'paypal_braintree` WHERE id_order = '.(int)$id_order);
        return $result;
    }

    public function refund($transactionId,$amount)
    {
        $this->initConfig();
        try{
            $result = Braintree_Transaction::refund($transactionId,$amount);
            if($result->success)
            {
                return true;
            }
            else
            {
                $errors = $result->errors->deepAll();
                foreach ($errors as $error)
                {
                    // if transaction already total refund
                    if($error->code == Braintree_Error_Codes::TRANSACTION_HAS_ALREADY_BEEN_REFUNDED)
                    {
                        return true;
                    }

                }
                return false;
            }
        }catch (Exception $e){
            PrestaShopLogger::addLog($e->getCode().'=>'.$e->getMessage());
            return false;
        }
    }


    public function submitForSettlement($transaction_id,$amount)
    {
        $this->initConfig();
        try{
            $result = Braintree_Transaction::submitForSettlement($transaction_id,$amount);
            if($result instanceof Braintree_Result_Successful && $result->success)
            {
                return true;
            }
            else
            {
                $errors = $result->errors->deepAll();
                foreach ($errors as $error)
                {
                    // if transaction already total refund
                    if($error->code == Braintree_Error_Codes::TRANSACTION_CANNOT_SUBMIT_FOR_SETTLEMENT)
                    {
                        return true;
                    }
                }
            }
        }catch(Exception $e){
            PrestaShopLogger::addLog($e->getCode().'=>'.$e->getMessage());
            return false;
        }
        return false;

    }

    public function void($transaction_id)
    {
        $this->initConfig();
        try{
            $result = Braintree_Transaction::void($transaction_id);
            if($result instanceof Braintree_Result_Successful && $result->success)
            {
                return true;
            }
        }catch (Exception $e){
            PrestaShopLogger::addLog($e->getCode().'=>'.$e->getMessage());
            return false;
        }
    }
}