This is a fix to the price rounding problem of prestashop 1.6 paypal module 3.11.1 and applies to modules/paypal/express_checkout/process.php in the following methods:

	1.	private function callAPI($fields) 
	2.	private function setProductsList(&$fields, &$index, &$total) 
	3.	public function rightPaymentProcess() 

The problem: Paypal Module sends the total amount of paypal with the wrong rounding decimal places during the payment process while Paypal itself calculates the total amount again by summing up product prices with appropriate rounding decimal places as a result the final comparison between the totals to be different and produce flag of failed transaction.

The solution: Return failed payment status of some transaction only if the difference of the rouding decimal places in greater than 1 euro/dollar. In other words it ignores the little difference in rounding.
We would be grateful if we helped you with this approach and happy to hearing from you for improvements.

Thanks to Thanasis Koustenis @IQTecommerce!
