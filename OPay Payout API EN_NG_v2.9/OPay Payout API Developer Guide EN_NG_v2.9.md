# OPay PAYOUT API Specification Document 

|**Document Name**|OPay PAYOUT API Specification Document|
|---|---|
|**Document Type**|API document|
|**Description**|PAYOUT API Specification Document|
|**Version**|v2.9|
|**Author**|OPay|
|**Created Date**|2022-04-03|



|**evision**<br>**Version**|**history**<br>**Revised content**|**Update Date**|**Remarks**|
|---|---|---|---|
|**v2.0**|**1. Update field country to Not required**<br>**2. Update the request path in 2.4.1**<br>**3. Update the field Type to type in 2.4.3**<br>**4. Update the field data description in**<br>**2.4.4**|**2022-02-16**||
|**v2.1**|**1.Add errorMsg to the callback**<br>**notification of 2.3.3**<br>**2.Add more error codes and error**<br>**messages to 3.1**|**2022-02-22**||
|**v2.2**|**Update metaData parameter type to**<br>**Map**|**2022-03-11**||
|**v2.3**|**Update signature sample**|**2023-03-06**||
|**v2.4**|**Add query supported bank list endpoint**|**2024-01-04**||
|**v2.5**|**Update Order Notification API endpoint**|**2024-02-18**||
|**v2.6**|**Update Error Codes list**|**2024-02-26**||
|**v2.7**|**Update Balance query response**|**2024-03-26**||
|**v2.8**|**Update betting customer validate**|**2024-04-03**||
|**v2.9**|**Update betting provider**|**2024-05-21**||



### **Revision history** 

|1 Overview.................................................................................................................. 4|
|---|
|1.1 Request Rules......................................................................................................4|
|1.2 Security Control...................................................................................................5|
|1.3 Integration Business Process................................................................................. 5|
|2 API Description......................................................................................................... 5|
|2.1 Create Order........................................................................................................6|
|2.1.1 Request Path................................................................................................. 6|
|2.1.2 Request Method.............................................................................................6|
|2.1.3 Request Parameter......................................................................................... 6|
|2.1.4 Response Parameter....................................................................................... 8|
|2.1.5 Example of successfully returned values...........................................................8|
|2.2 Order Status Query API........................................................................................9|
|2.2.1 Request Path................................................................................................. 9|
|2.2.2 Request Method.............................................................................................9|
|2.2.3 Request Parameter......................................................................................... 9|
|2.2.4 Response Parameter....................................................................................... 9|



2 / 21 

|2.2.5 Examples of successfully returned value.........................................................10|
|---|
|2.3 Order Notification API (this API is provided by merchant).....................................10|
|2.3.1 Request Path............................................................................................... 10|
|2.3.2 Request Method...........................................................................................10|
|2.3.3 Request Parameter....................................................................................... 11|
|2.3.4 Response Parameter..................................................................................... 12|
|2.4 Merchant balance query API............................................................................... 12|
|2.4.1 Request Path............................................................................................... 12|
|2.4.2 Request Method...........................................................................................12|
|2.4.3 Request Parameter....................................................................................... 13|
|2.4.4 Response Parameter..................................................................................... 13|
|2.5 bank account validate......................................................................................... 13|
|2.5.1 Request Path............................................................................................... 13|
|2.5.2 Request Method...........................................................................................14|
|2.5.3 Request Parameter....................................................................................... 14|
|2.5.4 Response Parameter..................................................................................... 14|
|2.5.5 Example of successfully returned value..........................................................14|
|2.6 Opay wallet validate...........................................................................................15|
|2.6.1 Request Path............................................................................................... 15|
|2.6.2 Request Method...........................................................................................15|
|2.6.3 Request Parameter....................................................................................... 15|
|2.6.4 Response Parameter..................................................................................... 15|
|2.7 Betting providers............................................................................................... 16|
|2.7.1 Request Path............................................................................................... 16|
|2.7.2 Request Method...........................................................................................16|
|2.7.3 Request Parameter....................................................................................... 16|
|2.7.4 Response Parameter..................................................................................... 16|
|2.8 Betting customer validate....................................................................................17|
|2.8.1 Request Path............................................................................................... 17|
|2.8.2 Request Method...........................................................................................17|
|2.8.3 Request Parameter....................................................................................... 17|



3 / 21 

|2.8.4 Response Parameter..................................................................................... 17|
|---|
|2.9 Supported bank list query API.............................................................................18|
|2.9.1 Request Path............................................................................................... 18|
|2.9.2 Request Method...........................................................................................18|
|2.9.3 Request Parameter....................................................................................... 18|
|2.9.4 Response Parameter..................................................................................... 18|
|3 Appendix................................................................................................................ 19|
|3.1 Error Codes.......................................................................................................19|
|3.2 Signature & verifySign sample for Java................................................................20|



# **1 Overview** 

The API interface specification is the protocol specification between the platform and the product demander to realize the integration between the existing business of the product demander and the platform. 

**Note: 1. Our company reserves the right to add or delete return codes. Please provide corresponding technical support. If there is a return code that does not exist in the document, please contact the relevant staff of our company for assistance in a timely manner. Do not treat the order as failed directly.** 

**2. As for the order status of the transaction, please refer to the order status returned by our company.** 

**3. order status enumeration** ： INITIAL, PENDING, CHECKING, SUCCESS, FAIL , CLOSE, RETURN; 

## **1.1 Request Rules** 

|**Rule**|**Description**|
|---|---|
|Testing<br>environment<br>domain|https://testapi.opaycheckout.com|
|Production<br>environment<br>domain|https://liveapi.opaycheckout.com|
|Request method|POST|
|Parameter format|application/json|
|Character encoding|apply UTF-8 character code|



4 / 21 

|Encryption method|RSA 256|
|---|---|
|RSA Key Size|2048 bits|



## **1.2 Security Control** 

The system adopts the following methods to ensure the security of product demander platform: 1. The merchant and Opay shall exchange public keys and keep their own private keys to prevent the disclosure of private keys; 

2. The API platform uses the public key provided by the merchant, verify whether the data source is payout initiated by the merchant side; 

3. The maximum order number submitted by the Merchant side is 32 digits, and the Merchant shall guarantee its uniqueness; 

4. The merchant shall provide the IP address of the requesting side to the API platform, and the platform will perform whitelist verification on the IP address; 

5. Merchants need to open the payout payment method on MD platform. During the payout transaction, API will verify whether the payment method is open. 

6. The user's bank account and bank code provided by the merchant shall be correct, and the API side will verify the user's account information; 

## **1.3 Integration Business Process** 

Merchants call the Opay API to create a single order. After receiving the transaction request, Opay API service will collect and verify the information. After passing the verification, it will place the order and return the initial state of the order to the transaction request side of the merchant. Placing an order and transaction are asynchronous. When the transaction is successful at the end, the transaction result will be sent via callback URL sent by the merchant when placing order. The merchant side can also proactively obtain the order details through the order status query API. 

# **2 API Description** 

HTTP HEADER Format 

Authorization : Bearer {sign} MerchantId : "{merchnatId}" 

Content-Type :"application/json" 

|Parameter|Type|Description|
|---|---|---|
|Authorization|String|Bearer {sign}|



5 / 21 

|MerchantId|String|Merchant ID on merchant|
|---|---|---|
|||dashboard|
|Content-Type|String|application/json|



Note: 

{RequestBody} is signed using the RSA256 algorithm: 

1) HTTP post request 

2) sign = signByPrivateKey (The request body is binary data and is signed with the merchant's private key.) 

3) Authorization = "Bearer "+ sign 

Please refer to the java sample. See: **3.2 Signature & verifySign sample for Java** 

## **2.1 Create Order** 

## **2.1.1 Request Path** 

/api/v1/international/payout/createSingleOrder 

## **2.1.2 Request Method** 



|POST<br>**2.1.3 Request**<br>Parameter|**Paramet**<br>Type|**er**<br>Mandatory<br>or not|Example Value|Field Description|
|---|---|---|---|---|
|payoutType|String|Yes|BankTransfer|Nigeria supports:<br>BankTransfer, OpayWalletNg,Betting|
|notifyUrl|String|Yes||Notify merchants result (length less<br>than 256) order result will be notified<br>via this url with merchant order ID and<br>order status|
|merchantOrderNo|String|Yes|1000000000000<br>000001|Merchant request details<br>No more than 32 digits|
|country|String|Yes|NG|Country NG for Nigeria|
|amount|Long|Yes|100|Cent unit. 100 Kobo=1 NGN|
|currency|String|Yes|NGN|Currency: NGN for Nigeria|
|language|String|Yes||Language:<br>en_US for English<br>etc.<br>en_US("en_US","English"),|



6 / 21 

||||en("en", "English"),<br>alb("alb","Arabic");|
|---|---|---|---|
|remark|String|No|Notes: no more than 128 digits|
|metaData|Map|Yes|Please note the phone number rule is<br>+2 as the start<br>Nigeria phone number: +234 1 xxx<br>xxxx (11 digits）<br>For example:<br>Nigeria: +23412345678|
||||-------------------------------------<br>If pass BankTransfer for payoutType<br>{<br>"accountBankCode": "MIDB",<br>"accountName": "David",<br>"accountNo": "0123334535390"<br>}<br>-------------------------------------<br>If pass ReferenceCode for payoutType<br>{<br>"serviceProvider": "Opay",<br>"phone": "+201234567891",<br>"customerName": "David",<br>"expireTime":"60"<br>}<br>Please note the unit of expire time is<br>minute, “60” refers to 60 minutes (one<br>hour)<br>-------------------------------------<br>If pass OpayWallet or OpayWalletNg<br>for payoutType<br>{<br>"customerName": "David",<br>"phone": "+23412345678"<br>}<br>If pass MobileRecharge for<br>payoutType<br>{<br>"serviceProvider": "Etisalat",<br>"phone": "+201234567891",<br>"customerName": "David"|



7 / 21 

|}<br>-------------------------------------<br>If pass TelecomVoucher for<br>payoutType<br>{<br>"opayServiceCode": "call voucher<br>query API to access",<br>"serviceProvider": "Etisalat",<br>"voucherId": call voucher queryAPI to<br>access",<br>"phone": "+201234567891",<br>"customerName": "David"<br>}<br>-------------------------------------<br>If pass Betting for payoutType<br>{<br>"provider": "BET9JA",<br>"customerId": "1259257649121"<br>}<br>**2.1.4 Response Parameter**<br>Returnparameter description<br><br><br> <br>|
|---|
|Parameter<br>Type<br>Example value<br>Field description|
|code<br>String<br>00000<br>Return transaction code: more details please refer to<br>**Appendix 3.1 Error Codes**|
|message<br>String<br>Transaction success<br>Return code description|
|data<br>Object<br>{<br>"orderNo": "210927510356272646",<br>"reference": "203333311213200005",<br>"orderStatus": "INITIAL"<br>}|
|Returnparameter data description|
|Parameter<br>Type<br>Field Description|
|orderNo<br>String<br>Opay order number<br><br><br>|
|reference<br>String<br>Merchant order number<br>dS<br>Si<br>Od|
|orertatus<br>trng<br>rer status|



## **2.1.5 Example of successfully returned values** 

{ "code": "00000", "message": "SUCCESSFUL", "data":{ "orderNo": "210927510356272646", 

8 / 21 

"reference": "203333311213200005", "orderStatus": "INITIAL" 

} 

} 

## **2.2 Order Status Query API** 

## **2.2.1 Request Path** 

/api/v1/international/payout/queryorder 

## **2.2.2 Request Method** 



POST 

## **2.2.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|reference|String|No|10000100|Merchant order number（at least pass one<br>order number, Opay or merchant order<br>number）|
|orderNo|String|No|10000100|Opay order number|
|country|String|No|NG|<br>Country NG for Nigeria|



## **2.2.4 Response Parameter** 

Return parameter description 

|Parameter|Type|Example value|Field description|
|---|---|---|---|
|code|String|00000|Transaction return code: for details please refer to<br>**Appendix 3.1 Error Codes**|
|message|String|Transaction success|Return code description|
|data|Object||{<br>"reference":"303333311213100008",<br>"orderNo":"210914140336850425",<br>"orderStatus":"SUCCESS",<br>"errorCode":"",<br>"errorMsg":"",<br>"additionalField":{<br>"referenceCode":"123456"|



9 / 21 



}, "amount":{ "total":700, "currency":"NGN" } } 

## **2.2.5 Examples of successfully returned value** 

{ "code":"00000", "message":"SUCCESSFUL", "data":{ "reference":"303333311213100008", "orderNo":"210914140336850425", "orderStatus":"SUCCESS", "errorCode":"", "errorMsg":"", "additionalField":{ "referenceCode":"123456" }, "amount":{ "total":700, "currency":"NGN" } } } 



## **2.3 Order Notification API (this API is provided by merchant)** 

## **2.3.1 Request Path** 

URL: notifyUrl based on the created order 

## **2.3.2 Request Method** 

POST Request 

10 / 21 

## **2.3.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|payload|JSONOb<br>ject|Yes|See Payload Parameter|Notification payload with format<br>JSONObject|
|sha512|String|No|10000100|Signature merchant should verification|
|type|String|No|NG|Notification type:<br>transaction-status|



#### **Payload Parameter:** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|country|String|Yes|NG|Country NG for Nigeria|
|amount|String|Yes|100|Naira unit.|
|bussinessTyp<br>e|String|No|MUAATransfer||
|currency|String|Yes|NGN|Currency: NGN for Nigeria|
|channel|String|No|Web||
|channelOrder<br>No|String|No|||
|displayedFail<br>ure|String|No||The error message for failed transaction.|
|fee|String|No|||
|payChannel|String|No|BalancePayment||
|reference|String|Yes||Merchant order no|
|status|String|Yes||successful<br>failed|
|timestamp|String|Yes||Notification time|
|transactionId|String|Yes||Opay transaction Id|
|token|String|Yes||Transaction token, default: same as|
|||||transactionId|
|{|||||
|"payload":{<br>"amount": "2|000.00",||||
|"bussinessT<br>"channel": "|ype": "MUAA<br>Web",|Transfer",|||
|"country": "|NG",||||
|"currency":|"NGN",||||
|"displayedFa|ilure":"",||||
|"fee": "4.00"|,||||
|"instrument-|id": "useless",||||
|"instrumentI|d": "useless",||||
|"instrumentT|ype": "coins"|,|||
|"instrument_|id": "useless",||||
|"payChanne|l": "BalancePa|yment",|||
|"reference":|"107517656_|84473739604|85900288",||
|"refunded":|false,||||
|"remark": "|Withdraw from|iLOTBet",|||



11 / 21 





<!-- Start of picture text -->
OPay < Download Apps Support liveMode  V a |<br>, Account Details<br>i$ Promocodes<br>Y}¥<br>8Subscription Business Financial API Keys & Web Hook Settings IP WhiteListing<br>D Sharetink Pay In API Keys » Update Keys Live Mode<br>a°Gustones CpayesseneyiagSecret Key ‘w Copy<br>hee= salar ~ OPAY#eeoreseengiggPublic Key (@ Copy<br>logistics Quer Pay Out RSA Public Key<br>Activate<br>4 Transactions<br>I Settlement Webhook URL<br>° Report Center http://hw-test-online-channe!-outflux-callback. kong-hw-test.cpayweb.com/opay/callback/outflux/transfer<br>A t Setting ¥<br><!-- End of picture text -->

## **2.4.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|country|String|Yes|NG|Country|
|currency|String|Yes|NGN|Currency|
|type|String|Yes|CASH_ACCOUNT|CASH_ACCOUNT<br>UNSETTLED_ACCOUNT|



## **2.4.4 Response Parameter** 

Return Parameter Description 

|Return Paramet<br>Parameter|er Description<br>Type|Example value|Field description|
|---|---|---|---|
|code|String|00000|Transaction return code: for details please refer to<br>**Appendix 3.1 Error Codes**|
|message|String|Transaction<br>success|Return code description|
|data|Object||{<br>"country": "NG",<br>"balance":{<br>"total":700,<br>"currency":"NGN"<br>}<br>}|
|Returnparamet|er data descripti|on||
|Parameter|Type|Field desc|ription|
|country|String|Yes||
|balance|Object|||
|Amount descrip|tion|||
|Parameter|Type|Field desc|ription|
|total|Long|Yes||
|currency|String|Yes||



## **2.5 bank account validate** 

## **2.5.1 Request Path** 

/api/v1/international/payout/bank-account-validate 

13 / 21 

## **2.5.2 Request Method** 

POST 

## **2.5.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|accountBankCod|String|Yes|058|Account bank code|
|e|||||
|accountNo|Object|Yes|2215381176|Account number|



## **2.5.4 Response Parameter** 

|Return Parameter Description<br>Parameter<br>Type<br>Example value|Field Description|
|---|---|
|code<br>String<br>00000|Transaction return code: for details please refer<br>to**Appendix 3.1 Error Codes**|
|message<br>String<br>Transaction success|Return code description|
|data<br>Object|{<br>"accountBankCode":"058",<br>"accountNo":"2215381176",<br>"accountName":"acc name"<br>}|
|Returnparameter data description||
|Parameter<br>Type<br>Field descript|ion|
|accountBankCode<br>String<br>Account bank|code|
|accountNo<br>String<br>Account bank|number|
|accountName<br>String<br>Account nam|e|



## **2.5.5 Example of successfully returned value** 

{ "code": "00000", "message": "SUCCESSFUL", "data":{ "accountBankCode":"058", "accountNo":"2215381176", "accountName":"acc name" 

14 / 21 

} 

} 

## **2.6 Opay wallet validate** 

## **2.6.1 Request Path** 

/api/v1/international/payout/opay-wallet-validate 

## **2.6.2 Request Method** 

POST 

## **2.6.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|phone|String|Yes|1129000042|Phone number for Opay wallet|



## **2.6.4 Response Parameter** 

Return Parameter Description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|code|String|00000|Transaction return code: more details please<br>referto**Appendix3.1 Error Codes**|
|message|String|Transaction success|Return code description|
|data|Object||{<br>"phone":"1129000042",<br>"firstName":"f name",<br>"lastName":"l name"<br>}|



#### Return parameter data description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|phone|String||Phone number for opay wallet|
|firstName|String||Customer first name|
|lastName|String||Customer last name|



15 / 21 

## **2.7 Betting providers** 

## **2.7.1 Request Path** 

/api/v1/international/payout/betting-providers 

## **2.7.2 Request Method** 

POST 

## **2.7.3 Request Parameter** 

|<br>Parameter|<br>Type<br>Mandatory<br>or not<br>Example value<br>Field description|
|---|---|
|country<br>**2.7.4 Resp**<br>Return Paramete|String<br>Yes<br>NG<br>Country code:NG<br>**onse Parameter**<br>r Description<br> <br>|
|Parameter|Type<br>Example value<br>Field Description|
|code|String<br>00000<br>Transaction return code: more details please<br>referto**Appendix3.1 Error Codes**|
|message|String<br>Transaction success<br>Return code description|
|data|Object<br>[{<br>"provider": "BET9JIA",<br>"providerLogoUrl": "http://logourl.com",<br>"minAmount": "1000000",<br>"maxAmount": "200000000"<br>},{<br>"provider": "BETTING",<br>"providerLogoUrl": "http://logourl.com",<br>"minAmount": "1000000",<br>"maxAmount": "200000000"<br>}]|



#### Return parameter data description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|provider|String||Provider for betting.|
|providerLogoUrl|String||Provider logo url.|



16 / 21 

|minAmount|String|Min amount|
|---|---|---|
|maxAmount|String|Max amount|



## **2.8 Betting customer validate** 

## **2.8.1 Request Path** 

/api/v1/international/payout/betting-customer-validate 

## **2.8.2 Request Method** 

POST 

## **2.8.3 Request Parameter** 

|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|serviceType|String|Yes|Betting|Service type|
|provider|String|Yes|BET9JA|Betting provider|
|customerId|String|Yes|1259257649123|The customerId of the betting<br>provider.|



## **2.8.4 Response Parameter** 

Return Parameter Description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|code|String|00000|Transaction return code: more details please<br>refer to**Appendix 3.1 Error Codes**|
|message|String|Transaction success|Return code description|
|data|Object||{<br>"provider": "BET9JA",<br>"customerId": "1259257649123",<br>"firstName": "OHWOFASS RICHMOOND",<br>"lastName": "LUCKN",<br>"userName": "OHWOFASS RICHMOOND<br>LUCKN"<br>}|



Return parameter data description 

17 / 21 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|provider|String||Provider for betting.|
|serviceType|String||Service type.<br>Betting|
|customerId|String||The customer Id of the provider.|
|firstName|String||First name of the customer|
|lastName|String||Last name of the customer|
|userName|String||Username of the customer|



## **2.9 Supported bank list query API** 

## **2.9.1 Request Path** 

/api/v1/international/banks 

## **2.9.2 Request Method** 

POST 

## **2.9.3 Request Parameter** 



|Parameter|Type|Mandatory<br>or not|Example value|Field description|
|---|---|---|---|---|
|countryCode|String|Yes|NG|Country NG for Nigeria|



## **2.9.4 Response Parameter** 

Return Parameter Description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|code|String|00000|Transaction return code: more details please<br>refer to**Appendix 3.1 Error Codes**|
|message|String|Transaction success|Return code description|
|data|List<Transfer<br>Bank>||[<br>{<br>"bankCode": "044",<br>"bankName": "Access Bank"<br>},<br>{<br>"bankCode": "401",<br>"bankName": "ASOSavings & Loan"<br>}<br>]|



18 / 21 

Return parameter data description 

|Parameter|Type|Example value|Field Description|
|---|---|---|---|
|bankCode|String|044|Bank code for transfer|
|bankName|String|Access Bank|Bank name|



# **3 Appendix** 

## **3.1 Error Codes** 

|**Error Code**|**Error Message**|**Note**|
|---|---|---|
|01001|Closed Account||
|01002|Dormant Account||
|01003|Missing Account Number||
|01004|Insufficient Payment Details||
|01005|Incorrect Account Number||
|01006|Invalid Currency||
|01007|Frozen or Blocked Account||
|01008|Rejected By Beneficiary||
|01009|Regulatory Reason||
|01010|Insufficient Fund||
|01011|Invalid Credit Instructions||
|01012|Invalid Account Details||
|01013|Duplicate Payment||
|01014|Customer Deceased||
|01101|Duplicate Message Id||
|01102|Invalid Bank Code||
|01103|Duplicate Transaction Id||
|01104|Invalid Corporate Code||
|01105|Invalid Debtor Account||
|01107|Invalid Branch||
|01108|Invalid Signature||
|01111|Invalid Category Code||
|01188|Internal Error||
|02000|Authentication failed||
|02007|Merchant has not gone live||
|02016|IP verification failed||
|02017|The payment method has not been opened yet||
|02812|Transaction does not exist.||
|51004|Account Name cannot be empty||
|51005|Account No cannot be empty||
|51006|Account Bank Code cannot be empty||
|51007|Customer Name cannot be empty||
|51008|VoucherId cannot be empty||
|51009|Service Provider cannot be empty||
|51010|Payout Type cannot be empty<br>||
|51011|Country cannot be empty||
|51012|Notification URL cannot be empty||



19 / 21 

|51013|MerchantId cannot be empty|
|---|---|
|51014|Currency cannot be empty|
|51015|Language cannot be empty|
|51016|Amount cannot be empty|
|51017|Merchant Order No cannot be empty|
|51018|Opay Service Code cannot be empty|
|5001|ORDER IS EXIST|
|5006|BALANCE NOT ENOUGH|
|5011|ACCOUNTNAME NOT EMPTY|





## **3.2 Signature & verifySign sample for Java** 



public static String signByPrivateKey(String body,String key) throws RuntimeException{ try { byte[] keyBytes = Base64.decodeBase64(key.getBytes("UTF-8")); PKCS8EncodedKeySpec pkcs8KeySpec = new PKCS8EncodedKeySpec(keyBytes); KeyFactory keyFactory = KeyFactory.getInstance("RSA"); PrivateKey privateKey = keyFactory.generatePrivate(pkcs8KeySpec); Signature signature = Signature.getInstance("SHA256withRSA"); signature.initSign(privateKey); signature.update(body.getBytes("UTF-8")); byte[] signatureBytes = Base64.encodeBase64(signature.sign()); return new String(signatureBytes, "UTF-8"); } catch (Exception e) { throw new RuntimeException(e); 

20 / 21 



<!-- Start of picture text -->
}<br>}<br>public static boolean verify(String data, String publicKey, String sign) throws<br>RuntimeException{<br>try {<br>byte[] keyBytes = Base64.decodeBase64(publicKey.getBytes("UTF-8"));<br>X509EncodedKeySpec keySpec = new X509EncodedKeySpec(keyBytes);<br>KeyFactory keyFactory = KeyFactory.getInstance("RSA");<br>PublicKey pubKey = keyFactory.generatePublic(keySpec);<br>Signature signature = Signature.getInstance("SHA256withRSA");<br>signature.initVerify(pubKey);<br>signature.update(data.getBytes("UTF-8"));<br>return signature.verify(Base64.decodeBase64(sign));<br>} catch (Exception e) {<br>throw new RuntimeException(e);<br>}<br>}<br><!-- End of picture text -->

21 / 21 

