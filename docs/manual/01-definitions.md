# 1. Words used in this guide

You do not need to remember these. Come back to this page whenever a word is unclear.

## The parts of the system

| Word | What it means |
|---|---|
| **Server** | The computer that keeps all the data (products, prices, stock, sales, customers, accounts) and runs the software. Every other device talks to it. It can be a PC in your shop, a computer in your office, or a rented computer on the internet (*cloud*). |
| **Back office** | The management screens, opened in a web browser at `http://<server>/admin`. Managers use it to set up products and prices, receive stock, see reports, run payroll and so on. |
| **Till** (POS, point of sale) | The selling screen used by cashiers. It runs in the Chrome or Edge browser at `http://<server>/pos/` on a PC, laptop or tablet, and keeps working when the server or the internet is down. |
| **Device** | Any till, handheld scanner, kitchen screen or self-service screen. Each one is *registered* in the back office and *enrolled* once with a one-time code, so only your own devices can sell. |
| **Enrolment code** | A one-time code (like `ABCD-EFGH-JKMN`) that the back office shows when you register a device. You type it on the device once. |
| **Handheld** | A phone or rugged scanner used in the warehouse to receive goods, put them away and count stock. |
| **Kitchen screen** (KDS) | A screen in the kitchen or bar that shows the orders to prepare (Restaurant module). |
| **Online store / channel** | A website that sells your products (the built-in shop, WooCommerce or Shopify), or a delivery app. It receives prices and stock automatically and sends its orders back. |

## Your business in the system

| Word | What it means |
|---|---|
| **Company** | Your business. One installation normally holds one company. |
| **Legal entity** | The registered company, with its TIN and VAT number, printed on VAT invoices. |
| **Branch** | One shop, restaurant, pharmacy or warehouse location. One branch is the **head office** (HQ). |
| **Warehouse** | A place where stock is kept. Every branch has a main warehouse; you can add more (store room, cold room, van). |
| **Till (in the set-up)** | A selling point at a branch, for example *Till 1*. A device is assigned to one till. |
| **Branch code** | 1–6 letters or digits, such as `HQ` or `KSI`. It appears in receipt numbers and cannot be changed later. |
| **User** | A person who signs in. Back-office users sign in with email, password and a second factor. Cashiers sign in on the till with a **username and PIN**. |
| **Role** | A set of permissions, such as *Cashier*, *Supervisor*, *Branch Manager* or *Accountant*. You give people roles. |
| **Scope** | Where a role applies: the whole company, one branch, one warehouse or one till. A cashier with a till scope can sell only on that till. |
| **Security level** | A ceiling (L1 to L6) on what a person can ever be given. It stops anyone from handing out more power than they have. |
| **Two-factor sign-in** (2FA) | A second proof of identity after the password: a 6-digit code from an authenticator app on your phone, or a passkey. Required for managers. |
| **Passkey** | A sign-in using your device's fingerprint, face or PIN instead of a code. Optional. |

## How the software is organised

| Word | What it means |
|---|---|
| **Core** | The part every business has: company, branches, users, products, prices, tax, stock, tills, customers, purchasing, sales, finance and HR (if licensed). |
| **Module** | An extra package for a type of business, switched on **per branch**: Grocery, Pharmacy, Restaurant, Electronics, Fashion, Services, Online Storefront, and more later. A branch can use several. |
| **Licence** | The key from SES Labs that allows the software to run. It sets how many branches, devices and users you may have and which modules and features (Finance, HR & payroll) you bought. |
| **Installation code** | A code starting with `SYNREQ-` that your server shows on the Licence page. You send it to SES Labs to receive your licence key (starting with `SYN1-`). |
| **Read-only** | What happens when a licence expires or is withdrawn: you can still see everything, but nothing can be changed or sold. Nothing is deleted. |

## Working offline and with several sites

| Word | What it means |
|---|---|
| **Offline** | Without a connection to the server. Tills keep selling offline and send their sales later. |
| **Offline sign-in** (grant) | Permission for a cashier to sign in on a till without the server. It is given when they sign in on that till while it is online, and lasts 72 hours by default. |
| **Queue** | The list of sales a till made offline that are waiting to be sent. They are sent automatically. |
| **Review queue** | A list in the back office of things a manager should check, such as a sale made offline at an old price. |
| **Node** | One server running the software. A company can have several. |
| **HQ server** (head office) | The main server. It owns the master data (products, prices, users) and receives everything from the branches. |
| **Branch server** | An optional server **at** a branch. The branch keeps working fully (tills, back office, stock) when the internet to head office is down, and exchanges changes with HQ when it returns. |
| **Standalone** | A single server for a single site, with no other servers. |
| **Sync** | Sending changes between servers (branch ⇄ HQ) and from tills to their server. It is automatic. |

## Technical words you will meet during installation

| Word | What it means |
|---|---|
| **Operating system** | The basic software of a computer: Windows 10/11, or Ubuntu Linux on servers. |
| **PHP** | The programming language the server is written in. You install it so the server can run. |
| **Composer** | A tool that downloads the PHP building blocks (libraries) the server needs. |
| **Node.js / npm** | A tool used once during installation to build the till app. |
| **PostgreSQL** | The database: the program that stores all your data safely. |
| **Database** | The organised store of all your data inside PostgreSQL. It is called `erp`. |
| **`.env` file** | A small settings file on the server holding passwords and keys. It must stay private and be backed up. |
| **APP_KEY / ERP_AUDIT_KEY** | Secret keys in `.env`. APP_KEY protects sessions and stored secrets; ERP_AUDIT_KEY seals the audit log. Lose them and some data cannot be read or verified, so **back them up**. |
| **Scheduler** | A background timer that runs the software's routine jobs every minute: sending changes to online stores, syncing branches, posting to the accounts, reminders. It **must** run all the time. |
| **Live updates** (Reverb) | An optional service that pushes price and stock changes to tills and screens instantly. Without it, tills check every 60 seconds. |
| **IP address** | The number of a computer on the network, such as `192.168.1.10`. Tills use it to find the server. |
| **LAN** | Your shop's local network (cables or Wi-Fi through your router). |
| **Port** | A numbered "door" on a computer. This guide uses port `8010` for the server on Windows and the standard web ports 80/443 on Linux. |
| **HTTPS / SSL certificate** | Encrypted web connection (the padlock in the browser). Needed when tills reach the server over the internet, and for some browser features. |
| **Domain** | A web address you own, such as `erp.yourshop.com`. Needed for a cloud server. |
| **Firewall** | Protection that blocks unwanted network connections. You open only the ports you need. |
| **Service** | A program that starts automatically with the computer and runs in the background. |
| **Backup** | A copy of the database and the `.env` file kept somewhere else, so you can recover after a theft, fire or disk failure. |
