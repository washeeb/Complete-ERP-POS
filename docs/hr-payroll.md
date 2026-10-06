# HR, attendance and payroll

Everything here is at **head office** (HR & payroll menu), except clocking in, which happens on any till.

## Employees

**HR & payroll → Employees → Add employee.** Name, branch, start date, job, and pay per month: basic salary, transport and meal allowances, overtime rate (1.5 × by default), provident fund (Tier 3) %, welfare dues, and how they are paid (bank account, MoMo number or cash). Tick *qualifying junior staff* if their overtime is taxed at the special 5 % / 10 % rates. Link the employee to **their sign-in** so their clock-ins count and they can see their own leave and payslips. *End employment* when someone leaves (they are paid up to their last day).

Pay and bank details are only visible to HR and payroll.

## Clocking in and out

At any till, on the sign-in screen: type your **username and PIN** and press **Clock in** (or **Clock out**). You do not need to sign in. If the till is offline it still works for anyone who has signed in on that till before; the clock-in is sent when the connection is back.

**HR & payroll → Timesheets** (managers): each person's days, hours and overtime for the month. Red times are clock-ins without a clock-out — click the person, then *Add* the missing clock-out, or *Cancel* a wrong one. A reason is required; nothing is deleted.

Overtime is time beyond the standard 8-hour day.

## Leave

Staff request leave under **My leave & payslips** (or HR records it for them). Types: annual (15 working days a year), sick (with a doctor's note), maternity (12 weeks), paternity (5 days), unpaid. The branch manager approves or refuses under **Leave**; nobody approves their own. Unpaid leave is deducted from pay.

## Loans and salary advances

**HR & payroll → Loans & advances → Give a loan or advance**: amount, monthly deduction, first payroll month, and the account it was paid from. Payroll deducts it until it is repaid.

## Monthly payroll

1. **Prepare** (HR / payroll officer): *Payroll → Prepare payroll*, choose the month. Each payslip uses the employee's pay, the month's overtime, unpaid leave and loan deductions. Fix anything (an employee's pay, a missing clock-out) and press *Recalculate* as often as needed.
2. **Approve** (finance manager or GM — not the person who prepared it). The payslips are locked and the payroll is posted to the books.
3. **Pay**: send the *Bank / MoMo list (CSV)* to the bank or MoMo bulk-payment portal, hand cash staff their pay against the *Pay sheet* (they sign), then *Record payment*.
4. **Payslips**: print or save as PDF from the payroll; staff see their own under *My leave & payslips*.

How a payslip is worked out: SSNIT 5.5 % of basic; Tier 3 (if any) is tax-relieved; PAYE on basic + allowances + overtime − SSNIT − Tier 3, using the monthly bands; then welfare dues and loan repayments. The employer adds 13 % SSNIT (Tier 1 13.5 % and Tier 2 5 % of basic are what you remit).

## Tax and SSNIT rules — accountant must confirm

*Payroll → Tax and SSNIT rules* shows the PAYE bands and SSNIT rates the system uses (seeded with the 2024 Ghana bands). Until your accountant checks them and presses **Check and confirm**, every payroll shows *NOT YET CHECKED*. When GRA changes the bands, update them there.

PAYE, SSNIT, Tier 2/3 and welfare amounts sit in their payable accounts until you pay them over (record that as a supplier payment or a journal).

## Who can do what

| | Employees | Pay details | Timesheets | Approve leave | Loans | Prepare payroll | Approve payroll | Pay |
|---|---|---|---|---|---|---|---|---|
| HR manager | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| Payroll officer | view | | | | | ✓ | | |
| Finance manager | view | | | | | | ✓ | ✓ |
| General manager | view | | | ✓ | | | ✓ | |
| Branch manager | view (branch) | | ✓ (branch) | ✓ (branch) | | | | |
| Supervisor | | | ✓ (branch) | | | | | |
| Everyone with a sign-in | own leave and payslips | | | | | | | |

## Not yet

Fingerprint or phone clocking, shift rosters, public holidays, daily-rate casual pay, SSNIT and GRA return files, performance reviews and recruitment.
