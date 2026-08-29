# **Payout API integration** 

1. Register a merchant account on the OPay online gateway, refer to the MD User Guide 

2. Merchant generates RSA public and private keys in the test environment and production environment 

3. Merchant submits the public key of the test environment and the production environment on **Merchant’s dashboard-Account Details-API Keys & Web Hook.** If submitted, the merchant needs to tell Opay to review. 

4. Merchant configures their IP into the whitelist of the test environment and the production environment on **Merchant’s dashboard-Account Details-IP WhiteListing** 

5. Refer to the Payout interface document for integration Test. For the test information of sandbox environment, please refer to the OPay Payout Test Info.xlsx 6. If merchant also need to configure OPay IP list to whitelist, please refer to the following： 

Opay Test IP: <u>119.13.76.156</u> Opay Product IP: 159.138.170.59 

# **Payout balance change logic** 

1. Firstly, merchants need to transfer money to OPay's finance team, pre-charge mode, and OPay finance team will deposit to merchants' available balance account. 

2. All Payout shall be deducted from the **merchant's available balance account** 

- 3.Payout MDR are determined by the business team 

4.Payout rules, from the available balance account for external deduction. For example, if the Payout payment amount is 100NGN, the MDR is 1% and the available balance account has 1000NGN, then 101NGN will be deducted from the available balance account of the merchant after the successful payment, and the remaining amount of the available balance account will be 899NGN. 

