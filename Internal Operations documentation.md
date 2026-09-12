# E-Commerce Operations & Management System

## 1. Overview

This document describes the internal Operations, Inventory, Delivery, Returns, Accounting, Treasury, and Employee Management system that will be added to the existing E-Commerce platform.

The existing E-Commerce flow must remain intact.

The new system should be implemented as an **internal operations layer on top of the existing E-Commerce system**, without forcing customers to interact with internal company processes.

The customer should continue to use the website normally:

```text
Customer
   ↓
Website
   ↓
Checkout
   ↓
Order Created
```

After the order is created, it automatically enters the company's internal workflow.

Customer Service is **not mandatory** for website orders.

---

# 2. Core Principle

The system has two different perspectives:

### Customer Perspective

Customers should only see simple, understandable order statuses such as:

* Order Received
* Processing
* Shipping
* Out for Delivery
* Delivered
* Postponed
* Cancelled
* Returned

### Internal Perspective

Employees should see detailed operational statuses such as:

* New
* Checking
* Confirmed
* Postponed
* Cancelled
* Assigned to Representative
* Assigned to Shipping Company
* Out for Delivery
* Delivered
* Returned
* Partially Returned

The internal status must never be exposed directly to the customer unless explicitly mapped to a customer-facing status.

---

# 3. Order Sources

Orders can come from two sources.

## 3.1 Website Orders

The normal E-Commerce flow:

```text
Customer
   ↓
Website
   ↓
Checkout
   ↓
Order Created
   ↓
Checking
```

Customer Service does not need to process these orders before they enter Checking.

## 3.2 Customer Service Orders

Customer Service can manually create an order for a customer.

```text
Customer
   ↓
Customer Service
   ↓
Order Created
   ↓
Checking
```

Both order sources enter the same operational workflow after creation.

Every order should store its source.

Example:

```text
order_source:
    website
    customer_service
```

---

# 4. Order Numbering

The first customer-facing order number must start at:

```text
1001
```

Then:

```text
1002
1003
1004
...
```

The customer-facing order number should be independent from the database primary key.

Example:

```text
Database ID: 1
Order Number: 1001
```

---

# 5. Product System

Products must support two lifecycle modes:

1. Advertisement Product
2. Real Product

---

# 6. Advertisement Products

An Advertisement Product is a fully functional E-Commerce product that can be displayed and ordered even when there is no physical inventory.

It is NOT an incomplete product.

An Advertisement Product can contain:

* Product name
* Description
* Images
* Gallery
* Categories
* Price
* Sale price
* Sizes
* Colors
* Product variants
* SKU if required
* Weight ranges
* SEO data
* Product options
* Website visibility
* Any normal E-Commerce product information

The main difference is that inventory tracking is disabled.

Example:

```text
Product:
T-Shirt Black

Type:
Advertisement

Inventory Tracking:
Disabled

Website:
Active
```

Customers can see the product and place orders normally.

---

# 7. Converting Advertisement Product to Real Product

When the physical product becomes available in the warehouse, the same product should be converted from Advertisement Product to Real Product.

A new product should NOT be created.

The existing product keeps:

* Product ID
* URL
* Images
* SEO
* Variants
* Existing orders
* Existing product information

Only inventory tracking is enabled.

Example:

```text
Advertisement Product
        ↓
Enable Inventory Tracking
        ↓
Real Product
```

After conversion, warehouse employees can enter stock quantities.

Example:

```text
Black / S = 20
Black / M = 30
Black / L = 15

White / S = 10
White / M = 25
```

---

# 8. Product Sizes

The system must support product sizes such as:

```text
S
M
L
XL
XXL
```

Sizes should be reusable/master data.

However, the weight range must be defined **per Product Variant**, not globally on the Size.

This is important because the same size can have completely different weight ranges for different products.

Example:

### Product A

```text
S → 80kg - 90kg
M → 90kg - 100kg
L → 100kg - 110kg
```

### Product B

```text
S → 60kg - 70kg
M → 70kg - 80kg
L → 80kg - 90kg
```

Therefore:

```text
Product
   ↓
Product Variant
   ├── Size
   ├── Color
   ├── Weight From
   ├── Weight To
   └── Inventory
```

---

# 9. Product Variants

A product can have combinations of:

* Size
* Color
* Other product attributes

Example:

```text
T-Shirt

Black / S
Black / M
Black / L

White / S
White / M
White / L
```

Each variant can have its own:

* SKU
* Price if needed
* Weight range
* Inventory
* Images if needed
* Other variant-level information

---

# 10. Warehouse System

The current business has one warehouse.

However, the database and architecture must support multiple warehouses from the beginning.

The system should NOT be designed specifically for only one warehouse.

Example:

```text
Warehouse A
Warehouse B
Warehouse C
```

Inventory should be connected to:

```text
Warehouse
+
Product Variant
```

Example:

```text
Warehouse A
    T-Shirt / Black / M = 100

Warehouse B
    T-Shirt / Black / M = 50
```

The same Product Variant can therefore exist in multiple warehouses.

---

# 11. Inventory Management

Inventory must be tracked per Product Variant and Warehouse.

Example:

```text
Warehouse A

T-Shirt Black / S = 50
T-Shirt Black / M = 100
T-Shirt Black / L = 75
```

Inventory should support:

* Initial stock
* Stock additions
* Stock deductions
* Returns
* Adjustments
* Transfers between warehouses
* Reservations
* Inventory movement history

---

# 12. Inventory Reservation

Because inventory is not physically deducted when an order is initially created, the system should distinguish between:

* Physical Stock
* Reserved Stock
* Available Stock

Example:

```text
Physical Stock = 100
Reserved Stock = 10
Available Stock = 90
```

An order can reserve inventory without immediately changing the physical stock.

This prevents multiple customers from ordering more stock than is available.

---

# 13. Inventory Deduction

The physical inventory should NOT be deducted when the customer creates the order.

It should also NOT be deducted simply because the order was confirmed by Checking.

The actual stock deduction happens when Accounting confirms that the order was successfully delivered to the customer.

Example:

```text
Stock = 100

Order #1050
Black / M × 2

Order created:
Stock = 100

Order assigned to delivery:
Stock = 100

Customer receives order.

Accounting confirms:
"Delivered"

Stock becomes:
98
```

This is a critical business rule.

---

# 14. Order Workflow

The complete internal workflow is:

```text
Website / Customer Service
          ↓
        Order
          ↓
       Checking
          ↓
   ┌──────┼──────┐
   ↓      ↓      ↓
Confirmed Postponed Cancelled
   ↓
Delivery Manager
   ↓
┌─────────┴──────────┐
↓                    ↓
Representative     Shipping Company
↓                    ↓
Customer Delivery   Customer Delivery
└─────────┬──────────┘
          ↓
      Accounting
          ↓
   ┌──────┴───────┐
   ↓              ↓
Delivered       Returned
   ↓              ↓
Stock          Return Items
Deduction
```

---

# 15. Checking Department

The Checking department reviews orders.

Checking employees can change the internal order status to:

* Confirmed
* Postponed
* Cancelled

Every status change must be recorded in a complete history.

The system does NOT need a scheduled callback system at this stage.

If a customer is contacted and the order is postponed, the employee simply records the status change.

---

# 16. Order Status History

Order status changes must never overwrite the previous history.

Example:

```text
Order #1050

08/09/2026
New
Employee: Ahmed

08/09/2026
Postponed
Employee: Mohamed
Note: Customer requested postponement

09/09/2026
Postponed
Employee: Ahmed

10/09/2026
Confirmed
Employee: Ahmed
```

Every status history record should contain at least:

* Order ID
* Old Status
* New Status
* Employee
* Date/time
* Notes

---

# 17. Customer-Facing Order Status

Internal statuses must be mapped to customer-facing statuses.

Example:

| Internal Status              | Customer Status    |
| ---------------------------- | ------------------ |
| New                          | Order Received     |
| Checking                     | Processing         |
| Confirmed                    | Processing         |
| Assigned to Representative   | Shipping           |
| Assigned to Shipping Company | Shipping           |
| Out for Delivery             | Out for Delivery   |
| Delivered                    | Delivered          |
| Postponed                    | Postponed          |
| Cancelled                    | Cancelled          |
| Returned                     | Returned           |
| Partially Returned           | Partially Returned |

The customer should NOT see internal employee workflow terminology.

For example, the customer should not see:

```text
Checking
Assigned to Delivery Manager
Accounting Review
```

Instead, they should see:

```text
Order Received
Processing
Shipping
Out for Delivery
Delivered
```

---

# 18. Customer Order Tracking

From the customer's perspective:

```text
Order Created
      ↓
Order Received
      ↓
Processing
      ↓
Shipping
      ↓
Out for Delivery
      ↓
Delivered
```

The customer-facing status is automatically determined from the internal workflow.

There is no need for a separate employee to manually update the customer-facing status.

---

# 19. Customer Service

Customer Service has a Manager.

Customer Service employees can:

* Create customers
* Create orders
* View customers
* View orders
* Update customer information according to permissions
* Handle orders created through Customer Service

Website orders do not have to pass through Customer Service.

---

# 20. Delivery Manager

The Delivery Manager manages delivery assignments.

The Delivery Manager can:

* View confirmed orders
* Assign orders to delivery representatives
* Assign multiple orders to a representative
* Assign orders to a shipping company
* View representative deliveries
* View shipping company deliveries
* Manage delivery representatives
* Manage delivery areas

---

# 21. Delivery Representatives

Delivery representatives are NOT system users.

They do not need login accounts.

The Delivery Manager manages their information.

Representative information includes:

* Name
* Phone number
* Delivery route
* Governorates
* Cities
* Districts/Areas
* Active/Inactive status

A representative can cover multiple areas.

Example:

```text
Representative:
Ahmed

Coverage:
Cairo
Nasr City
New Cairo
Heliopolis
```

---

# 22. Delivery Routes

Delivery routes should support geographic coverage.

A route can include:

* Governorates
* Cities
* Districts
* Areas

This can later be used to help the Delivery Manager select the appropriate representative for an order based on the customer's address.

---

# 23. Shipping Companies

Shipping companies are also NOT system users.

They do not need login accounts.

The Delivery Manager and Accounting department interact with shipping companies.

Shipping company information may include:

* Company name
* Contact person
* Phone
* Address
* Pricing rules
* Delivery fees
* Return fees
* Bank information
* Notes
* Active/Inactive status

---

# 24. Shipping Company Orders

A shipping company may receive:

* Orders with cash collection
* Orders without cash collection
* Orders requiring other payment handling

The company may later transfer collected money to the business.

The system does not integrate directly with the shipping company's financial system.

All financial information received from the shipping company is entered manually by Accounting.

---

# 25. Accounting Department

Accounting is responsible for confirming the financial and delivery result of orders.

Accounting can:

* View orders assigned to representatives
* View orders assigned to shipping companies
* Record collected money
* Record partial payments
* Record unpaid orders
* Print orders
* Confirm successful delivery
* Record returns
* Record returned quantities
* Record financial transactions
* Reconcile shipping company payments

---

# 26. Delivery Confirmation

The representative delivers orders to customers and later returns to Accounting.

Accounting reviews the returned delivery information.

Accounting then confirms whether the order was:

* Delivered
* Partially delivered if applicable
* Returned
* Partially returned

The inventory deduction happens when Accounting confirms the order as delivered.

---

# 27. Order Payments

An order may have:

```text
Paid
Partially Paid
Unpaid
```

The payment method can be:

```text
Cash
Bank Transfer
Wallet
Other
```

Payment collection is manually recorded by Accounting.

There is no required payment gateway or banking integration.

---

# 28. Payment Integration

There is currently NO integration with:

* Banks
* Wallets
* Shipping companies
* Delivery representatives

The system does not automatically detect transfers.

Accounting employees manually enter financial transactions based on the actual payment or transfer.

Example:

```text
Order #1001

Amount:
500 EGP

Payment Method:
Cash

Collected By:
Accounting Employee

Date:
08/09/2026
```

---

# 29. Bank Transfer

If a shipping company transfers money to the business bank account, Accounting records the transfer manually.

Example:

```text
Source:
Shipping Company

Amount:
70,000 EGP

Method:
Bank Transfer

Destination:
CIB Bank Account

Reference:
TRX-12345

Date:
08/09/2026

Attachment:
Transfer Screenshot / PDF
```

The system does not verify the transfer automatically.

---

# 30. Treasury System

The system requires a complete Treasury module.

Treasury should support:

* Cash
* Bank accounts
* Wallets
* Transactions
* Transfers
* Income
* Expenses
* Reconciliation

Example:

```text
Treasury
│
├── Cash Accounts
├── Bank Accounts
├── Wallet Accounts
│
└── Transactions
```

---

# 31. Cash Accounts

Example:

```text
Main Cashbox

Balance:
150,000 EGP
```

Cash transactions may include:

* Customer payments
* Representative cash collection
* Expenses
* Cash transfers

---

# 32. Bank Accounts

The system should support multiple bank accounts.

Example:

```text
CIB
QNB
AlexBank
```

Each account has its own balance based on recorded transactions.

---

# 33. Wallet Accounts

The system should support multiple wallet accounts.

Example:

```text
Vodafone Cash
Etisalat Cash
Orange Cash
```

Wallet transactions are manually recorded.

---

# 34. Treasury Transactions

Every financial movement should be recorded as a transaction.

A transaction can contain:

* Amount
* Transaction type
* Payment method
* Source
* Destination
* Related order
* Related representative
* Related shipping company
* Employee
* Date/time
* Reference number
* Attachment
* Notes

---

# 35. Customer Payment Example

```text
Order #1050

Customer paid:
1,500 EGP

Payment Method:
Cash

Accounting employee records:

Income
+1,500 EGP
Destination: Main Cashbox
Related Order: #1050
```

---

# 36. Representative Payment Example

A representative returns to Accounting with collected money.

```text
Representative:
Ahmed

Orders:
15

Collected:
20,000 EGP

Payment Method:
Cash
```

Accounting records the amount received into the appropriate treasury account.

---

# 37. Shipping Company Reconciliation

Example:

```text
Delivered Orders:
500

Expected Customer Collection:
250,000 EGP

Shipping Fees:
15,000 EGP

Return Fees:
5,000 EGP

Net Amount Expected:
230,000 EGP
```

If the shipping company transfers:

```text
230,000 EGP
```

the account is fully reconciled.

If it transfers less:

```text
Expected:
230,000

Transferred:
220,000

Remaining:
10,000
```

The system should show the remaining amount.

---

# 38. Shipping Pricing

The system needs configurable shipping pricing.

Pricing may depend on:

* Governorate
* City
* District
* Delivery representative
* Shipping company
* Weight
* Number of items
* Delivery type

The exact pricing rules should remain configurable instead of hard-coded.

---

# 39. Return Fees

Shipping companies charge fees for returned orders.

Return fees should be configurable per shipping company.

Example:

```text
Delivery Fee:
50 EGP

Return Fee:
30 EGP
```

If:

```text
Total Orders:
1000

Returned:
200
```

Then:

```text
Return Fees = 200 × 30
            = 6,000 EGP
```

Return fees should be included when calculating the amount owed to/from the shipping company.

---

# 40. Returns

Returns must be handled at the **Order Item / Product Variant level**.

An entire order does not have to be returned.

Example:

```text
Order #1050

T-Shirt Black / M × 2
T-Shirt Black / L × 1
Pants / XL × 1
```

Customer returns:

```text
T-Shirt Black / M × 1
Pants / XL × 1
```

The system records:

```text
Returned:

T-Shirt Black / M = 1
Pants / XL = 1
```

The remaining items stay delivered.

---

# 41. Return Reasons

The system should support configurable return reasons, for example:

```text
Wrong Size
Defective Product
Customer Changed Mind
Wrong Product
Product Damaged
Other
```

---

# 42. Inventory Movement History

Every inventory change must be recorded.

Example:

```text
T-Shirt / Black / M

+100  Initial Stock
-2    Order #1001 Delivered
-1    Order #1005 Delivered
+1    Return #200
-10   Order #1010 Delivered
```

This allows the business to understand exactly why the current stock has its current value.

---

# 43. Audit Log

Important administrative actions should be logged.

Audit information should include:

* Employee
* Action
* Entity
* Entity ID
* Old value
* New value
* Date/time
* Notes if required

Example:

```text
Ahmed changed Order #1050

Old Status:
Postponed

New Status:
Confirmed

Date:
10/09/2026 10:32 PM
```

---

# 44. Employee Management

Employee records should include:

* Full name
* Residence/address
* Phone number
* National ID image
* Role
* Active/Inactive status

Employees who need system access should have login credentials.

Delivery representatives do not require system accounts.

---

# 45. Roles

Initial roles include:

```text
Chairman
Vice Chairman
Warehouse
Customer Service
Customer Service Manager
Checking
Delivery Manager
Accounting
System Administrator
```

Roles should use permission-based access.

---

# 46. Permissions

Permissions should be granular.

Examples:

```text
products.view
products.create
products.update

inventory.view
inventory.update
inventory.adjust
inventory.transfer

orders.view
orders.create
orders.update
orders.assign

orders.status.update

customers.view
customers.create
customers.update

returns.view
returns.create
returns.update

treasury.view
treasury.transaction.create
treasury.transaction.update

shipping_companies.view
shipping_companies.manage

delivery_representatives.view
delivery_representatives.manage

employees.view
employees.manage
```

The final permission list should be expanded according to the actual modules.

---

# 47. Chairman

The Chairman can:

* Manage product information
* Manage treasury
* View financial activity
* View cash transactions
* View bank transactions
* View wallet transactions
* Monitor business operations
* Access reports according to permissions

---

# 48. Vice Chairman

The Vice Chairman can:

* Manage product information
* Configure product variants
* Define product sizes
* Define product colors
* Define product weight ranges
* Perform other product management tasks according to permissions

---

# 49. Warehouse

Warehouse employees/managers can:

* View products
* View inventory
* Add stock
* Adjust stock
* Manage warehouse quantities
* Record inventory movements
* Handle returns back into inventory when applicable
* Transfer inventory between warehouses when multiple warehouses are available

---

# 50. Customer Service Manager

The Customer Service Manager can:

* Manage Customer Service employees
* View customers
* View orders
* Monitor orders created by Customer Service
* Manage Customer Service permissions

---

# 51. Checking

Checking employees can:

* View orders requiring checking
* Contact customers
* Change order status
* Confirm orders
* Postpone orders
* Cancel orders
* Add notes
* View previous status history

No callback scheduling system is required at this stage.

---

# 52. Delivery Manager

The Delivery Manager can:

* View ready orders
* Assign orders to representatives
* Assign orders to shipping companies
* Manage representatives
* Manage delivery routes
* View delivery assignments
* Track orders by representative
* Track orders by shipping company

---

# 53. Accounting

Accounting can:

* View delivery orders
* Receive money from representatives
* Record customer payments
* Record partial payments
* Record unpaid orders
* Confirm order delivery
* Record returns
* Record returned quantities
* Print orders
* Record shipping company transfers
* Record bank transfers
* Record wallet transactions
* Reconcile shipping companies
* Manage treasury transactions according to permissions

---

# 54. Reports

The system should eventually provide reports for:

### Orders

* Orders by status
* Orders by source
* Orders by date
* Orders by representative
* Orders by shipping company
* Delivered orders
* Cancelled orders
* Postponed orders
* Returned orders

### Inventory

* Current stock
* Available stock
* Reserved stock
* Stock movements
* Product/variant stock
* Warehouse stock
* Low-stock products

### Delivery

* Orders per representative
* Orders per shipping company
* Delivered orders
* Returned orders
* Delivery performance

### Accounting

* Total collected
* Paid orders
* Partially paid orders
* Unpaid orders
* Representative collections
* Shipping company collections

### Treasury

* Cash balance
* Bank balances
* Wallet balances
* Income
* Expenses
* Transfers
* Outstanding amounts

---

# 55. Important Separation of Concerns

The system must keep these concepts separate:

```text
Order Status
Payment Status
Inventory Status
Delivery Assignment
Customer-Facing Status
```

They should NOT be represented by one single status field.

For example:

```text
Order Status:
Delivered

Payment Status:
Partially Paid

Inventory:
Deducted

Delivery:
Representative

Customer Status:
Delivered
```

This separation is required for accurate business logic.

---

# 56. Recommended High-Level Architecture

```text
E-COMMERCE
│
├── Products
│   ├── Products
│   ├── Categories
│   ├── Sizes
│   ├── Colors
│   ├── Product Variants
│   └── Product Weight Ranges
│
├── Inventory
│   ├── Warehouses
│   ├── Stock
│   ├── Reservations
│   ├── Stock Movements
│   └── Transfers
│
├── Customers
│
├── Orders
│   ├── Orders
│   ├── Order Items
│   ├── Order Status History
│   ├── Payments
│   └── Delivery Assignments
│
├── Checking
│
├── Delivery
│   ├── Representatives
│   ├── Routes
│   └── Shipping Companies
│
├── Returns
│   ├── Returns
│   ├── Return Items
│   └── Return Reasons
│
├── Accounting
│
├── Treasury
│   ├── Cash Accounts
│   ├── Bank Accounts
│   ├── Wallet Accounts
│   └── Transactions
│
├── Employees
│
├── Roles & Permissions
│
├── Audit Logs
│
└── Reports
```

---

# 57. Final Business Flow

The complete business flow should be:

```text
                         CUSTOMER
                            │
                 ┌──────────┴──────────┐
                 │                     │
              WEBSITE            CUSTOMER SERVICE
                 │                     │
                 └──────────┬──────────┘
                            ↓
                          ORDER
                            ↓
                         CHECKING
                            │
                  ┌─────────┼─────────┐
                  ↓         ↓         ↓
              Confirmed  Postponed  Cancelled
                  │
                  ↓
           DELIVERY MANAGER
                  │
          ┌───────┴────────┐
          ↓                ↓
    REPRESENTATIVE    SHIPPING COMPANY
          │                │
          └───────┬────────┘
                  ↓
                CUSTOMER
                  ↓
             DELIVERY RESULT
                  ↓
              ACCOUNTING
                  │
          ┌───────┴────────┐
          ↓                ↓
      DELIVERED         RETURNED
          │                │
          ↓                ↓
   DEDUCT INVENTORY    RETURN ITEMS
          │
          ↓
        TREASURY
```

---

# 58. Customer Experience Flow

The customer should experience a simple flow:

```text
Order Received
      ↓
Processing
      ↓
Shipping
      ↓
Out for Delivery
      ↓
Delivered
```

The customer should never be required to understand:

* Checking
* Accounting
* Delivery Manager
* Representative Assignment
* Shipping Company Reconciliation
* Treasury
* Internal status changes

These are internal company processes.

---

# 59. Main Design Principle

The most important requirement is:

> **Do not change the existing E-Commerce customer journey.**

The current website should continue working normally.

The new functionality should extend the existing system with:

```text
Operations
+
Inventory
+
Delivery
+
Returns
+
Accounting
+
Treasury
+
Employee Management
+
Reports
```

The website remains an E-Commerce platform.

The new modules operate as an internal business management layer connected to the existing orders, customers, products, and inventory.

---

# 60. Future Scalability

The system should be designed to support future expansion without major database redesign.

Potential future requirements include:

* Multiple warehouses
* More delivery representatives
* More shipping companies
* Multiple bank accounts
* Multiple wallets
* More payment methods
* Advanced delivery routes
* Advanced inventory reservations
* Warehouse transfers
* More detailed accounting
* Automated notifications
* Customer SMS/WhatsApp notifications
* Advanced reporting
* Additional roles and permissions

The initial implementation should therefore avoid hard-coding assumptions such as:

```text
One warehouse only
One bank account only
One wallet only
One shipping company only
```

The current business may use one warehouse, but the architecture should support multiple warehouses.
