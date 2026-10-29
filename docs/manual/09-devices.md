# 9. Setting up tills and other devices

## 9.1 How devices are set up

Every till, handheld and kitchen screen is set up the same way:

1. **Register** it in the back office: **System → Devices → New device**. You get a **one-time enrolment code** (valid 24 hours by default).
2. On the device, open the till app address in Chrome or Edge and **enter the code**.
3. People **sign in** on it (cashiers with username and PIN; kitchen screens need no sign-in).

**Why:** only devices you registered can sell or see your data. Each device creates its own security key when it is enrolled, so a copied browser cannot pretend to be your till. A lost device is switched off with one click (**Revoke**).

### Register a device

**System → Devices → New device**:

| Field | What to enter |
|---|---|
| Branch | Where the device is used |
| Till (for POS devices) | The till it sells on (e.g. *Till 1*). Each selling device needs a till. |
| Name | Something you will recognise: *Front counter PC*, *Waiter tablet 2*, *Handheld 1* |
| Kind | **POS till** (tills and waiter tablets) or **Handheld scanner** (warehouse). **Kitchen display** (kitchen or bar screen) is offered only at a branch running the Restaurant module. |

![Registering a device](img/device-new.png)

Click **Create**. The code is shown **once**: write it down or keep the page open while you set up the device. If it expires, use **New code** on the device's row.

## 9.2 The till app address

| Setup | Address to open on the device |
|---|---|
| A (on the server PC itself) | `https://localhost/pos/` |
| B (Windows server on the shop network) | `https://<server-address>/pos/` (e.g. `https://192.168.1.10/pos/`). Trust the server first (4.9). |
| B/C (Ubuntu server on the network, no domain) | `https://<server-address>/pos/`. Trust the server first (4.9). |
| D (cloud, with HTTPS) | `https://<your-domain>/pos/` |
| E (branch server) | the **branch server's** address, as in B |

## 9.3 Trust the server's address

Browsers allow a web app to use its **security functions** (the till's own signing key, the offline PIN check), to **work offline** and to **install as an app** only when it comes from a secure address: `https://…` or `localhost`. **This is required**: without it the till cannot even enrol.

- **A server without an internet name** (Windows or Ubuntu): it is reached over `https` with its own certificate. Each device trusts it once, as in **4.9**. Nothing else is needed.
- **Setup D and servers with an internet name:** nothing to do. The certificate is a public one.
- **Only for a server you set up by hand without https** (not recommended): each till, tablet, handheld and kitchen screen must be told once that the plain `http://` address is trusted:

1. In Chrome, open `chrome://flags/#unsafely-treat-insecure-origin-as-secure` (in Edge: `edge://flags/#unsafely-treat-insecure-origin-as-secure`).
2. In the box, type the server address **without** `/pos/`, e.g. `http://192.168.1.10`.
3. Set the option to **Enabled** and click **Relaunch**.

**Why:** the till proves it is your device by signing with a key it creates and keeps itself, and checks PINs offline; browsers only offer these functions to trusted addresses.

**Check:** the till app shows *Set up this device* and enrolling with the code works.

**Note:** This is safe on your own shop network. Never use plain `http://` over the internet; use HTTPS (Chapter 5).

## 9.4 Till PC (Windows)

1. Install **Google Chrome** or use **Microsoft Edge**. Set the PC's clock to update automatically (as in 4.1, step 2).
2. Do 9.3 if your setup needs it.
3. Open the till address (9.2).
4. **Install it as an app:** in Chrome, menu **⋮ → Cast, save and share → Install page as app…**; in Edge, menu **… → Apps → Install this site as an app**. A *POS* icon appears on the desktop and Start menu; use it from now on.
   **Why:** the app opens full-screen without the address bar and starts even when the server is off.
5. Enter the **enrolment code** and click **Enrol this device**:

   ![The till asks for its enrolment code the first time](img/till-enrol.png)

6. A cashier signs in with **username and PIN**, counts the drawer and **opens the shift**.

**Check:** sell an item and print the receipt. Then pull out the network cable (or switch off Wi-Fi): the top shows *working offline*, and you can still sell. Plug it back in: the yellow banner disappears as the sale is sent.

## 9.5 Tablet (Android)

1. Update Chrome from the Play Store. Do 9.3 if needed (Chrome on Android has the same flags page).
2. Open the till address, then Chrome menu **⋮ → Add to Home screen → Install**.
3. Enrol and sign in as on a PC.

**Tip:** waiter tablets in a restaurant are registered as **POS till** devices on a till of their branch.

**Van reps:** register the rep's Android phone as a **POS till** on the **van's till** (each van has one, created with the van). Allow the app to use the phone's **location** when asked, so visits record where they happened. On the phone the till shows **Route (F3)**, **Take order** and **Empties returned**. Install it as an app (step 2) so it opens without signal.

## 9.6 Choosing light or dark

Every till, handheld and kitchen screen has a **Theme** switch at the bottom left: **System** (follows the device's own setting), **Light** or **Dark**. The choice is remembered on that device. Dark is easier on the eyes in a dim bar or kitchen and saves battery on phones.

## 9.7 Receipt printer and cash drawer

1. Install the printer's **Windows (or Android) driver** from the manufacturer, and print a Windows test page.
2. In the printer's **Printing preferences**, choose paper **80 mm** (or 72 mm) receipt roll. For a cash drawer connected to the printer, switch on the driver's **cash drawer / open drawer before printing** option.
3. Make it the **default printer** on that till PC.
4. The first time you click **Print receipt**, in the browser's print window choose the printer, set **Margins: None**, **Scale: 100**, and untick **Headers and footers**. The browser remembers it.
5. **Print without the print window** (recommended): edit the desktop shortcut of the till app (right-click → **Properties**), and at the end of **Target** add a space and `--kiosk-printing`. Start the till from that shortcut.

**Why:** `--kiosk-printing` sends receipts straight to the default printer, which is faster and avoids mistakes at busy times.

**Check:** a sale prints a full-width receipt and the drawer opens.

### The desktop till app (silent printing, cash drawer, scale)

For a Windows till PC with a receipt printer, a cash drawer or a weighing scale, install **SES Labs Till** (`SES Labs Till_<version>_x64-setup.exe`, from SyncLytics) instead of using the browser:

1. Install the receipt printer's Windows driver (or note the IP address of a network receipt printer).
2. Trust the server on this PC (4.9). Then run the SES Labs Till setup. On first start it asks for the **server address**: exactly what you type in a browser to open the back office, without `/admin`. For a cloud server that is its name, e.g. `https://erp.clientdomain.com`, **never its IP address** (the security certificate is for the name, so the IP is refused). For a server in the shop it is the address used in 4.9, e.g. `https://192.168.1.10`.
3. **Receipt printer:** choose it from the list (or type `tcp://<printer-ip>:9100` for a network printer); tick **Open the cash drawer** if the drawer is plugged into the printer; **Print a test receipt**.
4. **Scale:** choose its COM port and speed (usually 9600), then **Read the scale** with something on it.
5. **Save and open the till.** The app first checks that the server answers. If it does not, it says so and stays on this page: correct the address and save again. (**Use this address anyway** keeps an address that cannot be reached yet, for example when the server is not switched on.) Then enrol and sign in as with any till (9.1).

The app also tells the server **which computer it is** (its name, network card and address on the shop network), so the audit log and **System → Devices** (*Last used from*) show which till did what (12.1). **Open the back office** on its set-up page opens the back office in the app, so changes made there are recorded with the computer's details too.

Receipts then **print by themselves** the moment the sale is done, without a print window, the drawer opens after cash sales, and items sold by weight show a **Weigh** button. **Printer and scale set-up** on the receipt screen opens these settings again.

**Till settings (desktop till 0.28 or later):** the **⚙ Settings** button at the top of the till. Anyone can look and press the test buttons; **Save** needs a manager's username and PIN (permission *Change a till's printer, drawer, scale and screen settings*; general manager, branch manager, IT Admin), and the change is recorded in the audit log against that till.
- **Receipt printer:** the receipt printer is picked for you (e.g. *EPSON TM-T20III Receipt*). Printers that only save files (*Microsoft Print to PDF*, *XPS*, *OneNote*, fax) cannot be chosen. **Why:** a receipt sent to them makes Windows open a "Save as" window after every sale. **Print a test receipt** prints a ruler across the paper: check it fits and the paper is cut.
- **Paper** 80 mm (48 characters a line) or 58 mm (32); **copies** of each receipt (2 when the shop keeps one); **company logo** on or off.
- **Dosage labels** on their own label printer, or on the receipt printer.
- **Cash drawer:** open after cash sales; **Open the drawer (test)** needs a manager's PIN, like opening it without a sale.
- **Weighing scale:** port and speed, and **Read the scale**.
- **Barcode scanner:** scan into the box to see exactly what the scanner sends; a **beep** after each scan (high: found, low: not found).
- **Screen:** light, dark or like the computer; text size normal, larger or largest.
Saving needs the server (so the change is approved and recorded). Printer, drawer and scale are kept on the till computer itself.

**Starting the till (0.28 or later):** a till already set up opens straight on the till: the window stays hidden until the till is drawn, so there is no white window and no set-up page in between. The set-up page appears only on first start, from *Till set-up*, or when the server never answered. To print only when the cashier presses **Print receipt**: **System → Settings → Tills and cash → Desktop till prints each receipt by itself** (on by default).

**Slow network:** every till (desktop or browser) waits at most a few seconds for the server to accept a sale. If it has not answered by then, the sale is kept on the till, the receipt shows and prints at once (marked *Recorded on this till while offline*), and the sale is sent from the till's queue moments later; the server never records it twice. No other call to the server waits longer than 20 seconds. **Why:** a customer should never wait for the network; the receipt number and the sale are the same either way.

**Why:** the app sends the receipt to the printer in the printer's own language (ESC/POS), which is faster and more reliable than a browser print window, and can talk to the drawer and scale, which a browser cannot.

**Note:** the app only opens your SES Labs server — no other website — so the printer and drawer cannot be reached from anywhere else.

**When the till starts:** a till already set up shows only *Opening the till…* for a moment, then the till (version 0.26 or later; older versions showed a white window and the set-up page first). It opens the server straight away if it answers. If the server has worked on this PC before but cannot be reached now (internet down), the till still opens and sells offline. If the saved address has **never** worked, the set-up page comes back with a message, so a wrong address can always be corrected.

**Updating the app:** install the new `SES Labs Till_<version>_x64-setup.exe` over the old one. The server address, printer, drawer and scale settings are kept.

#### Starting the desktop till again from scratch

Uninstalling the app keeps its settings (the server address, printer and scale), so a reinstall comes back with the same settings. To clear them:

1. Close SES Labs Till (also from the system tray, if it shows there).
2. Open **PowerShell** (Start → type *PowerShell*) and run these two lines:

   ```powershell
   Remove-Item "$env:APPDATA\app.seslabs.till" -Recurse -Force -ErrorAction SilentlyContinue
   Remove-Item "$env:LOCALAPPDATA\app.seslabs.till" -Recurse -Force -ErrorAction SilentlyContinue
   ```

3. Open SES Labs Till again (or reinstall it). It starts at the set-up page with empty fields.

**Why:** the first folder holds the till's settings; the second holds what the till app saved for working offline. Clearing both makes the PC behave like a new till. Then delete the old entry under **System → Devices** in the back office and enrol the till again (9.1).

**Note:** sales not yet sent to the server are in the second folder. Clear it only when the till shows nothing waiting to send, or when the till never worked.

## 9.8 Barcode scanner

**Tablets and phones without a scanner** use the camera: the 📷 button beside the till's search box (Chrome on Android). Hold the barcode steady in the frame; the item is added as if scanned.


1. Plug it in (USB) or pair it (Bluetooth).
2. Open Notepad and scan a product: the number appears and the cursor moves to a **new line**.
3. If it does not move to a new line, scan the **"Add Enter / CR suffix"** setting barcode from the scanner's manual.

**Why:** the till adds the product when the scanner "presses Enter" after the code.

## 9.9 Handheld (warehouse)

1. Register a device of kind **Handheld scanner** for the branch.
2. On the phone: Chrome, do 9.3 if needed, open the till address (9.2), enrol with the code.
3. Storekeepers sign in with **username and PIN**. They need a warehouse role (Storekeeper, Warehouse Manager…), not till access.

**Working offline:** the handheld keeps working when the Wi-Fi or the server drops, like a till. Receiving deliveries, moving stock between bins, receiving transfers and counting are saved on the handheld and sent by themselves when the server is back (*Working offline* and *N action(s) waiting to be sent* show at the top). Scans are recognised from the handheld's own copy of the products and shelf labels, refreshed every 30 minutes while connected. The person can even sign in offline, as long as they signed in on that handheld while it was connected before. Each action is done once on the server, even if it is sent twice. What the server refuses (e.g. a count already closed in the back office) is listed on the menu under *Refused by the server* with the reason: **Send again** after fixing the cause, or **Discard** what was done again correctly.

**What needs the connection:** the list of what is in a bin (Put away), and products or orders created since the handheld last connected. **Why:** warehouse Wi-Fi is often weakest at the back of the store, where the work is.

4. **Install it as an app:** in Chrome, menu (⋮) → **Add to Home screen** (or **Install app**). It then opens full screen from its own icon. To keep staff in it, use Android's **screen pinning** (Settings → Security → App pinning) or the brand's kiosk launcher (e.g. Zebra Enterprise Home Screen).

### Which handhelds work

Any Android handheld with **Android 10 or later** and **Google Chrome**, and any phone. The system does not need a special app or driver from the maker: the built-in scanner only has to **type the code like a keyboard and finish with Enter** (Tab also works). Phones and tablets without a scanner use the **camera**: the 📷 button beside each scan box (Chrome on Android).

What to look for when buying:

| | Recommended |
|---|---|
| Android | 11 or later, with Google Play (so Chrome stays up to date) |
| Memory | 3 GB or more |
| Scanner | a **2D imager** (reads shop barcodes, QR codes and codes on screens) |
| Wi-Fi | dual band (2.4 and 5 GHz) |
| Battery | a full shift; a spare or swappable battery for long days |
| Build | drop-rated (1.2 m or more) for warehouses |

Well-known models that fit: **Sunmi** L2s / L2H, **Urovo** DT40 / DT50, **Newland** MT90 / N7 (good value); **Honeywell** EDA52 / CT30 XP, **Zebra** TC22 / TC27 (mid-range); **Zebra** TC58 / MC33, **Honeywell** CT45 / CT60, **Datalogic** Memor 11 (rugged, for large warehouses). A Bluetooth or USB ring / pocket scanner paired with an ordinary phone or tablet also works.

### Setting up the scanner, by brand

On each handheld, open the maker's scanner settings once and set: **output as keyboard (keystrokes)** and **Enter after the code**. Then test in the handheld's scan box: the item should be found and the device beeps.

| Brand | Where | What to set |
|---|---|---|
| **Zebra** | **DataWedge** app → Profile0 (or a new profile for Chrome) | *Keystroke output*: on. *Basic data formatting* → *Send ENTER key*: on. *Intent output*: off |
| **Honeywell** | Settings → Honeywell Settings → Scanning → Internal scanner → Default profile → Data Processing | *Wedge*: on, method *Keyboard*. *Suffix*: `\n` (Enter) |
| **Sunmi** | Settings → Scanner (or the *Scanner* app) | *Output mode*: simulate keyboard / keyboard input. *Add suffix*: Enter |
| **Urovo** | Settings → Scanner settings | *Output mode*: keyboard. *Send Enter key* (or suffix Enter): on |
| **Newland** | Scan Settings (*Quick Setting*) | *Output mode*: simulate keystroke. *Suffix*: CR / Enter |
| **Datalogic** | Settings → Scanner settings → Wedge | *Keyboard wedge*: on. *Formatting* → *Label suffix*: Enter |
| Bluetooth / USB scanner | The scanner's own setting barcodes (in its manual) | *HID keyboard* mode, and the *Add Enter / CR suffix* barcode |

**If a scan does nothing:** tap the scan box and scan again; if the code appears but nothing happens, the Enter suffix is missing. The handheld also catches a scan when the cursor is not in the box, so a code is not lost after tapping a button.

**Why keyboard output:** it works the same on every brand and model, with nothing to install or update from the maker; changing brand later needs no change to the system.

## 9.10 Kitchen screen (Restaurant)

1. Register a device of kind **Kitchen display** for the branch.
2. On the screen's PC or tablet: Chrome, do 9.3 if needed, open the till address, enrol with the code.
3. Choose the **station** it shows (e.g. *KITCHEN* or *BAR*). No one signs in.
4. Press **F11** (PC) for full screen.

**Check:** send a dish from a till: it appears on the screen within a few seconds.

## 9.11 Counting stock with a handheld

During a stock count (Chapter 14.4) storekeepers scan the bin, then each product, and type the quantity. Counts from several handhelds go into the same count.

## 9.12 Label scale (Grocery)

Set the scale to print **EAN-13** labels starting with `21` (see Chapter 8, Grocery). The scale's own software holds each item's **PLU** (5-digit code) and price per kg; keep its PLU codes the same as the products' **scale codes**.

## 9.13 When a device is lost, stolen or replaced

- **Lost or stolen:** **System → Devices → Revoke** at once. Its access and every sign-in on it end immediately.
- **Replacing a till that still has unsent sales:** on the old till press **Export unsent sales** (in the yellow banner). In the back office: **System → Sync Center → Import till file**. Then register and enrol the new device.
- **Automatic copy (desktop till 0.27 or later):** while any sales are waiting, the desktop till saves the same file by itself every 15 minutes in **Documents → SES Labs Till → Unsent sales** on that PC, keeping the newest 48 (12 hours). **Why:** if the till PC breaks or its data is wiped before the sales reach the server, the newest file there is loaded with *Import till file*, even if nobody pressed the button. Loading a file twice is safe: sales already received are skipped.
- **A shift left open overnight:** the till shows *This shift was opened on … and not closed*, and **Sales → Shifts** marks it *left open since …* in red. Close it on the till (count the cash) and open a new shift. **Why:** otherwise today's sales and cash run into yesterday's shift, and neither day balances on its own.
- **Re-installing the same device:** register it as a new device (it gets the next receipt series letter, so receipt numbers never repeat).

## 9.14 Device health and remote fixes

Every till and handheld tells the server how it is doing every few minutes: its version, sales waiting to be sent, the connection to the server, free storage and the printer. **System → Devices** shows it in the **Health** column — *OK*, or what is wrong in plain words (hover for the details) — and IT is told once a day per problem: a till not heard from during trading hours, sales stuck for more than 30 minutes, items refused by the server, storage nearly full, an old till version.

With the **Remote access** add-on, someone allowed to control devices can **Send a fix** to a till: reload it, send waiting sales now, refresh the catalogue, clear its cache (catalogue and pictures — never unsent sales) or collect its logs. The till does it within about five minutes and the result shows under **Fixes**. **Why:** most till problems are solved by one of these, without anyone travelling to the shop.

**SyncLytics support** never needs the add-on: the owner opens **System → Support access** for a few hours or until a problem is closed (see Chapter 13).

**Seeing and controlling the screen:** once the remote support relay is set up (Chapter 20) and the agent is installed on a device (**System → Devices → Install remote support**), the device shows **View screen** and **Take control**. Who may connect, and whether the person at the device must accept, follow the same rules as above; every connection is in the audit log and recorded on the relay.

