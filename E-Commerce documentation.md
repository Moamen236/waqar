# Fashion E-Commerce Platform

A production-ready full-stack fashion e-commerce and retail operations platform built with **Laravel, Inertia.js, React, TypeScript, and Tailwind CSS**.

The platform provides a complete online shopping experience combined with product management, multi-warehouse inventory, area-based shipping, Cash on Delivery, returns, promotions, treasury management, dynamic roles and permissions, notifications, reporting, and audit logging.

---

## Table of Contents

- [Project Overview](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#project-overview)
- [Project Goals](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#project-goals)
- [Technology Stack](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#technology-stack)
- [Architecture](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#architecture)
- [Core Modules](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#core-modules)
- [Business Rules](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#business-rules)
- [Customer Features](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#customer-features)
- [Admin Features](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#admin-features)
- [Product Management](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#product-management)
- [Inventory Management](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#inventory-management)
- [Warehouse Management](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#warehouse-management)
- [Shipping](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#shipping)
- [Shopping Cart](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#shopping-cart)
- [Checkout](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#checkout)
- [Orders](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#orders)
- [Cash on Delivery](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#cash-on-delivery)
- [Returns & Refunds](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#returns--refunds)
- [Reviews](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#reviews)
- [Coupons & Promotions](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#coupons--promotions)
- [Treasury](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#treasury)
- [Expenses](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#expenses)
- [Roles & Permissions](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#roles--permissions)
- [Notifications](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#notifications)
- [Reports](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#reports)
- [SEO](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#seo)
- [Database Architecture](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#database-architecture)
- [Database Tables](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#database-tables)
- [Database Relationships](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#database-relationships)
- [API Architecture](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#api-architecture)
- [API Endpoints](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#api-endpoints)
- [Customer Pages](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#customer-pages)
- [Admin Pages](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#admin-pages)
- [Order Workflow](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#order-workflow)
- [Inventory Workflow](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#inventory-workflow)
- [Treasury Workflow](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#treasury-workflow)
- [Recommended Laravel Structure](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#recommended-laravel-structure)
- [Recommended React/Inertia Structure](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#recommended-reactinertia-structure)
- [Routes](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#routes)
- [Security](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#security)
- [Performance](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#performance)
- [Queues & Scheduled Tasks](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#queues--scheduled-tasks)
- [Development Phases](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#development-phases)
- [Recommended Claude Code Skills](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#recommended-claude-code-skills)
- [Future Enhancements](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#future-enhancements)
- [Definition of Done](https://chatgpt.com/c/6aa087c4-e958-83ea-b494-a2ffc36c13fd#definition-of-done)

---



# Project Overview

The application is a **single-store fashion e-commerce platform**.

It supports the complete lifecycle:

```text
Product
   ↓
Inventory
   ↓
Customer
   ↓
Cart
   ↓
Checkout
   ↓
Cash on Delivery
   ↓
Order
   ↓
Warehouse
   ↓
Shipping
   ↓
Delivery
   ↓
Cash Collection
   ↓
Treasury

```

The platform is designed to be extensible while avoiding unnecessary CMS functionality, external social authentication, online payment gateways, SMS integrations, and chat systems.

---



# Project Goals

The main goals are:

1. Provide a modern fashion storefront.
2. Provide a complete administrative dashboard.
3. Manage products and fashion variants.
4. Support multiple warehouses.
5. Track inventory accurately.
6. Prevent overselling through stock reservation.
7. Calculate shipping based on geographic areas.
8. Support Cash on Delivery.
9. Track COD collection through treasury.
10. Provide complete order lifecycle management.
11. Support returns and refunds.
12. Support product reviews.
13. Support coupons and promotions.
14. Provide dynamic roles and permissions.
15. Provide financial and inventory reporting.
16. Maintain a complete audit trail.
17. Keep business logic centralized in Laravel.
18. Make the application API-ready for future mobile applications.

---
## Multi-Language Support

The system must support **Arabic and English** throughout both the customer storefront and admin dashboard.

### Supported Languages

* Arabic (`ar`)
* English (`en`)

### Language Requirements

* Customer storefront supports Arabic and English.
* Admin dashboard supports Arabic and English.
* Users can switch between Arabic and English.
* The selected language should be persisted for the user/session.
* Guest users should have their selected language stored in the session/cookie.
* Authenticated users should have their preferred language stored in their account.
* Arabic must use **RTL (Right-to-Left)** layout.
* English must use **LTR (Left-to-Right)** layout.
* UI labels, buttons, validation messages, notifications, emails, and system messages must support both languages.
* Product and category content should support Arabic and English.
* SEO metadata should support Arabic and English.
* URLs/slugs should support localized SEO-friendly URLs where required.
* use spatie/laravel-translatable for database like products, categories, etc...

### Language Direction

```text
Arabic   → RTL
English  → LTR
```

The application must dynamically switch the document direction based on the active locale.

```text
Arabic:
<html lang="ar" dir="rtl">

English:
<html lang="en" dir="ltr">
```

---

## Bilingual Product & Catalog Content

The following customer-facing content must support both Arabic and English:

* Product name
* Product description
* Product short description
* Category name
* Category description
* Collection name
* Collection description
* Attribute name
* Attribute value
* Product variant display names
* SEO title
* SEO description
* Breadcrumb labels where applicable

---

# Technology Stack

| Layer          | Technology                   |
| -------------- | ---------------------------- |
| Backend        | Laravel12                    |
| PHP            | PHP 8.3+                     |
| Database       | MySQL 8+                     |
| Frontend       | React                        |
| Language       | TypeScript                   |
| SPA            | Inertia.js                   |
| CSS            | Tailwind CSS                 |
| Build Tool     | Vite                         |
| Authentication | Laravel Authentication       |
| Authorization  | Spatie Laravel Permission    |
| Media          | Spatie Laravel Media Library |
| Cache          | Redis                        |
| Queue          | Redis                        |
| Search         | Laravel Scout                |
| Scheduler      | Laravel Scheduler            |
| Payment        | Cash on Delivery             |
| Shipping       | Area-based                   |
| Storage        | Laravel Filesystem           |

---

# Architecture

```text
┌─────────────────────────────────────────────────────┐
│                  Laravel Application                │
│                                                     │
│  ┌──────────────────┐      ┌─────────────────────┐ │
│  │ Customer Store   │      │ Admin Dashboard     │ │
│  │                  │      │                     │ │
│  │ React            │      │ React               │ │
│  │ TypeScript       │      │ TypeScript           │ │
│  │ Inertia.js       │      │ Inertia.js           │ │
│  │ Tailwind CSS     │      │ Tailwind CSS         │ │
│  └────────┬─────────┘      └──────────┬──────────┘ │
│           │                           │            │
│           └─────────────┬─────────────┘            │
│                         │                          │
│                  Laravel Backend                   │
│                         │                          │
│       ┌─────────────────┼─────────────────┐       │
│       │                 │                 │       │
│    Catalog            Orders          Inventory    │
│       │                 │                 │       │
│   Products            COD           Warehouses     │
│   Variants           Returns         Transfers     │
│   Categories         Reviews         Movements     │
│       │                 │                 │       │
│       └─────────────────┼─────────────────┘       │
│                         │                          │
│                  Treasury / Finance                │
└─────────────────────────┼─────────────────────────┘
                          │
                   ┌──────┴──────┐
                   │             │
                 MySQL         Redis

```
---

# Core Modules

The application contains the following modules:

```text
1. Authentication
2. Users
3. Roles & Permissions
4. Categories
5. Products
6. Product Variants
7. Attributes
8. Collections
9. Media
10. Inventory
11. Warehouses
12. Stock Transfers
13. Customers
14. Addresses
15. Countries
16. Governorates
17. Cities
18. Areas
19. Shipping Rates
20. Cart
21. Wishlist
22. Checkout
23. Orders
24. COD Payments
25. Returns
26. Refunds
27. Reviews
28. Coupons
29. Promotions
30. Treasury
31. Treasury Transactions
32. Treasury Transfers
33. Expenses
34. Notifications
35. Reports
36. SEO
37. Settings
38. Audit Logs

```

---

# Business Rules

## Product

- Products do not have brands.
- Products can have multiple variants.
- Variants can represent size/color combinations.
- Each variant has its own SKU.
- Stock belongs to variants.
- Inventory is tracked per warehouse.
- Products can belong to multiple collections.
- Products belong to categories.


## Authentication

The system supports:

- Email/password registration.
- Email/password login.
- Password reset.

The system does not support:

- Google login.
- Apple login.
- Facebook login.
- OTP login.


## Payment

Only:

```text
Cash on Delivery

```

is supported.

No online payment gateway is required.

## Tax

The system does not calculate or store sales tax.

Order total:

```text
Subtotal
- Discount
+ Shipping
----------------
Grand Total

```


## Shipping

Shipping is calculated using:

```text
Country
    ↓
Governorate
    ↓
City
    ↓
Area
    ↓
Shipping Rate

```

The frontend cannot control the final shipping price.

The server calculates it.

## Notifications

Supported:

- Email.
- In-app notifications.

Not supported:

- SMS.
- WhatsApp.
- Live chat.
- AI assistant.



## CMS

There is no generic CMS or page builder.

The system only manages e-commerce-specific configuration.

---



# Customer Features



## Homepage

- Hero banners.
- Promotional banners.
- Featured products.
- New arrivals.
- Best sellers.
- Sale products.
- Collections.
- Categories.
- Trending products.
- Recently viewed products.



## Product Listing

- Pagination.
- Search.
- Category filter.
- Price filter.
- Color filter.
- Size filter.
- Material filter.
- Availability filter.
- Discount filter.
- Collection filter.
- Rating filter.

Sorting:

```text
Newest
Price Low → High
Price High → Low
Best Selling
Top Rated
Featured

```

---



# Product Details

The product page contains:

- Product gallery.
- Product name.
- SKU.
- Rating.
- Review count.
- Price.
- Sale price.
- Discount.
- Color.
- Size.
- Size guide.
- Stock availability.
- Quantity selector.
- Add to cart.
- Buy now.
- Wishlist.
- Share.
- Description.
- Specifications.
- Shipping information.
- Return information.
- Related products.
- Recommended products.
- Recently viewed products.

The product page does **not** contain:

- Product zoom.
- Brand information.

---



# Customer Authentication



## Registration

```text
Name
Email
Phone
Password
Password Confirmation

```



## Login

```text
Email
Password

```



## Account

```text
Profile
Addresses
Orders
Wishlist
Reviews
Notifications
Recently Viewed
Change Password
Logout

```

---



# Product Management



## Product

```text
id
category_id
name
slug
sku
description
short_description
price
sale_price
cost_price
status
is_featured
is_new
is_on_sale
weight
sort_order
meta_title
meta_description
created_at
updated_at
deleted_at

```



## Product Variant

```text
id
product_id
sku
barcode
price
sale_price
cost_price
weight_min
weight_max
status
created_at
updated_at

```

Example:

```text
T-Shirt
├── Black / S
│ └── Recommended Weight: 70 KG → 85 KG
├── Black / M
│ └── Recommended Weight: 85 KG → 100 KG
├── Black / L
│ └── Recommended Weight: 85 KG → 100 KG
├── White / S
│ └── Recommended Weight: 85 KG → 100 KG
├── White / M
│ └── Recommended Weight: 85 KG → 100 KG
└── White / L
  └── Recommended Weight: 100 KG → 130 KG

```

---



# Attributes

Attributes are dynamic.

Examples:

```text
Color
Size
Material
Fit
Style

```

Tables:

```text
attributes
attribute_values
product_attribute_values
variant_attribute_values

```

The administrator can create additional attributes without changing the database schema.

---



# Categories

Categories support unlimited nesting.

Example:

```text
Men
├── Clothing
│   ├── T-Shirts
│   ├── Shirts
│   ├── Jeans
│   └── Jackets
└── Shoes

Women
├── Clothing
│   ├── Dresses
│   ├── Tops
│   ├── Jeans
│   └── Jackets
└── Shoes

Accessories
├── Bags
├── Belts
└── Sunglasses

```

---



# Collections

Collections are independent from categories.

Examples:

```text
New Arrivals
Summer Collection
Winter Collection
Sale
Best Sellers
Featured

```

Tables:

```text
collections
collection_product

```

Relationship:

```text
Collection
    ↕
Products

```

---



# Inventory Management

Inventory is managed at variant level.

```text
Product
   ↓
Product Variant
   ↓
Warehouse
   ↓
Inventory

```

Example:

```text
Black T-Shirt / M

Main Warehouse       100
Alexandria Warehouse  35
------------------------
Total                135

```

---



# Warehouse Management

The system supports multiple warehouses.

Example:

```text
Main Warehouse
Alexandria Warehouse
6 October Warehouse

```



## Warehouse fields

```text
id
name
code
manager_id
phone
country_id
governorate_id
city_id
area_id
address
status
created_at
updated_at

```

---



# Warehouse Inventory

Table:

```text
warehouse_inventory

```

Fields:

```text
id
warehouse_id
product_variant_id
quantity
reserved_quantity
available_quantity
reorder_level
created_at
updated_at

```

Rule:

```text
available_quantity =
quantity - reserved_quantity

```

A unique constraint should exist on:

```text
warehouse_id
product_variant_id

```

---



# Inventory Movements

Every stock modification must be traceable.

Table:

```text
inventory_movements

```

Fields:

```text
id
warehouse_id
product_variant_id
type
quantity
reference_type
reference_id
before_quantity
after_quantity
created_by
notes
created_at

```

Movement types:

```text
purchase
sale
return
adjustment
damaged
lost
transfer_in
transfer_out
reservation
release

```

---



# Stock Reservation

When an order is created:

```text
Stock       = 10
Reserved    = 0
Available   = 10

```

Customer orders 2:

```text
Stock       = 10
Reserved    = 2
Available   = 8

```

When the order is fulfilled:

```text
Stock       = 8
Reserved    = 0
Available   = 8

```

When the order is cancelled:

```text
Stock       = 10
Reserved    = 0
Available   = 10

```

---



# Warehouse Transfers

Tables:

```text
stock_transfers
stock_transfer_items

```

Workflow:

```text
Draft
 ↓
Requested
 ↓
Approved
 ↓
In Transit
 ↓
Received
 ↓
Completed

```

Example:

```text
Main Warehouse
       │
       │ 20 units
       ↓
Alexandria Warehouse

```

---



# Shipping

Shipping hierarchy:

```text
Country
    ↓
Governorate
    ↓
City
    ↓
Area
    ↓
Shipping Rate

```

Tables:

```text
countries
governorates
cities
areas
shipping_rates

```



## Shipping Rate

```text
id
area_id
price
free_shipping_threshold
is_active
created_at
updated_at

```

---



# Cart

The cart supports:

- Guest users.
- Authenticated users.
- Add item.
- Remove item.
- Update quantity.
- Variant selection.
- Coupon.
- Stock validation.
- Shipping calculation.

Tables:

```text
carts
cart_items

```



## Cart Total

```text
Subtotal
- Discount
+ Shipping
----------------
Grand Total

```

There is no tax.

---



# Guest Cart

```text
Guest Cart
    ↓
Login
    ↓
Merge Guest Cart
    ↓
Customer Cart

```

Duplicate items should be merged safely.

---



# Wishlist

Tables:

```text
wishlists
wishlist_items

```

Features:

- Add product.
- Remove product.
- Move to cart.
- Display stock.
- Display current price.

---



# Checkout

Checkout flow:

```text
Cart
 ↓
Customer Information
 ↓
Shipping Address
 ↓
Shipping Area
 ↓
Shipping Rate
 ↓
Order Review
 ↓
Cash on Delivery
 ↓
Place Order

```

---



# Checkout Business Logic

Order creation must:

1. Validate cart.
2. Validate products.
3. Validate variants.
4. Validate inventory.
5. Validate quantities.
6. Validate coupon.
7. Validate address.
8. Calculate subtotal.
9. Calculate discount.
10. Calculate shipping.
11. Calculate grand total.
12. Create order.
13. Create order items.
14. Reserve inventory.
15. Create COD payment.
16. Create status history.
17. Send notifications.
18. Commit the database transaction.

All financial calculations happen on the server.

---



# Orders



## `orders`

```text
id
order_number
user_id
status
payment_status
shipping_status

subtotal
discount_amount
shipping_amount
grand_total

coupon_id

shipping_first_name
shipping_last_name
shipping_phone
shipping_country
shipping_governorate
shipping_city
shipping_area
shipping_address
shipping_building
shipping_apartment
shipping_floor
shipping_landmark
shipping_postal_code
shipping_notes

notes

placed_at
confirmed_at
shipped_at
delivered_at
cancelled_at

created_at
updated_at

```

Shipping information is stored as a snapshot.

Changing a customer's address later must not modify historical orders.

---



# Order Items



## `order_items`

```text
id
order_id
product_id
product_variant_id
product_name
variant_name
sku
quantity
unit_price
discount_amount
total
created_at
updated_at

```

Product information is partially snapshotted so historical orders remain accurate.

---



# Order Status Workflow

Primary workflow:

```text
Pending
   ↓
Confirmed
   ↓
Processing
   ↓
Packed
   ↓
Shipped
   ↓
Out For Delivery
   ↓
Delivered

```

Cancellation:

```text
Pending
   ↓
Cancelled

```

Delivery failure:

```text
Shipped
   ↓
Delivery Failed
   ↓
Returned

```

Return workflow:

```text
Delivered
   ↓
Return Requested
   ↓
Return Approved
   ↓
Return Received
   ↓
Return Inspected
   ↓
Refunded

```

---



# Order Status History

Table:

```text
order_status_history

```

Fields:

```text
id
order_id
from_status
to_status
changed_by
reason
notes
created_at

```

Every status change must be recorded.

---



# Cash on Delivery

Only COD is supported.

Table:

```text
payments

```

Fields:

```text
id
order_id
method
status
amount
collected_at
notes
created_at
updated_at

```

Method:

```text
cod

```

Payment statuses:

```text
pending
collected
not_collected
refunded

```

---



# COD + Treasury

Creating an order does not automatically mean money has entered the treasury.

Example:

```text
Order Created
     ↓
COD
     ↓
Delivered
     ↓
Cash Collected
     ↓
Treasury Transaction

```

Example:

```text
Order #10025
Total: 1,500 EGP
Payment: COD
Status: Delivered
Payment: Pending

```

After collection:

```text
Payment: Collected

Treasury:
+1,500 EGP

```

---



# Returns & Refunds

Customers can request returns for eligible orders.

## `returns`

```text
id
return_number
order_id
user_id
status
reason
customer_notes
admin_notes
requested_at
approved_at
received_at
completed_at

```



## `return_items`

```text
id
return_id
order_item_id
quantity
reason
condition

```

Return statuses:

```text
requested
approved
rejected
received
inspected
refunded
completed

```

---



# Reviews

Customers can review purchased products.

## `reviews`

```text
id
user_id
product_id
order_id
order_item_id
rating
title
comment
status
created_at
updated_at

```

Statuses:

```text
pending
approved
rejected

```

Reviews can optionally contain images.

---



# Coupons



## `coupons`

```text
id
code
type
value
minimum_order_amount
maximum_discount
usage_limit
usage_limit_per_user
starts_at
expires_at
is_active

```

Types:

```text
percentage
fixed

```

Coupons may target:

- Products.
- Categories.
- Collections.
- Customers.

---



# Promotions

Promotions are broader than coupons.

Examples:

```text
20% Summer Sale
Buy 2 Get 1
Selected Products Sale
Category Discount

```

Tables:

```text
promotions
promotion_products
promotion_categories
promotion_collections

```

---



# Treasury

Treasury is a core financial module.

A treasury represents a place where money is held.

Examples:

```text
Main Cash
Bank Account
Wallet

```



## `treasuries`

```text
id
name
code
type
opening_balance
status
created_at
updated_at

```

Types:

```text
cash
bank
wallet

```

---



# Treasury Transactions



## `treasury_transactions`

```text
id
treasury_id
type
amount
reference_type
reference_id
description
transaction_date
created_by
created_at
updated_at

```

Types:

```text
income
expense
adjustment
transfer_in
transfer_out

```

Balance:

```text
Opening Balance
+ Income
- Expenses
+ Adjustments
+ Transfers In
- Transfers Out
----------------
Current Balance

```

---



# Treasury Transfers



## `treasury_transfers`

```text
id
from_treasury_id
to_treasury_id
amount
reference
notes
created_by
created_at

```

Example:

```text
Main Cash
   - 5,000

Bank
   + 5,000

```

Both transactions must reference the same transfer.

---



# Expenses



## `expense_categories`

```text
id
name
description
is_active

```



## `expenses`

```text
id
treasury_id
expense_category_id
amount
description
expense_date
created_by
created_at
updated_at

```

Examples:

```text
Delivery
Packaging
Warehouse
Utilities
Maintenance
Other

```

---



# Roles & Permissions

Authorization should follow a **Spatie Laravel Permission-style architecture**.

Core tables:

```text
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions

```

Roles are dynamic.

Example roles:

```text
Super Admin
Store Manager
Order Manager
Inventory Manager
Warehouse Manager
Finance Manager
Customer Support

```

These are examples only.

Administrators can create additional roles.

Example permission set:

```text
products.view
products.create
products.update
products.delete

orders.view
orders.update
orders.cancel

inventory.view
inventory.adjust

warehouses.view
warehouses.create
warehouses.update
warehouses.transfer

treasury.view
treasury.create
treasury.update

reports.view

```

---



# Notifications

Supported notification channels:

```text
Email
In-App

```

Email events:

```text
Welcome
Password Reset
Order Placed
Order Confirmed
Order Processing
Order Shipped
Order Delivered
Order Cancelled
Return Requested
Return Approved
Return Rejected
Refund

```

Admin notifications:

```text
New Order
Low Stock
Return Request
New Review
Stock Adjustment
Treasury Transaction

```

SMS is not included.

---



# Reports



## Sales Reports

- Daily sales.
- Weekly sales.
- Monthly sales.
- Yearly sales.
- Revenue.



## Order Reports

- Orders by status.
- Orders by area.
- Orders by warehouse.
- Cancellation rate.
- Delivery performance.



## Product Reports

- Best sellers.
- Most viewed.
- Highest revenue.
- Low stock.



## Inventory Reports

- Warehouse stock.
- Stock movements.
- Transfers.
- Inventory valuation.



## Financial Reports

- Treasury balances.
- Income.
- Expenses.
- COD collected.
- COD pending.
- Refunds.

---



# SEO

Products:

```text
slug
meta_title
meta_description
canonical_url

```

Categories:

```text
slug
meta_title
meta_description

```

The application should generate:

```text
sitemap.xml
robots.txt
Product Schema
Breadcrumb Schema
Organization Schema

```

---



# Database Architecture



## Core Tables

```text
users

roles
permissions
model_has_roles
model_has_permissions
role_has_permissions

categories
products
product_variants
product_images
attributes
attribute_values
product_attribute_values
variant_attribute_values

collections
collection_product

countries
governorates
cities
areas
shipping_rates

warehouses
warehouse_inventory
inventory_movements
stock_transfers
stock_transfer_items

carts
cart_items

wishlists
wishlist_items

addresses

orders
order_items
order_status_history

payments

returns
return_items
refunds

reviews

coupons
coupon_products
coupon_categories
coupon_collections

promotions
promotion_products
promotion_categories
promotion_collections

treasuries
treasury_transactions
treasury_transfers

expense_categories
expenses

notifications

settings
audit_logs

```

---



# Database Relationships

```text
User
 ├── Addresses
 ├── Orders
 ├── Wishlist
 ├── Reviews
 ├── Notifications
 └── Roles

Category
 └── Products

Product
 ├── Variants
 ├── Media
 ├── Attributes
 ├── Collections
 └── Reviews

ProductVariant
 ├── WarehouseInventory
 ├── OrderItems
 └── InventoryMovements

Warehouse
 ├── Inventory
 ├── Movements
 └── StockTransfers

Order
 ├── OrderItems
 ├── Payment
 ├── StatusHistory
 └── Returns

Return
 └── ReturnItems

Treasury
 ├── Transactions
 └── Transfers

Area
 └── ShippingRate

```

---



# Important Database Constraints

Unique values:

```text
products.slug
products.sku
product_variants.sku
categories.slug
coupons.code
warehouses.code
treasuries.code
orders.order_number

```

Warehouse inventory:

```text
UNIQUE(
    warehouse_id,
    product_variant_id
)

```

Wishlist:

```text
UNIQUE(
    wishlist_id,
    product_id
)

```

---



# API Architecture

The application uses Inertia for the primary frontend.

However, the business layer should remain API-ready.

API prefix:

```text
/api

```

The API should use the same services/actions as the Inertia controllers.

The API must never duplicate core business logic.

---



# API Endpoints



## Authentication

```http
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/user
PUT    /api/auth/profile
PUT    /api/auth/password
POST   /api/auth/forgot-password
POST   /api/auth/reset-password

```

---



## Categories

```http
GET    /api/categories
GET    /api/categories/{category}

```

---



## Products

```http
GET    /api/products
GET    /api/products/{product}

```

Filters:

```text
?page=
&search=
&category=
&min_price=
&max_price=
&color=
&size=
&sort=

```

---



## Collections

```http
GET    /api/collections
GET    /api/collections/{collection}

```

---



## Attributes

```http
GET    /api/attributes

```

---



## Wishlist

```http
GET    /api/wishlist
POST   /api/wishlist
DELETE /api/wishlist/{product}
POST   /api/wishlist/{product}/move-to-cart

```

---



## Cart

```http
GET    /api/cart
POST   /api/cart/items
PUT    /api/cart/items/{item}
DELETE /api/cart/items/{item}
DELETE /api/cart
POST   /api/cart/coupon
DELETE /api/cart/coupon

```

---



## Addresses

```http
GET    /api/addresses
POST   /api/addresses
GET    /api/addresses/{address}
PUT    /api/addresses/{address}
DELETE /api/addresses/{address}
POST   /api/addresses/{address}/default

```

---



## Shipping

```http
GET    /api/shipping/countries
GET    /api/shipping/governorates
GET    /api/shipping/cities
GET    /api/shipping/areas
GET    /api/shipping/rates

```

---



## Checkout

```http
GET    /api/checkout
POST   /api/checkout/validate
POST   /api/checkout

```

---



## Orders

```http
GET    /api/orders
POST   /api/orders
GET    /api/orders/{order}
POST   /api/orders/{order}/cancel
GET    /api/orders/{order}/tracking

```

---



## Returns

```http
GET    /api/returns
POST   /api/orders/{order}/returns
GET    /api/returns/{return}

```

---



## Reviews

```http
GET    /api/products/{product}/reviews
POST   /api/products/{product}/reviews
PUT    /api/reviews/{review}
DELETE /api/reviews/{review}

```

---



## Notifications

```http
GET    /api/notifications
POST   /api/notifications/{notification}/read
POST   /api/notifications/read-all

```

---



# Customer Pages

```text
/

/products
/products/{product}

/categories
/categories/{category}

/collections
/collections/{collection}

/search

/cart

/checkout
/checkout/success

/wishlist

/account
/account/profile
/account/addresses
/account/orders
/account/orders/{order}
 /account/reviews
/account/notifications
/account/recently-viewed
/account/password

```

Authentication:

```text
/login
/register
/forgot-password
/reset-password

```

---



# Admin Pages



## Dashboard

```text
/admin/dashboard

```



## Products

```text
/admin/products
/admin/products/create
/admin/products/{product}
/admin/products/{product}/edit

```



## Categories

```text
/admin/categories
/admin/categories/create
/admin/categories/{category}/edit

```



## Attributes

```text
/admin/attributes
/admin/attributes/create
/admin/attributes/{attribute}/edit

```



## Collections

```text
/admin/collections
/admin/collections/create
/admin/collections/{collection}/edit

```

---



# Admin Inventory

```text
/admin/inventory
/admin/inventory/movements
/admin/inventory/adjustments
/admin/inventory/low-stock

```

---



# Admin Warehouses

```text
/admin/warehouses
/admin/warehouses/create
/admin/warehouses/{warehouse}
/admin/warehouses/{warehouse}/edit
/admin/warehouses/{warehouse}/inventory

```

---



# Admin Transfers

```text
/admin/stock-transfers
/admin/stock-transfers/create
/admin/stock-transfers/{transfer}

```

---



# Admin Orders

```text
/admin/orders
/admin/orders/{order}

/admin/orders/pending
/admin/orders/processing
/admin/orders/shipped
/admin/orders/delivered
/admin/orders/cancelled

```

---



# Admin Customers

```text
/admin/customers
/admin/customers/{customer}
/admin/customers/{customer}/orders
/admin/customers/{customer}/addresses
/admin/customers/{customer}/reviews

```

---



# Admin Shipping

```text
/admin/shipping/countries
/admin/shipping/governorates
/admin/shipping/cities
/admin/shipping/areas
/admin/shipping/rates

```

---



# Admin Returns

```text
/admin/returns
/admin/returns/{return}

```

---



# Admin Reviews

```text
/admin/reviews
/admin/reviews/pending
/admin/reviews/approved
/admin/reviews/rejected

```

---



# Admin Coupons

```text
/admin/coupons
/admin/coupons/create
/admin/coupons/{coupon}/edit

```

---



# Admin Promotions

```text
/admin/promotions
/admin/promotions/create
/admin/promotions/{promotion}/edit

```

---



# Admin Treasury

```text
/admin/treasuries
/admin/treasuries/{treasury}

/admin/treasury/transactions
/admin/treasury/transfers

```

---



# Admin Expenses

```text
/admin/expenses
/admin/expenses/create
/admin/expenses/{expense}/edit

/admin/expense-categories

```

---



# Admin Roles

```text
/admin/roles
/admin/roles/create
/admin/roles/{role}/edit

/admin/permissions

```

---



# Admin Reports

```text
/admin/reports/sales
/admin/reports/orders
/admin/reports/inventory
/admin/reports/financial

```

---



# Admin Settings

```text
/admin/settings

```

---



# Recommended Laravel Structure

```text
app/
├── Actions/
│   ├── Cart/
│   ├── Checkout/
│   ├── Inventory/
│   ├── Orders/
│   ├── Payments/
│   ├── Returns/
│   ├── Shipping/
│   ├── Treasury/
│   └── Warehouses/
│
├── Console/
│   └── Commands/
│
├── Enums/
│   ├── OrderStatus.php
│   ├── PaymentStatus.php
│   ├── InventoryMovementType.php
│   ├── ReturnStatus.php
│   ├── TreasuryTransactionType.php
│   └── StockTransferStatus.php
│
├── Events/
│
├── Exceptions/
│
├── Http/
│   ├── Controllers/
│   │   ├── Store/
│   │   ├── Admin/
│   │   └── Api/
│   │
│   ├── Middleware/
│   │
│   └── Requests/
│       ├── Store/
│       ├── Admin/
│       └── Api/
│
├── Jobs/
│
├── Listeners/
│
├── Mail/
│
├── Models/
│
├── Notifications/
│
├── Policies/
│
├── Repositories/
│
├── Services/
│   ├── Cart/
│   ├── Checkout/
│   ├── Inventory/
│   ├── Orders/
│   ├── Shipping/
│   ├── Treasury/
│   └── Warehouse/
│
└── Support/
    ├── Money/
    ├── Search/
    └── Helpers/

```

---



# Recommended React/Inertia Structure

```text
resources/js/
│
├── Components/
│   ├── UI/
│   ├── Forms/
│   ├── Tables/
│   ├── Modals/
│   ├── Product/
│   ├── Cart/
│   ├── Checkout/
│   ├── Order/
│   ├── Inventory/
│   ├── Warehouse/
│   └── Admin/
│
├── Layouts/
│   ├── StoreLayout.tsx
│   ├── AuthLayout.tsx
│   └── AdminLayout.tsx
│
├── Pages/
│   ├── Store/
│   ├── Admin/
│   └── Auth/
│
├── Hooks/
│   ├── useCart.ts
│   ├── useWishlist.ts
│   ├── useModal.ts
│   └── usePermission.ts
│
├── Lib/
│   ├── axios.ts
│   ├── utils.ts
│   └── formatting.ts
│
├── Types/
│   ├── product.ts
│   ├── order.ts
│   ├── customer.ts
│   ├── inventory.ts
│   ├── warehouse.ts
│   └── treasury.ts
│
├── Stores/
│
├── App.tsx
└── app.css

```

---



# Customer Pages Structure

```text
resources/js/Pages/Store/

├── Home.tsx
│
├── Categories/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Products/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Collections/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Cart/
│   └── Index.tsx
│
├── Checkout/
│   ├── Index.tsx
│   └── Success.tsx
│
├── Wishlist/
│   └── Index.tsx
│
├── Account/
│   ├── Dashboard.tsx
│   ├── Profile.tsx
│   ├── Addresses.tsx
│   ├── Orders.tsx
│   ├── OrderShow.tsx
│   ├── Reviews.tsx
│   ├── Notifications.tsx
│   └── RecentlyViewed.tsx
│
└── Search.tsx

```

---



# Admin Pages Structure

```text
resources/js/Pages/Admin/

├── Dashboard/
│   └── Index.tsx
│
├── Products/
│   ├── Index.tsx
│   ├── Create.tsx
│   ├── Edit.tsx
│   └── Show.tsx
│
├── Categories/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Attributes/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Collections/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Orders/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Customers/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Inventory/
│   ├── Index.tsx
│   ├── Movements.tsx
│   ├── Adjustments.tsx
│   └── LowStock.tsx
│
├── Warehouses/
│   ├── Index.tsx
│   ├── Create.tsx
│   ├── Edit.tsx
│   ├── Show.tsx
│   └── Inventory.tsx
│
├── StockTransfers/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Show.tsx
│
├── Shipping/
│   ├── Countries.tsx
│   ├── Governorates.tsx
│   ├── Cities.tsx
│   ├── Areas.tsx
│   └── Rates.tsx
│
├── Returns/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Reviews/
│   ├── Index.tsx
│   └── Show.tsx
│
├── Coupons/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Promotions/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Treasury/
│   ├── Index.tsx
│   ├── Show.tsx
│   ├── Transactions.tsx
│   └── Transfers.tsx
│
├── Expenses/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Roles/
│   ├── Index.tsx
│   ├── Create.tsx
│   └── Edit.tsx
│
├── Permissions/
│   └── Index.tsx
│
├── Reports/
│   ├── Sales.tsx
│   ├── Orders.tsx
│   ├── Inventory.tsx
│   └── Financial.tsx
│
└── Settings/
    └── Index.tsx

```

---



# Routes

Recommended route separation:

```text
routes/
├── web.php
├── auth.php
├── store.php
├── admin.php
└── api.php

```

Customer routes:

```text
/
/products
/products/{product}
/categories/{category}
/collections/{collection}
/search
/cart
/checkout
/wishlist
/account

```

Admin routes:

```text
/admin
/admin/products
/admin/orders
/admin/customers
/admin/inventory
/admin/warehouses
/admin/stock-transfers
/admin/shipping
/admin/treasury
/admin/expenses
/admin/reports
/admin/settings

```

---



# Controller Architecture

Controllers should remain thin.

Avoid:

```php
public function store(Request $request)
{
    // 200 lines of checkout logic
}

```

Use:

```text
Controller
    ↓
Form Request
    ↓
Action / Service
    ↓
Domain Logic
    ↓
Models
    ↓
Database

```

Example:

```text
CreateOrderAction

```

Responsibilities:

```text
Validate Cart
Validate Stock
Calculate Prices
Calculate Discounts
Calculate Shipping
Create Order
Create Order Items
Reserve Inventory
Create COD Payment
Create Status History

```

---



# Financial Rules

All financial calculations must happen on the backend.

Never trust frontend values for:

```text
Price
Discount
Shipping
Stock
Subtotal
Total

```

The backend must recalculate all values.

---



# Database Transactions

Checkout must use a database transaction.

Conceptually:

```text
BEGIN TRANSACTION

Lock Inventory

Validate Available Stock

Reserve Stock

Create Order

Create Order Items

Create COD Payment

Create Order Status History

COMMIT

```

If any operation fails:

```text
ROLLBACK

```

---



# Concurrency Protection

The system must prevent overselling.

Example:

```text
Available Stock = 1

```

Two customers attempt to buy the product simultaneously.

Expected result:

```text
Customer A → Success
Customer B → Out of Stock

```

Use appropriate database row locking and transactional inventory updates.

---



# Security

The application must implement:

- CSRF protection.
- Password hashing.
- Rate limiting.
- Authorization policies.
- Permission middleware.
- Request validation.
- Mass-assignment protection.
- SQL injection protection.
- XSS protection.
- Secure file uploads.
- Admin route protection.
- Audit logging.
- Secure sessions.
- Secure password reset.
- Signed URLs where appropriate.

---



# Audit Logs

Important administrative operations must be recorded.

Examples:

```text
Product created
Product updated
Product deleted
Product price changed
Stock adjusted
Order cancelled
Order status changed
Treasury transaction created
Expense created
Role changed
Permission changed
Warehouse transfer approved

```



## `audit_logs`

```text
id
user_id
event
subject_type
subject_id
old_values
new_values
ip_address
user_agent
created_at

```

---



# Performance

The application should use:

- Redis caching.
- Database indexes.
- Eager loading.
- Pagination.
- Query optimization.
- Queue processing.
- Image optimization.
- Lazy loading.
- HTTP caching where appropriate.

Avoid N+1 database queries.

---



# Queues

Redis queues should handle asynchronous operations.

Examples:

```text
Send Email
Send Notification
Process Media
Generate Reports
Update Search Index

```

Critical order and inventory transactions remain synchronous and transactional.

---



# Scheduled Tasks

Potential scheduled tasks:

```text
Clean expired carts
Expire coupons
Check low stock
Clean temporary files
Generate scheduled reports
Update search indexes

```



## Critical Tests

The following scenarios must have dedicated tests:

```text
Customer cannot purchase unavailable stock.

Two customers cannot purchase the final stock simultaneously.

Cancelled orders release reserved stock.

Delivered COD orders can be marked as collected.

Collected COD creates the correct treasury transaction.

Treasury transfers create balanced transactions.

Shipping price cannot be manipulated from the frontend.

Customers cannot access another customer's orders.

Users cannot access admin functionality without permission.

```

---



# Development Phases



## Phase 1 — Project Foundation

```text
Laravel setup
Inertia.js
React
TypeScript
Tailwind CSS
Authentication
Users
Roles
Permissions
Admin layout
Store layout
Settings

```

---



## Phase 2 — Catalog

```text
Categories
Products
Variants
Attributes
Media
Collections
Search
SEO

```

---



## Phase 3 — Inventory

```text
Warehouses
Warehouse Inventory
Stock Movements
Stock Reservations
Stock Adjustments
Stock Transfers
Low Stock

```

---



## Phase 4 — Storefront

```text
Homepage
Categories
Product Listing
Product Details
Search
Wishlist
Cart
Recently Viewed

```

---



## Phase 5 — Checkout

```text
Addresses
Countries
Governorates
Cities
Areas
Shipping Rates
Checkout
Cash on Delivery
Orders

```

---



## Phase 6 — Order Operations

```text
Order Workflow
Order History
Packing
Shipping
Delivery
Cancellation
COD Collection

```

---



## Phase 7 — Returns & Reviews

```text
Returns
Return Items
Refunds
Reviews
Review Moderation

```

---



## Phase 8 — Treasury

```text
Treasuries
Transactions
Expenses
Expense Categories
Treasury Transfers
COD Reconciliation
Financial Reports

```

---



## Phase 9 — Marketing

```text
Coupons
Promotions
Collections
Discount Rules

```

---



## Phase 10 — Production

```text
SEO
Notifications
Queues
Caching
Audit Logs
Reports
CI/CD
Monitoring
Backups
Security Hardening

```

---



# Recommended Claude Code Skills

The skills below are available in this Claude Code environment and map to specific parts of the plan above. Use them as the phases are executed rather than treating them as a separate step.

## Planning / Pre-build

| Skill | Use for | Where it applies |
| --- | --- | --- |
| `/design` | Mock up storefront and admin screens (layout, states, RTL/LTR variants) before wiring them to React/Inertia, so layout decisions are settled before code | Homepage, Product Listing/Details, Checkout, Account pages, Admin Dashboard — Phases 1, 4, 5 |
| `/dataviz` | Get chart types, KPI tiles, and color usage right *before* building the reporting screens, not after | Admin Dashboard, Sales/Order/Inventory/Financial Reports — Phase 8, 10 |

## Implementation

| Skill | Use for | Where it applies |
| --- | --- | --- |
| `/init` | Generate a `CLAUDE.md` once the Laravel/Inertia scaffold exists, so Claude Code consistently follows the `Actions/Services/Enums` conventions defined in [Recommended Laravel Structure](#recommended-laravel-structure) instead of re-deriving them each session | Phase 1 — Project Foundation, then kept current as modules are added |
| `/run` | Launch the app and click through a flow to confirm it actually works end-to-end, not just that tests pass | Checkout, COD collection, stock reservation, treasury flows — especially Phases 5 and 6 |

## Quality / Review

| Skill | Use for | Where it applies |
| --- | --- | --- |
| `/code-review` | Catch correctness bugs before merging, particularly in the financial and concurrency-sensitive logic called out under [Concurrency Protection](#concurrency-protection) and [Critical Tests](#critical-tests) | Checkout, Stock Reservation, Treasury, COD, Refunds — Phases 3, 5, 6, 8 |
| `/code-review ultra` | Deep multi-agent review across the whole branch/PR before a phase is considered done | End of each phase, and a full pass before Phase 10 goes live |
| `/security-review` | Verify pending changes against the [Security](#security) checklist (CSRF, authorization, mass assignment, audit logging, etc.) | Authentication, Roles & Permissions, Admin routes, Payments/COD — Phase 1 and again before Phase 10 |
| `/simplify` | Cleanup pass for reuse/efficiency once a feature works, without hunting for bugs | Any phase, especially just before Phase 10 hardening |

## Explicitly not needed

The spec excludes an AI shopping assistant, live chat, and any AI-generated content, so `claude-api` (Anthropic API/SDK guidance) isn't relevant to building this platform — it would only apply if a future phase reintroduces an AI feature (see [Future Enhancements](#future-enhancements)).

---



# Future Enhancements

The following features are intentionally excluded from the initial scope but can be added later:

```text
Online Payment Gateways
Apple Pay
Google Pay
Mobile Application
Multi-Currency
Multi-Language
Multi-Country
Multi-Store
Multi-Vendor
Loyalty Points
Gift Cards
Store Credit
Referral System
Affiliate System
Product Recommendations
AI Shopping Assistant
WhatsApp
Live Chat
SMS
Advanced Search Engine
Abandoned Cart Automation

```

---



# Explicitly Excluded Features

The initial project does **not** include:

```text
Brand Management
Product Zoom
Google Authentication
Apple Authentication
Facebook Authentication
OTP Authentication
Tax
Online Payment Gateways
SMS Notifications
CMS
Page Builder
Live Chat
WhatsApp
AI Assistant

```

---



# Definition of Done

A module is considered complete only when:

- Database migration exists.
- Model exists.
- Relationships are implemented.
- Validation exists.
- Authorization exists.
- Business logic is separated into actions/services where appropriate.
- Admin interface exists where required.
- Customer interface exists where required.
- Loading states exist.
- Empty states exist.
- Error states exist.
- Success feedback exists.
- Pagination exists where required.
- Search/filtering exists where required.
- Tests exist for critical functionality.
- Audit logging exists for sensitive operations.
- Permission checks are enforced server-side.
- No business-critical values are trusted from the frontend.

---



# Production Architecture

The final system should follow this model:

```text
                         ┌──────────────────────┐
                         │    CUSTOMER STORE    │
                         │                      │
                         │ React + Inertia      │
                         │ TypeScript           │
                         │ Tailwind CSS         │
                         └──────────┬───────────┘
                                    │
                                    │
                         ┌──────────▼───────────┐
                         │   LARAVEL BACKEND    │
                         │                      │
                         │ Authentication       │
                         │ Authorization        │
                         │ Business Logic       │
                         │ Orders               │
                         │ Inventory            │
                         │ Shipping             │
                         │ Treasury             │
                         └──────────┬───────────┘
                                    │
                         ┌──────────▼───────────┐
                         │     ADMIN PANEL      │
                         │                      │
                         │ React + Inertia      │
                         │ TypeScript           │
                         │ Tailwind CSS         │
                         └──────────────────────┘

                                    │
                  ┌─────────────────┼─────────────────┐
                  │                 │                 │
             ┌────▼────┐       ┌────▼────┐       ┌────▼────┐
             │  MySQL  │       │  Redis  │       │ Storage │
             └─────────┘       └─────────┘       └─────────┘

```

---



# Final Product Vision

The completed platform should operate as a unified system:

```text
                         CUSTOMER
                            │
                            ▼
                      ONLINE STORE
                            │
             ┌──────────────┼──────────────┐
             │              │              │
          Products        Cart          Wishlist
             │              │
             └──────────────┤
                            ▼
                         CHECKOUT
                            │
                    Area-Based Shipping
                            │
                            ▼
                    CASH ON DELIVERY
                            │
                            ▼
                          ORDER
                            │
                  ┌─────────┴─────────┐
                  │                   │
             INVENTORY             SHIPPING
                  │                   │
             WAREHOUSE             DELIVERY
                  │                   │
             STOCK MOVEMENT           │
                  │                   │
                  └─────────┬─────────┘
                            ▼
                     CASH COLLECTION
                            │
                            ▼
                        TREASURY
                            │
                   ┌────────┴────────┐
                   │                 │
                Income            Expenses
                   │                 │
                   └────────┬────────┘
                            ▼
                         REPORTS

```

The core principle is:

> **Laravel owns the business rules. Inertia/React renders the interfaces. Inventory, orders, shipping, COD, warehouses, and treasury all operate from the same centralized business logic.**

This keeps the platform maintainable, testable, secure, and ready for future mobile/API clients without rebuilding the core system.