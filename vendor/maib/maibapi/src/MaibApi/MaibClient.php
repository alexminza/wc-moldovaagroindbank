<?php

namespace Maib\MaibApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Command\Guzzle\DescriptionInterface;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Command\Result;
use GuzzleHttp\Exception\BadResponseException;

class MaibClient extends GuzzleClient
{
    /**
     * The Payment Gateway URL to use in production mode.
     */
    const MAIB_LIVE_REDIRECT_URL = 'https://maib.ecommerce.md:443/ecomm01/ClientHandler';

    /**
     * The Bank server URL to use in production mode.
     */
    const MAIB_LIVE_BASE_URI = 'https://maib.ecommerce.md:11440/ecomm01/MerchantHandler';

    /**
     * The Payment Gateway URL to use in development/testing mode.
     */
    const MAIB_TEST_REDIRECT_URL = 'https://maib.ecommerce.md:21443/ecomm/ClientHandler';

    /**
     * The Bank server URL to use in development/testing mode.
     */
    const MAIB_TEST_BASE_URI = 'https://maib.ecommerce.md:21440/ecomm/MerchantHandler';

    /**
     * The Certificate URL to use in development/testing mode.
     */
    const MAIB_TEST_CERT_URL = __DIR__ . '/cert/cert.pem';

    /**
     * The Certificate PASSWORD to use in development/testing mode.
     */
    const MAIB_TEST_CERT_PASS = 'Za86DuC$';

    /**
     * The Certificate Private Key to use in development/testing mode.
     */
    const MAIB_TEST_CERT_KEY_URL = __DIR__ . '/cert/key.pem';

    /**
     * @param ClientInterface      $client
     * @param DescriptionInterface $description
     * @param array                $config
     */
    public function __construct(
        ClientInterface $client = null,
        DescriptionInterface $description = null,
        array $config = []
    ) {
        $client = $client instanceof ClientInterface ? $client : new Client();
        $description = $description instanceof DescriptionInterface ? $description : new MaibDescription();
        parent::__construct($client, $description, null, null, null, $config);
    }

    /**
     * @param string $name
     * @param array $arguments
     *
     * @return array
     * @throws BadResponseException
     * @throws \Exception
     */
    public function __call($name, array $arguments)
    {
        try {
            $response = parent::__call($name, $arguments);

            $array1 = preg_split('/\n|\r\n?/', trim((string)$response->offsetGet('additionalProperties')));
            $result = array();
            foreach ($array1 as $value) {
                $array2 = explode(':', $value);
                $result[$array2[0]] = isset($array2[1])? trim($array2[1]) : '';
            }
            return $result;
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * Registering transactions
     * @param  float $amount
     * @param  int $currency
     * @param  string $clientIpAddr
     * @param  string $description
     * @param  string $language
     * start SMS transaction. This is simplest form that charges amount to customer instantly.
     * @return array  TRANSACTION_ID
     * TRANSACTION_ID - transaction identifier (28 characters in base64 encoding)
     * error          - in case of an error
     */
    public function registerSmsTransaction($amount, $currency, $clientIpAddr, $description = '', $language = 'ru')
    {
        $args = [
          'command'  => 'v',
          'amount' => (string)($amount * 100),
          'msg_type' => 'SMS',
          'currency' => (string)$currency,
          'client_ip_addr' => $clientIpAddr,
          'description' => $description,
          'language' => $language
        ];

        return parent::registerSmsTransaction($args);
    }

    /**
     * Registering DMS authorization
     *    * @param  float $amount
     *    * @param  int $currency
     *    * @param  string $clientIpAddr
     *    * @param  string $description
     *    * @param  string $language
     * DMS is different from SMS, dms_start_authorization blocks amount,
     *    and than we use dms_make_transaction to charge customer.
     * @return array  TRANSACTION_ID
     * TRANSACTION_ID - transaction identifier (28 characters in base64 encoding)
     * error          - in case of an error
     */
    public function registerDmsAuthorization($amount, $currency, $clientIpAddr, $description = '', $language = 'ru')
    {
        $args = [
          'command'  => 'a',
          'amount' => (string)($amount * 100),
          'currency' => (string)$currency,
          'msg_type' => 'DMS',
          'client_ip_addr' => $clientIpAddr,
          'description' => $description,
          'language' => $language
        ];

        return parent::registerDmsAuthorization($args);
    }

    /**
     * Executing a DMS transaction
     * @param  string $authId
     * @param  float $amount
     * @param  int $currency
     * @param  string $clientIpAddr
     * @param  string $description
     * @param  string $language
     * @return array  RESULT, RESULT_CODE, BRN, APPROVAL_CODE, CARD_NUMBER, error
     * RESULT         - transaction results: OK - successful transaction, FAILED - failed transaction
     * RESULT_CODE    - transaction result code returned from Card Suite Processing RTPS (3 digits)
     * BRN            - retrieval reference number returned from Card Suite Processing RTPS (12 characters)
     * APPROVAL_CODE  - approval code returned from Card Suite Processing RTPS (max 6 characters)
     * CARD_NUMBER    - masked card number
     * error          - in case of an error
     */
    public function makeDMSTrans($authId, $amount, $currency, $clientIpAddr, $description = '', $language = 'ru')
    {

        $args = [
          'command'  => 't',
          'trans_id' => $authId,
          'amount' => (string)($amount * 100),
          'currency' => (string)$currency,
          'client_ip_addr' => $clientIpAddr,
          'msg_type' => 'DMS',
          'description' => $description,
          'language' => $language
        ];

        return parent::makeDMSTrans($args);
    }

    /**
     * Transaction result
     * @param  string $transId
     * @param  string $clientIpAddr
     * @return array  RESULT, RESULT_PS, RESULT_CODE, 3DSECURE, RRN, APPROVAL_CODE, CARD_NUMBER,
     *                AAV, RECC_PMNT_ID, RECC_PMNT_EXPIRY, MRCH_TRANSACTION_ID
     * RESULT             - OK              - successfully completed transaction,
     *                 FAILED          - transaction has failed,
     *                 CREATED         - transaction just registered in the system,
     *                 PENDING         - transaction is not accomplished yet,
     *                 DECLINED        - transaction declined by ECOMM,
     *                 REVERSED        - transaction is reversed,
     *                 AUTOREVERSED    - transaction is reversed by autoreversal,
     *                 TIMEOUT         - transaction was timed out
     * RESULT_PS          - transaction result, Payment Server interpretation (shown only
     *                       if configured to return ECOMM2 specific details
     *                 FINISHED        - successfully completed payment,
     *                 CANCELLED       - cancelled payment,
     *                 RETURNED        - returned payment,
     *                 ACTIVE          - registered and not yet completed payment.
     * RESULT_CODE        - transaction result code returned from Card Suite Processing RTPS (3 digits)
     * 3DSECURE           - AUTHENTICATED   - successful 3D Secure authorization
     *                 DECLINED        - failed 3D Secure authorization
     *                 NOTPARTICIPATED - cardholder is not a member of 3D Secure scheme
     *                 NO_RANGE        - card is not in 3D secure card range defined by issuer
     *                 ATTEMPTED       - cardholder 3D secure authorization using attempts ACS server
     *                 UNAVAILABLE     - cardholder 3D secure authorization is unavailable
     *                 ERROR           - error message received from ACS server
     *                 SYSERROR        - 3D secure authorization ended with system error
     *                 UNKNOWNSCHEME   - 3D secure authorization was attempted by wrong card scheme
     *                                    (Dinners club, American Express)
     * RRN                - retrieval reference number returned from Card Suite Processing RTPS
     * APPROVAL_CODE      - approval code returned from Card Suite Processing RTPS (max 6 characters)
     * CARD_NUMBER        - Masked card number
     * AAV                - FAILED the results of the verification of hash value in AAV merchant name (only if failed)
     * RECC_PMNT_ID            - Reoccurring payment (if available) identification in Payment Server.
     * RECC_PMNT_EXPIRY        - Reoccurring payment (if available) expiry date in Payment Server in form of YYMM
     * MRCH_TRANSACTION_ID     - Merchant Transaction Identifier (if available) for Payment -
     *                            shown if it was sent as additional parameter on Payment registration.
     * The RESULT_CODE and 3DSECURE fields are informative only and can be not shown.
     * The fields RRN and APPROVAL_CODE appear for successful transactions only, for informative purposes,
     * and they facilitate tracking the transactions in Card Suite Processing RTPS system.
     * error                   - In case of an error
     * warning                 - In case of warning (reserved for future use).
     */
    public function getTransactionResult($transId, $clientIpAddr)
    {
        $args = [
          'command'  => 'c',
          'trans_id' => $transId,
          'client_ip_addr' => $clientIpAddr,
        ];

        return parent::getTransactionResult($args);
    }


    /**
     * Transaction reversal
     * @param  string $transId
     * @param  string $amount          reversal amount in fractional units (up to 12 characters).
     *                                 For DMS authorizations only full amount can be reversed, i.e.,
     *                                 the reversal and authorization amounts have to match.
     *                                 In other cases partial reversal is also available.
     * @return array  RESULT, RESULT_CODE
     * RESULT         - OK              - successful reversal transaction
     *                  REVERSED        - transaction has already been reversed
     *         FAILED          - failed to reverse transaction (transaction status remains as it was)
     * RESULT_CODE    - reversal result code returned from Card Suite Processing RTPS (3 digits)
     * error          - In case of an error
     * warning        - In case of warning (reserved for future use).
     */
    public function revertTransaction($transId, $amount)
    {
        $args = array(
          'command'  => 'r',
          'trans_id' => $transId,
          'amount' => (string)($amount * 100),
        );

        return parent::revertTransaction($args);
    }

    /**
     * Transaction refund
     * @param  string $transId
     * @param  string $amount          full original transaction amount is always refunded.
     * @return array  RESULT, RESULT_CODE
     * RESULT         - OK              - successful refund transaction
     *         FAILED          - failed refund transaction
     * RESULT_CODE    - result code returned from Card Suite Processing RTPS (3 digits)
     * refund_transaction_id - refund transaction identifier for obtaining refund payment details
     * error          - In case of an error
     * warning        - In case of warning (reserved for future use).
     *
     * Transaction status in payment server after refund is not changed.
     */
    public function refundTransaction($transId, $amount)
    {
        $args = array(
          'command'  => 'k',
          'trans_id' => $transId,
          'amount' => (string)($amount * 100),
        );

        return parent::refundTransaction($args);
    }

    /**
     * needs to be run once every 24 hours.
     * this tells bank to process all transactions of that day SMS or DMS that were success
     * in case of DMS only confirmed and sucessful transactions will be processed
     * @return array RESULT, RESULT_CODE, FLD_075, FLD_076, FLD_087, FLD_088
     * RESULT        - OK     - successful end of business day
     *        FAILED - failed end of business day
     * RESULT_CODE   - end-of-business-day code returned from Card Suite Processing RTPS (3 digits)
     * FLD_075       - the number of credit reversals (up to 10 digits), shown only if result_code begins with 5
     * FLD_076       - the number of debit transactions (up to 10 digits), shown only if result_code begins with 5
     * FLD_087       - total amount of credit reversals (up to 16 digits), shown only if result_code begins with 5
     * FLD_088       - total amount of debit transactions (up to 16 digits), shown only if result_code begins with 5
     */
    public function closeDay()
    {
        $args = [
          'command'  => 'b',
        ];

        return parent::closeDay($args);
    }
}
