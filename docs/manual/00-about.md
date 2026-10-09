# About this guide

This guide takes you from an empty computer to a working shop, restaurant, pharmacy or chain of branches running **Ervanta**. It is written for someone who is **not** an IT professional. Every step says **what** to do, and where it helps, **why** it is needed.

## How to read it

1. Read **Chapter 1 (Words used in this guide)** once. It explains terms such as *server*, *till*, *branch*, *module* and *licence*.
2. Use **Chapter 2 (Choose your setup)** to pick the installation that fits your business. Each setup lists the chapters you need, in order.
3. Check **Chapter 3 (What you need)** before buying or preparing anything.
4. Follow the installation chapter for your setup **in order**. Do not skip steps. Each step ends with a **Check** that tells you how to know it worked.
5. Keep the guide for daily running: backups, updates and troubleshooting are at the end.

## Boxes used in this guide

**Why:** explains the reason for a step, so you understand what you are doing.

**Note:** extra information that is useful but not required.

**Tip:** a shortcut or a good habit.

**Warning:** something that can lose data, money or security if ignored. Read these carefully.

**Check:** how to confirm that a step worked before you move on.

## Typing commands

Some steps ask you to type a **command**. A command is a line of text you type into a black window called a **terminal** and confirm with **Enter**.

- On **Windows** the terminal is **PowerShell**. Open it like this: click **Start**, type `PowerShell`, then click **Windows PowerShell**. When a step says *as administrator*, right-click it and choose **Run as administrator**, then click **Yes**.
- On **Ubuntu Linux** the terminal is called **Terminal**, or you reach the server through **SSH** (explained in Chapter 5).

Commands are shown in a dark box, like this:

```
php --version
```

Type them exactly as shown (you can copy and paste). Text in angle brackets, such as `<your-password>`, is a placeholder: replace the whole thing, brackets included, with your own value.

**Tip:** In PowerShell you can paste with a right-click. Press the **Up arrow** key to bring back the last command.

## What is ready today and what comes later

The software is built in phases. This guide always describes the software **as it is now**, and is updated with every release.

| Area | Today | Later |
|---|---|---|
| Server installation | Step-by-step manual installation (this guide) on Windows or Ubuntu Linux | One-click installers for every setup (Phase 13) |
| Till app | Runs in the Chrome or Edge browser and can be installed as an app; works offline | Optional desktop app with printer and cash-drawer drivers (Phase 13) |
| Backups | Manual and scheduled database backups (Chapter 12) | Encrypted automatic backups with a tested restore (Phase 13) |
| Industry modules | Grocery, Pharmacy, Restaurant, Electronics, Fashion, Services, Sales & Distribution, Manufacturing, Online Storefront | Logistics, Assets, Projects and Helpdesk |
| Insights and AI | Forecasts, stock health, customers, pricing, cash flow, loss prevention and alerts (no AI needed); optional local AI assistant (Chapter 15) | Scheduled reports by e-mail (Phase 12) |

**Warning:** Use the software with test data until your accountant has checked the tax set-up (Chapter 7, step 7) and you have practised a backup and a restore (Chapter 12).
