## OPay Merchant Dashboard User Guide

- -Nigeria


Content

MD Operation Instruction.......................................................................................................................3

[1. Dashboard Munu................................................................................................................................ 3](#page-0)

[2. Sharelink Menu................................................................................................................................... 3](#page-0)

[3. Balance Menu......................................................................................................................................6](#page-0)

[4. Transactions Menu............................................................................................................................. 7](#page-0)

[5. Settlements](#page-0)

[Menu.............................................................................................................................11](#page-0)

[6. Account Details](#page-0)

[7. Users](#page-0)

[8. Roles](#page-0)

[Menu......................................................................................................................12](#page-0)

[Menu........................................................................................................................................16](#page-0)

[Menu........................................................................................................................................17](#page-0)

[9. Chargeback](#page-0)

[Menu............................................................................................................................ 19](#page-0)

[10. Payouts](#page-0)

[11.](#page-0)

[Menu................................................................................................................................. 24](#page-0)

[Single Payout Review](#page-0)

[Menu.........................................................................................................26](#page-0)

[12. Bulk Payout Review](#page-0)

[Menu............................................................................................................ 27](#page-0)

[13. Withdraw](#page-0)

[Menu...............................................................................................................................28](#page-0)

[14. Withdraw Transactions](#page-0)

[Menu.......................................................................................................28](#page-0)


## 1. Dashboard Menu

Dashboard menu displays merchant sale data, including conversion rate, sale progress, account balance, etc.

## 2. Sharelink Menu

Sharelink menu displays sharelink detail page and create share link

Click“Create Share Link” to create new payment link


## Create Link

—

*Click the share button under Actions to download the file template, edit the user phone number and email that will receive the link, upload user file and then click confirm*


UploadLink

Sharelink Details

Click copy button after“Payment Link”to copy the link, open it in the browser, enter user information and click “pay”


Click the delete button under Actions to delete the payment link

## 3. Balance Menu

Balance menu shows the capital flow; Available Balance represents available balance of merchants; Upcoming Balance shows the amount to be settled to the merchant


*Click“Advanced Search” to choose date, transaction type and account type*

*Click“Download”to download the selected capital flow, the file format is .xlsx*


## 4. Transactions Menu

This menu displays details of each transaction

*Enter merchant ID in“Search by merchant order No.”to view the transaction details of the merchant*

*Click“Advanced Search”，select order status, transaction time, transaction type, payment method, transaction order number, transaction code, Reference code, and amount range*


*Click“Export” to export the query result, file format: xlsx. The following are exported fields in the file*

*Click the green button under Action for each transaction to view the transaction details.*


## Transactions Details


## Transactions Details

Transactions Details

## 5. Settlements Menu

This menu generates settlement report according to the settlement cycle of merchants


Click“Download”under Operate for each settlement statement, download settlement details, the settlement file will be shown on the bottom left corner of the page.

- 6. Account Details Menu

This menu has 5 sections

- 1. Business：display basic information submitted during registration


## Account Details

- 2. Financial: display settlement account information


aS -—

Account Details

- 3. API keys & Web Hook: API keys and Web Hook can be set in this menu OPay h


Click “update keys”after Pay in API Keys, then click“confirm”. The Secret key and Public Key below will be reset, then click “copy” to copy the new KEY

Click “add” behind Pay Out RSA Public Key, fill in the box, then click“Send”, the register email will receive Verification Code, fill the code in the blank box, click “confirm” to successfully submit public key and wait for OPay team to review

- 4. Settings: select whether to receive email notifications


- 5. IP WHITELISTING：enter the whitelist IP, multiple IPs can be separated with comma. Payout enabling requires IP whitelist reporting MAN

## 7.Users Menu

This menu will display user list, new user can be created.


Click“New User”to create a new user, enter user email and choose role for this user, click“Create User”

Users

8.Roles Menu

This menu can display role list, and new role can be created


Click“New Role” to create new role, fill in the role name, select the menu for the role in the menu list, then click“Save Role”

aa


## 9. Chargeback Menu

- 1. Click Chargeback menu on the left, this page displays the list of chargeback transactions that are pending processing or have been processed

- 2. If there is chargeback transaction with status as “pending”, then it needs to be dealt with (the register email will receive the processing email)

At this time, account balance will be frozen, the frozen amount: order transaction amount+ chargeback service fee


- 3. Click the green button under Operate for chargeback transactions pending processing to process this chargeback order

- 4. Process the chargeback order with “accept” or “reject”

Accept: click “accepted” to accept this chargeback, the transaction amount will be returned to the card holder automatically

Deduct transaction amount+ chargeback service fee from merchant account


5. Reject: complaint process

a

5.1 Click“declined” and upload materials according to system prompt

- 5.2 enter decline reason, upload related transaction material, then click“declined”


5.3 the transaction status will be updated to “declined”

5.4 successful complaint, then transaction status will be updated to “Won” Unfreeze the merchant account, deduct chargeback service fee from merchant account

5.5 complaint failed, then the transaction status will be updated to “Lost” Deduct transaction amount+ chargeback service fee from merchant account


## 10. Payouts Menu

Merchants can select whether payout transaction needs to be reviewed. Merchant admin have the permission of payout setting


Click “Create Payout” to create payout, can select payout to bank account or OPay wallet

Click bank account or OPay wallet, fill in payout amount, and select single or bulk payout


Select single payout, fill in the beneficiary account information and payout description

Select bulk payout, download the bulk payout template, fill in payout information in line with the template, upload file and click confirm

## 11. Single Payout Review Menu


- OTP will be sent to the current operator email. Operator needs to check the email and enter OTP, click confirm to complete payout

- 12. Bulk Payout Review Menu


Bulk Payout Review

4Total Advanced Search

|

## 13. Withdraw Menu

Enter withdrawal amount and notes, click submit

## 14. Withdraw Transactions Menu

This menu displays the withdrawal records
