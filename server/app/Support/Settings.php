<?php

namespace App\Support;

use App\Domain\Fiscal\FiscalService;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Org\IndustryService;
use App\Domain\Pharmacy\PharmacyRules;
use App\Domain\Pos\SaleDetails;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Setting;
use InvalidArgumentException;

/**
 * Tenant settings with defaults declared here. The admin Settings page is generated from
 * DEFINITIONS, so adding a definition is all it takes to make a setting editable.
 * Only add a setting together with the code that reads it.
 */
final class Settings
{
    public const DEFINITIONS = [
        'support.share_health' => [
            'type' => 'bool', 'default' => true, 'group' => 'Security',
            'label' => 'Share system health with SyncLytics support',
            'help' => 'Every hour this server tells SyncLytics support how it is doing: the checks on System → Health (backups, disk, memory, scheduler…), tills with problems and open problem reports — never sales, customers, prices or stock. Support then sees a problem before you have to call. Off: nothing is sent (the daily licence check stays).',
        ],
        'security.support_account' => [
            'type' => 'bool', 'default' => true, 'group' => 'Security',
            'label' => 'The SyncLytics support account may sign in',
            'help' => 'Off: the support account (People & access → Users, "SyncLytics support") cannot sign in until you switch it on again — for example while no support is needed. You are told each time it signs in.',
        ],
        'security.mfa_from_level' => [
            'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 7, 'group' => 'Security',
            'label' => 'Require two-factor sign-in from security level',
            'help' => 'People at this level or above must set up an authenticator app before they can use the back office. Spec default: L3 (supervisors).',
        ],
        'security.pin_max_attempts' => [
            'type' => 'int', 'default' => 5, 'min' => 3, 'max' => 10, 'group' => 'Security',
            'label' => 'Wrong POS PINs before the PIN is locked',
            'help' => 'After this many wrong PINs in a row the person cannot sign in to a till by PIN until the lockout ends.',
        ],
        'security.pin_lockout_minutes' => [
            'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440, 'group' => 'Security',
            'label' => 'POS PIN lockout (minutes)',
            'help' => 'How long a locked PIN stays locked. A manager can also reset it from the user record.',
        ],
        'security.offline_grant_hours' => [
            'type' => 'int', 'default' => 72, 'min' => 4, 'max' => 336, 'group' => 'Security',
            'label' => 'Offline till sign-in lasts (hours)',
            'help' => 'After signing in online, a person can keep using that till without the server for this long. Shorter is safer; longer suits branches with poor connections. Sales made after it runs out are still kept, and flagged for review.',
        ],
        'stock.block_negative_sales' => [
            'type' => 'bool', 'default' => false, 'group' => 'Stock',
            'label' => 'Stop sales when the system shows no stock',
            'help' => 'Off (recommended while stock counts are being set up): the till sells anyway and the shortfall shows as negative stock to investigate. On: the till refuses the sale.',
        ],
        'pos.show_stock' => [
            'type' => 'bool', 'default' => false, 'group' => 'Tills and cash',
            'label' => 'Show stock left on the till while selling',
            'help' => 'On: each item in the sale and in the search list shows how many are left at that till\'s store, counting what is already in the sale and sales not yet sent. Off: no stock figures on the till. Tills pick up the change when they next sign in.',
        ],
        'pos.require_details' => [
            'type' => 'choice', 'default' => 'none', 'group' => 'Tills and cash', 'branch' => true, 'options' => SaleDetails::REQUIRE,
            'label' => 'Before payment, the till asks for',
            'help' => 'Nothing: walk-ins buy without details (they can still be typed under Details). A name and/or phone: the cashier enters them, or picks a customer, before payment; useful for deliveries, credit or follow-up calls. Offline sales are never refused; a missing detail goes to the review list. A branch can choose differently (Company → Branches).',
        ],
        'pos.payout_approval_minor' => [
            'type' => 'int', 'default' => 10000, 'min' => 0, 'max' => 100000000, 'group' => 'Tills and cash',
            'label' => 'Expenses paid from the till: a supervisor approves above (pesewas)',
            'help' => '10000 = GHS 100. A cashier can pay an expense out of the drawer (transport, cleaning, water…) up to this amount; above it a supervisor approves with their PIN. 0 = every pay-out needs a supervisor. Each pay-out is also an expense claim, paid from the till.',
        ],
        'pos.drawer_cash_limit_minor' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1000000000, 'group' => 'Tills and cash',
            'label' => 'Cash limit in a till drawer (pesewas)',
            'help' => '0 = off. e.g. 200000 = GHS 2,000: when the cash in a drawer goes above it, the till asks for a cash drop and supervisors are told. Less cash in the drawer means less to lose.',
        ],
        'remote.consent' => [
            'type' => 'choice', 'default' => 'outside_hours', 'group' => 'Remote access', 'branch' => true,
            'options' => ['outside_hours' => 'Unattended outside trading hours; during trading the person at the device accepts', 'unattended' => 'Always unattended (a banner shows who is connected)', 'ask' => 'Always ask the person at the device'],
            'label' => 'Remote connections to tills and computers',
            'help' => 'Who may connect without someone at the device clicking Accept. Every connection shows a banner on the device and is in the audit log. A branch can choose differently (Company → Branches).',
        ],
        'momo.confirm' => [
            'type' => 'choice', 'default' => 'manual', 'group' => 'Mobile money',
            'options' => ['manual' => 'Cashier types the transaction ID (no provider)', 'prompt' => 'Payment request to the customer’s phone; typing the ID still allowed',
                'prompt_only' => 'Payment request only (typing the ID only when the till is offline)'],
            'label' => 'How the till takes mobile money',
            'help' => 'A payment request needs a provider account (Paystack or Hubtel) under System → Mobile money. The customer approves on their phone with their PIN and the sale shows the payment as verified, so a fake or wrong transaction ID cannot be used.',
        ],
        'refill.reminders' => [
            'type' => 'bool', 'default' => false, 'group' => 'Refill reminders',
            'label' => 'Remind customers to refill their medicines',
            'help' => 'For medicines with “Used a day” filled in: a customer who bought one gets a WhatsApp (or SMS) message a few days before it runs out. Only named customers who agreed to WhatsApp or SMS messages (the customer’s consent); one message lists every medicine due. See Customers → Refill reminders.',
        ],
        'refill.days_before' => ['type' => 'int', 'default' => 3, 'min' => 0, 'max' => 14, 'group' => 'Refill reminders', 'label' => 'Days before it runs out', 'help' => 'Time to come back or send someone; 3 days suits most.'],
        'refill.send_at' => ['type' => 'string', 'default' => '09:00', 'max' => 5, 'group' => 'Refill reminders', 'label' => 'Send at (HH:MM)', 'help' => 'Morning, when the pharmacy is open to answer.'],
        'summary.daily' => [
            'type' => 'bool', 'default' => false, 'group' => 'Daily summary',
            'label' => 'Send a daily summary of the business',
            'help' => 'Each evening: sales by branch against the same day last week, cash / mobile money / card, cash over or short at the till closes, low, expired and expiring stock, losses, and what waits for approval. To everyone with "receive the daily summary" (owner and general manager by default), on the bell and by the channels below.',
        ],
        'summary.time' => ['type' => 'string', 'default' => '20:00', 'max' => 5, 'group' => 'Daily summary', 'label' => 'Send it at (HH:MM)', 'help' => 'After the last till usually closes.'],
        'summary.by_email' => ['type' => 'bool', 'default' => true, 'group' => 'Daily summary', 'label' => 'Also by e-mail', 'help' => 'To the e-mail address on each person’s user account.'],
        'summary.by_whatsapp' => ['type' => 'bool', 'default' => false, 'group' => 'Daily summary', 'label' => 'Also a short WhatsApp', 'help' => 'Needs a WhatsApp Business provider and the approved template daily_summary; goes to the mobile number on the user account.'],
        'alerts.payments' => [
            'type' => 'choice', 'default' => 'off', 'group' => 'Payment alerts',
            'options' => ['off' => 'Off', 'each' => 'Each payment', 'each_summary' => 'Each payment and a daily summary', 'summary' => 'Daily summary only'],
            'label' => 'Mobile money and card payment alerts',
            'help' => 'Told to everyone with "receive payment alerts" (owner, general manager, finance manager, accountant by default): in the back office (bell), and by the channels below.',
        ],
        'alerts.payment_methods' => [
            'type' => 'choice', 'default' => 'momo_card', 'group' => 'Payment alerts',
            'options' => ['momo_card' => 'Mobile money and card', 'momo' => 'Mobile money only', 'card' => 'Card only'],
            'label' => 'Which payments',
            'help' => 'Cash is never alerted: it is counted at the close of each shift.',
        ],
        'alerts.payment_min_minor' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100_000_000_000, 'group' => 'Payment alerts',
            'label' => 'Single alerts only from (pesewas)',
            'help' => '50000 = GHS 500. Smaller payments are left out of the single alerts (they are still in the daily summary). 0 = every payment.',
        ],
        'alerts.by_email' => ['type' => 'bool', 'default' => false, 'group' => 'Payment alerts', 'label' => 'Also by e-mail', 'help' => 'To the e-mail address on the person’s user account.'],
        'alerts.by_sms' => ['type' => 'bool', 'default' => false, 'group' => 'Payment alerts', 'label' => 'Also by SMS', 'help' => 'To the mobile number on the person\'s user account. Needs an SMS provider (System → Messaging).'],
        'alerts.by_whatsapp' => ['type' => 'bool', 'default' => false, 'group' => 'Payment alerts', 'label' => 'Also by WhatsApp', 'help' => 'Needs a WhatsApp Business provider and the approved templates payment_alert and payment_summary.'],
        'alerts.summary_at' => [
            'type' => 'string', 'default' => '21:00', 'max' => 5, 'group' => 'Payment alerts',
            'label' => 'Daily summary time (HH:MM)',
            'help' => 'The day’s mobile money and card totals per branch and network are sent once, after this time.',
        ],
        'pos.auto_print_receipt' => [
            'type' => 'bool', 'default' => true, 'group' => 'Tills and cash',
            'label' => 'Desktop till prints each receipt by itself',
            'help' => 'On the desktop till with a receipt printer chosen: the receipt prints the moment the sale is done (and the drawer opens for cash), without a print window and without waiting for the server. Off: the cashier presses Print receipt. Browsers always show their print window.',
        ],
        'pos.auto_shifts' => [
            'type' => 'bool', 'default' => false, 'group' => 'Tills and cash', 'branch' => true,
            'label' => 'Automatic shifts',
            'help' => 'Each till session is named by the time it opens (the shifts under Cash → Shifts and vaults: Morning, Afternoon…), and one over the straight-day length is a Straight day. A till cannot open outside the trading hours below, or open again in a shift after it was closed, unless a manager allows it with their PIN and a reason.',
        ],
        'pos.trading_hours' => [
            'type' => 'string', 'default' => '07:00-23:00', 'max' => 11, 'group' => 'Tills and cash', 'branch' => true,
            'label' => 'Till trading hours (automatic shifts)',
            'help' => 'e.g. 07:00-23:00. Outside these hours a till opens only with a manager\'s PIN and reason. Closing and counting are always allowed.',
        ],
        'pos.straight_day_hours' => [
            'type' => 'int', 'default' => 10, 'min' => 6, 'max' => 18, 'group' => 'Tills and cash',
            'label' => 'Straight day from (hours)',
            'help' => 'A till session this long or longer is a Straight day, whatever time it opened.',
        ],
        'remote.agent_link' => [
            'type' => 'string', 'default' => '', 'max' => 500, 'group' => 'Remote access',
            'label' => 'Remote support agent install link',
            'help' => 'The invitation link of this company’s device group on the remote support relay (installation guide, chapter 20). It is shown under System → Devices → Install remote support, to open on each till, computer and handheld.',
        ],
        'remote.trading_hours' => [
            'type' => 'string', 'default' => '07:00-21:00', 'max' => 11, 'group' => 'Remote access', 'branch' => true,
            'label' => 'Trading hours (for remote connections)',
            'help' => 'e.g. 07:00-21:00, or 08:00-20:00 for a branch that opens later. During these hours the person at the device accepts each connection (with the default setting above).',
        ],
        'cash.vaults' => [
            'type' => 'bool', 'default' => false, 'group' => 'Tills and cash',
            'label' => 'Cash control with vaults (Teller → Cashier → Income Controller)',
            'help' => 'Off: whoever closes a till counts the drawer, and the difference is booked as cash over/short. On: each till has a vault (cash tray with its own float) per shift; the Cashier issues the float and assigns the teller, receives and recounts the vault blind, and hands the day to the Income Controller, who counts it blind again, decides any differences and records the bank deposit.',
        ],
        'distribution.deposits_as_liability' => [
            'type' => 'bool', 'default' => false, 'group' => 'Distribution',
            'label' => 'Book container deposits as money owed to customers',
            'help' => 'Off: deposits on crates, bottles and cylinders count as sales, and empties bought back reduce sales. On: deposits are held as a liability (Container deposits held) and the containers out with customers stay an asset until they come back. Change it only at the start of a month so a month is not split between the two methods.',
        ],
        'evat.mode' => [
            'type' => 'choice', 'default' => 'off', 'group' => 'GRA E-VAT', 'options' => FiscalService::MODES,
            'label' => 'Report to GRA (E-VAT)',
            'help' => 'Each sale as it happens: the GRA code and QR print on the receipt (when GRA answers within a few seconds; otherwise sent shortly after). Daily batch: till sales go together once a day, receipts carry no GRA code. Invoices only: till sales are not reported. Needs GRA_EVAT_URL and GRA_EVAT_KEY in the server settings (.env).',
        ],
        'evat.daily_time' => [
            'type' => 'string', 'default' => '23:30', 'max' => 5, 'group' => 'GRA E-VAT',
            'label' => 'Daily batch time (HH:MM)',
            'help' => 'In daily mode, the till sales are sent after this time each day (and anything left over is sent the next day).',
        ],
        'ai.provider' => [
            'type' => 'choice', 'default' => 'off', 'group' => 'AI', 'options' => ['off' => 'Off — built-in insights only', 'ollama' => 'Local model (Ollama on your server, free)', 'claude' => 'Claude (cloud, within the monthly budget)',
                'cloud' => 'Another cloud model (Google Gemini, Groq, OpenRouter, Mistral, OpenAI; several have free plans)'],
            'label' => 'AI assistant',
            'help' => 'Forecasts, alerts and insights always work without AI. The copilot, invoice scanning and product descriptions need a model: a local one (Ollama, nothing leaves your servers), Claude, or another cloud service chosen below.',
        ],
        'ai.cloud_service' => [
            'type' => 'choice', 'default' => 'gemini', 'group' => 'AI',
            'options' => ['gemini' => 'Google Gemini (free plan)', 'groq' => 'Groq (free plan)', 'openrouter' => 'OpenRouter (free models)', 'mistral' => 'Mistral (free plan)',
                'openai' => 'OpenAI', 'custom' => 'Other OpenAI-compatible service (address below)'],
            'label' => 'Other cloud model: service',
            'help' => 'Used when the AI assistant is "Another cloud model". Its API key goes in the server settings (.env: AI_CLOUD_API_KEY), never on screen. Free plans limit how many questions a day; the cloud budget below applies to Claude only.',
        ],
        'ai.cloud_model' => [
            'type' => 'string', 'default' => '', 'max' => 120, 'group' => 'AI',
            'label' => 'Other cloud model: model name',
            'help' => 'Leave empty for the service\'s usual model. Services rename their models from time to time: copy the exact name from the service\'s model list. For the copilot it must support tools (function calling).',
        ],
        'ai.cloud_vision_model' => [
            'type' => 'string', 'default' => '', 'max' => 120, 'group' => 'AI',
            'label' => 'Other cloud model: model for reading invoices (images)',
            'help' => 'Leave empty for the service\'s usual one, or the same as above when that model reads images.',
        ],
        'ai.cloud_url' => [
            'type' => 'string', 'default' => '', 'max' => 200, 'group' => 'AI',
            'label' => 'Other cloud model: service address',
            'help' => 'Only for "Other OpenAI-compatible service": the address ending in /v1 (the part before /chat/completions).',
        ],
        'ai.ollama_url' => [
            'type' => 'string', 'default' => 'http://127.0.0.1:11434', 'max' => 200, 'group' => 'AI',
            'label' => 'Ollama address',
            'help' => 'Where the local model runs (see the installation guide, AI chapter).',
        ],
        'ai.ollama_model' => [
            'type' => 'string', 'default' => 'qwen2.5:7b', 'max' => 80, 'group' => 'AI',
            'label' => 'Local model for questions and writing',
            'help' => 'Must support tools (function calling), e.g. qwen2.5:7b or llama3.1:8b.',
        ],
        'ai.ollama_vision_model' => [
            'type' => 'string', 'default' => 'qwen2.5vl:7b', 'max' => 80, 'group' => 'AI',
            'label' => 'Local model for reading invoices (images)',
            'help' => 'A vision model, e.g. qwen2.5vl:7b or llama3.2-vision.',
        ],
        'ai.claude_model' => [
            'type' => 'string', 'default' => 'claude-sonnet-5-5', 'max' => 80, 'group' => 'AI',
            'label' => 'Claude model',
            'help' => 'Used only when the AI assistant is set to Claude and the API key is in the server settings (.env: ANTHROPIC_API_KEY).',
        ],
        'ai.monthly_cap_usd' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 10000, 'group' => 'AI',
            'label' => 'Cloud AI budget per month (USD)',
            'help' => 'Claude stops for the rest of the month when this is spent. 0 = no cloud AI.',
        ],
        'ai.personal_data_local' => [
            'type' => 'bool', 'default' => true, 'group' => 'AI',
            'label' => 'Keep personal data on your server',
            'help' => 'On: questions that need customer or staff details are answered by the local model only (or without AI wording when none is set up). Names, phones and e-mails are always masked before anything goes to the cloud.',
        ],
        'insights.margin_alert_pct' => [
            'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 90, 'group' => 'AI',
            'label' => 'Warn when a product’s margin is below (%)',
            'help' => 'Selling price before tax compared with average cost. Products sold below cost are always flagged.',
        ],
        'purchasing.over_receipt_pct' => [
            'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 100, 'group' => 'Purchasing',
            'label' => 'Over-delivery accepted without a manager (%)',
            'help' => 'A delivery may exceed the ordered quantity by this much. Above it, the goods received note waits until a manager with “approve deliveries” accepts it.',
        ],
        'loyalty.enabled' => [
            'type' => 'bool', 'default' => true, 'group' => 'Loyalty',
            'label' => 'Loyalty points',
            'help' => 'Customers on a sale earn 1 point per GHS 1 paid; 100 points are worth GHS 1 at the till.',
        ],
        'loyalty.min_redeem_points' => [
            'type' => 'int', 'default' => 500, 'min' => 0, 'max' => 100000, 'group' => 'Loyalty',
            'label' => 'Points needed before they can be used',
            'help' => 'A customer can pay with points once they hold at least this many.',
        ],
        'loyalty.expiry_months' => [
            'type' => 'int', 'default' => 12, 'min' => 0, 'max' => 120, 'group' => 'Loyalty',
            'label' => 'Points expire after (months)',
            'help' => 'Points not used within this many months of being earned expire (oldest first). 0 = never.',
        ],
        'stored_value.expiry_months' => [
            'type' => 'int', 'default' => 12, 'min' => 0, 'max' => 120, 'group' => 'Gift cards',
            'label' => 'Gift cards and store credit expire after (months unused)',
            'help' => 'A balance expires when the card has not been used for this long; any use resets the clock. 0 = never.',
        ],
        'returns.days' => [
            'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 365, 'group' => 'Returns',
            'label' => 'Returns accepted within (days, with receipt)',
            'help' => 'Within this many days a cashier can take goods back against the receipt and refund to the original payment. Without a receipt (or later): store credit only, with a supervisor PIN.',
        ],
        'finance.year_end_month' => [
            'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 12, 'group' => 'Finance',
            'label' => 'Financial year ends in month (1–12)',
            'help' => '12 = January to December (owner decision). Set this before the first posting: months already created keep their dates.',
        ],
        'finance.separate_books' => [
            'type' => 'bool', 'default' => true, 'group' => 'Finance',
            'label' => 'Separate books for each industry',
            'help' => 'On (default): each industry is its own company with its own books, created for you under Company → Legal entities (fill in its registered name and TIN). What the companies owe each other is recorded automatically. Off: one set of books for the whole company, with each industry as a profit centre. Change it only at the start of a financial year.',
        ],
        'finance.share_head_office_costs' => [
            'type' => 'bool', 'default' => false, 'group' => 'Finance',
            'label' => 'Share head office costs between industries by sales',
            'help' => 'Off (default): each industry\'s profit and loss shows only its own costs, and head office costs stay in the Company column. On: at month end the accountant can share the month\'s head office costs between the industries in proportion to their sales (Finance → Reports → Profit by industry), with one journal that can be reversed.',
        ],
        'payables.match_tolerance_pct' => [
            'type' => 'int', 'default' => 2, 'min' => 0, 'max' => 100, 'group' => 'Finance',
            'label' => 'Supplier bill price difference allowed (%)',
            'help' => 'A bill whose prices differ from the purchase order by more than this (or more than the amount below, whichever is smaller) waits for a finance manager.',
        ],
        'payables.match_tolerance_minor' => [
            'type' => 'int', 'default' => 5000, 'min' => 0, 'max' => 100000000, 'group' => 'Finance',
            'label' => 'Supplier bill price difference allowed (pesewas)',
            'help' => '5000 = GHS 50.00 per bill.',
        ],
        'grocery.scale_prefixes' => [
            'type' => 'string', 'default' => '21', 'max' => 40, 'group' => 'Grocery',
            'label' => 'Scale label barcodes start with',
            'help' => 'EAN-13 labels from the scales: prefix (2 digits), the product\'s 5-digit scale code (PLU), the weight in grams (5 digits), check digit. Several prefixes: 21,22.',
        ],
        'grocery.markdown_prefix' => [
            'type' => 'string', 'default' => '29', 'max' => 2, 'group' => 'Grocery',
            'label' => 'Markdown stickers start with',
            'help' => 'First two digits of the barcodes printed on near-expiry markdown stickers. Must differ from the scale prefixes.',
        ],
        'grocery.markdown_rules' => [
            'type' => 'string', 'default' => '7:30,3:50', 'max' => 60, 'group' => 'Grocery',
            'label' => 'Suggested markdowns (days left : % off)',
            'help' => '"7:30,3:50" = 30 % off when 7 days or fewer are left, 50 % at 3 days. A manager applies them.',
        ],
        'grocery.esl_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Grocery',
            'label' => 'Electronic shelf labels: price update address',
            'help' => 'If you use electronic shelf labels, their gateway\'s web address. Price changes are sent there as they happen. Leave blank if you do not.',
        ],
        'pharmacy.pom_rule' => [
            'type' => 'choice', 'default' => 'approve', 'group' => 'Pharmacy', 'branch' => true, 'options' => PharmacyRules::LEVELS,
            'label' => 'Selling prescription-only (POM) medicines',
            'help' => 'Pharmacist approves (recommended, Pharmacy Council practice): the patient, the prescription and a pharmacist\'s username and PIN. Prescription details only: the teller records the patient and prescription and sells. Patient only: the teller picks the patient. Sell freely: no questions. A branch can choose differently (Company → Branches).',
        ],
        'pharmacy.controlled_rule' => [
            'type' => 'choice', 'default' => 'approve', 'group' => 'Pharmacy', 'branch' => true, 'options' => PharmacyRules::LEVELS,
            'label' => 'Selling controlled drugs',
            'help' => 'As for prescription-only medicines. Controlled drugs always go in the controlled-drug register, whoever sells them.',
        ],
        'pharmacy.controlled_always_strict' => [
            'type' => 'bool', 'default' => false, 'group' => 'Pharmacy', 'branch' => true,
            'label' => 'Controlled drugs always need a pharmacist',
            'help' => 'On: controlled drugs need a pharmacist\'s approval and the prescription whatever "Selling controlled drugs" says, in every branch where this is on.',
        ],
        'pharmacy.max_qty_without_pharmacist' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100000, 'group' => 'Pharmacy', 'branch' => true,
            'label' => 'A pharmacist must approve more than this many (smallest unit) of one medicine',
            'help' => 'Where tellers may sell prescription-only or controlled medicines, a line above this quantity (in the unit stock is counted in, e.g. tablets) still needs a pharmacist. Stops bulk sales of codeine, tramadol and the like. 0 = no limit.',
        ],
        'pharmacy.major_warning_needs_pharmacist' => [
            'type' => 'bool', 'default' => true, 'group' => 'Pharmacy', 'branch' => true,
            'label' => 'A major allergy or interaction warning needs a pharmacist',
            'help' => 'Warnings always show to the person selling. On: when one is major (the patient is allergic, or the medicines must not be taken together), a pharmacist must approve the sale even where tellers may sell.',
        ],
        'pharmacy.after_sale_check' => [
            'type' => 'choice', 'default' => 'signoff', 'group' => 'Pharmacy', 'branch' => true,
            'options' => ['signoff' => 'A pharmacist signs them off afterwards', 'summary' => 'Daily summary to the superintendent', 'none' => 'Nothing (recorded on the sale)'],
            'label' => 'Medicine sales made without a pharmacist',
            'help' => 'Sign off: they wait on Pharmacy → Sales to sign off for a pharmacist to check and sign, and pharmacists are reminded each evening. Daily summary: people who manage the pharmacy get the day\'s list each evening. Pharmacy → Sales to sign off lists them either way, ready for an inspection.',
        ],
        'pharmacy.premises_licence_no' => [
            'type' => 'string', 'default' => '', 'max' => 60, 'group' => 'Pharmacy',
            'label' => 'Pharmacy Council premises licence number',
            'help' => 'Printed on the controlled-drug register and prescription receipts.',
        ],
        'pharmacy.superintendent' => [
            'type' => 'string', 'default' => '', 'max' => 120, 'group' => 'Pharmacy',
            'label' => 'Superintendent pharmacist',
            'help' => 'Name and registration number, printed on the controlled-drug register.',
        ],
        'restaurant.service_charge_bp' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 2500, 'group' => 'Restaurant',
            'label' => 'Service charge on table bills (basis points)',
            'help' => '1000 = 10 %. Added to table and bar-tab bills at the till as a line of the service-charge product below; 0 = none. The cashier can remove it.',
        ],
        'restaurant.service_charge_sku' => [
            'type' => 'string', 'default' => 'SERVICE', 'max' => 40, 'group' => 'Restaurant',
            'label' => 'Service-charge product (SKU)',
            'help' => 'Create a "Service charge" product that does not track stock, then copy the SKU the system gave it here. Its tax group sets the tax on the service charge.',
        ],
        'restaurant.tips_enabled' => [
            'type' => 'bool', 'default' => true, 'group' => 'Restaurant',
            'label' => 'Ask for a tip at payment',
            'help' => 'Tips are paid on top of the bill, owed to the waiter on the bill (or the cashier), and paid out with that month’s payroll.',
        ],
        'restaurant.waste_approval_minor' => [
            'type' => 'int', 'default' => 5000, 'min' => 0, 'max' => 100000000, 'group' => 'Restaurant',
            'label' => 'Kitchen waste needing a manager (pesewas)',
            'help' => '5000 = GHS 50.00. Waste worth more than this (at cost) is approved by a manager’s PIN when it is recorded.',
        ],
        'restaurant.sms_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Restaurant',
            'label' => 'SMS gateway address (booking messages)',
            'help' => 'Booking confirmations and reminders are posted here as {"to", "message", "reference"} for your SMS provider to send. Leave blank for no SMS.',
        ],
        'restaurant.sms_confirmations' => [
            'type' => 'bool', 'default' => true, 'group' => 'Restaurant',
            'label' => 'Text the guest when a table is booked',
            'help' => 'Needs the SMS gateway address and the guest’s phone number.',
        ],
        'restaurant.reminder_hours' => [
            'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 72, 'group' => 'Restaurant',
            'label' => 'Booking reminder (hours before)',
            'help' => 'A reminder text this many hours before the booking; 0 = no reminders.',
        ],
        'restaurant.hold_minutes' => [
            'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 240, 'group' => 'Restaurant',
            'label' => 'Show a booked table as reserved from (minutes before)',
            'help' => 'On the till’s floor view; a booking more than 15 minutes late is shown as late so the table can be released.',
        ],
        'fashion.alteration_sku' => [
            'type' => 'string', 'default' => 'ALTERATION', 'max' => 40, 'group' => 'Fashion',
            'label' => 'Alteration product (SKU)',
            'help' => 'Alterations are paid at the till as this product at the agreed charge. Create an "Alteration" product with stock tracking off, then copy the SKU the system gave it here; its tax group taxes the charge.',
        ],
        'stock.adjustment_approval_minor' => [
            'type' => 'int', 'default' => 50000, 'min' => 0, 'max' => 1000000000, 'group' => 'Stock',
            'label' => 'Stock adjustments above this value need a manager\'s approval (in pesewas/cents)',
            'help' => 'Worked out at average cost (increases and decreases added up). 50000 = GHS 500. 0 = every adjustment document needs approval by someone else.',
        ],
        'catalog.internal_barcode_prefix' => [
            'type' => 'string', 'default' => '20', 'max' => 2, 'group' => 'Stock',
            'label' => 'In-store barcodes start with',
            'help' => 'Products without a barcode can be given one when printing labels: an EAN-13 starting with these two digits (20–29 are for in-store use). Must differ from the scale and markdown prefixes (Grocery).',
        ],
        'stock.adjustment_photos' => [
            'type' => 'choice', 'default' => 'optional', 'group' => 'Stock',
            'options' => ['optional' => 'Optional', 'losses' => 'Required for damaged, expired, spoiled and missing stock', 'off' => 'Not used'],
            'label' => 'Photos on stock adjustment lines',
            'help' => 'A photo of the damaged or expired goods, attached to the line and kept with the adjustment. "Required" stops the adjustment from being submitted without one.',
        ],
        'stock.cycle_count_per_day' => [
            'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 500, 'group' => 'Stock',
            'label' => 'Cycle count: products per store per day',
            'help' => 'Stock → My day lists this many products to count each day: fast-moving and valuable ones every 30 days, the next every 60, the rest every 120; products often wrong twice as often. 0 = no cycle counts.',
        ],
        'stock.default_min_qty' => [
            'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 100000, 'group' => 'Stock',
            'label' => 'Default minimum stock (in the unit each product is bought in)',
            'help' => 'Every product, new and existing, keeps at least this many of its buying unit (the supplier\'s unit, e.g. 5 packs; the base unit if it has only one) in each store, and is reordered up to twice that. A product\'s own figure (Purchasing → Reorder levels, or min_qty / max_qty in the products file) or an accepted smart level replaces it. 0 = no default minimum.',
        ],
        'stock.default_exclude_slow_movers' => [
            'type' => 'bool', 'default' => false, 'group' => 'Stock',
            'label' => 'No default minimum for slow movers',
            'help' => 'On: products that have not sold in a store for 180 days (and are older than that) get no default minimum there, so dead stock is not reordered. Their own figures still apply.',
        ],
        'stock.smart_levels' => [
            'type' => 'choice', 'default' => 'suggest', 'group' => 'Stock',
            'options' => ['suggest' => 'Suggest from sales, I accept (recommended)', 'auto' => 'Update automatically from sales (never over an own figure)', 'off' => 'Off'],
            'label' => 'Smart reorder levels',
            'help' => 'Every Monday the system works out each product\'s minimum and maximum per store from the last 12 weeks of sales, the supplier\'s delivery time and a safety margin. Suggest: they wait on Purchasing → Suggested levels for you to tick and accept. Automatically: they replace the default and earlier smart levels by themselves; figures you typed are never changed.',
        ],
        'stock.low_stock_alerts' => [
            'type' => 'bool', 'default' => true, 'group' => 'Stock',
            'label' => 'Low-stock alerts',
            'help' => 'Each morning, buyers (permission to manage purchasing) get a notification for each store with products at or below their minimum. The dashboard shows the count all the time.',
        ],
        'stock.expiry_alert_days' => [
            'type' => 'int', 'default' => 90, 'min' => 7, 'max' => 365, 'group' => 'Stock',
            'label' => 'Warn about stock expiring within (days)',
            'help' => 'Stock → Expiry & returns lists batches expiring within this many days (amber under 30 days, red once expired), and the dashboard shows how many there are. Time to sell them, mark them down or send them back to the supplier.',
        ],
        'stock.block_sale_days_before_expiry' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 365, 'group' => 'Stock',
            'label' => 'Stop selling a batch this many days before it expires',
            'help' => '0 = sell until the expiry date (expired batches are never sold). For example 30: the till skips batches with less than 30 days left and takes the next batch, or refuses if there is none; useful where patients must finish a course before the expiry date.',
        ],
        'stock.disposal_evidence' => [
            'type' => 'choice', 'default' => 'method_witness', 'group' => 'Stock', 'options' => ExpiryService::EVIDENCE,
            'label' => 'Evidence kept when expired or damaged stock is disposed of',
            'help' => 'What the person preparing a disposal must record. Controlled drugs always need a witness, whatever is chosen. The FDA expects the method and a witness; a certificate from the waste company or the FDA officer can be uploaded as well.',
        ],
        'services.slot_minutes' => [
            'type' => 'int', 'default' => 15, 'min' => 5, 'max' => 120, 'group' => 'Services',
            'label' => 'Appointment times every (minutes)',
            'help' => 'The steps offered when booking an appointment and the rows of the staff calendar.',
        ],
        'services.day_start' => [
            'type' => 'string', 'default' => '08:00', 'max' => 5, 'group' => 'Services',
            'label' => 'Calendar day starts at (HH:MM)',
            'help' => 'The first time shown in the staff calendar and offered for bookings.',
        ],
        'services.day_end' => [
            'type' => 'string', 'default' => '20:00', 'max' => 5, 'group' => 'Services',
            'label' => 'Calendar day ends at (HH:MM)',
            'help' => 'The last time shown in the staff calendar.',
        ],
        'services.reminder_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 0, 'max' => 72, 'group' => 'Services',
            'label' => 'Text an appointment reminder this many hours before (0 = never)',
            'help' => 'Sent by the SMS gateway below to customers whose phone number is on the appointment.',
        ],
        'services.sms_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Services',
            'label' => 'SMS gateway address for appointment messages',
            'help' => 'Leave empty to use the restaurant one. The address of your SMS provider (or a small bridge to it) that accepts {"to", "message", "reference"}.',
        ],
        'services.sms_confirmations' => [
            'type' => 'bool', 'default' => true, 'group' => 'Services',
            'label' => 'Text a confirmation when an appointment is booked',
            'help' => 'Only when an SMS gateway is set and the appointment has a phone number.',
        ],
        'receipt.footer' => [
            'type' => 'string', 'default' => 'Thank you for shopping with us.', 'max' => 200, 'group' => 'Receipts',
            'label' => 'Message at the bottom of receipts',
            'help' => 'Printed on every till receipt, e.g. returns policy or a thank-you line.',
        ],
        'security.device_enrollment_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168, 'group' => 'Devices',
            'label' => 'Device enrolment code valid for (hours)',
            'help' => 'A new till must be enrolled with its one-time code within this time, or the code must be re-issued.',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function get(string $key, ?string $tenantId): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
        if (! $tenantId) {
            return $def['default'];
        }
        $this->cache[$tenantId] ??= Setting::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('value', 'key')->all();

        return $this->cache[$tenantId][$key] ?? $def['default'];
    }

    /** A setting a branch may set for itself ('branch' => true): the branch's value, else the company's. */
    public function forBranch(string $key, Branch|string|null $branch, ?string $tenantId): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
        $branch = is_string($branch) ? Branch::withoutGlobalScopes()->find($branch) : $branch;
        $own = ! empty($def['branch']) ? ($branch?->settings[$key] ?? null) : null;

        return $own ?? $this->get($key, $tenantId ?? $branch?->tenant_id);
    }

    /** @return list<string> settings a branch may set for itself */
    public static function branchKeys(?string $prefix = null): array
    {
        return array_keys(array_filter(self::DEFINITIONS, fn ($d, $k) => ! empty($d['branch']) && ($prefix === null || str_starts_with($k, $prefix)), ARRAY_FILTER_USE_BOTH));
    }

    /** A value checked against the setting's definition (type, range, options). */
    public static function valid(string $key, mixed $value): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
        if ($def['type'] === 'int') {
            $value = (int) $value;
            if ($value < $def['min'] || $value > $def['max']) {
                throw new DomainException("{$def['label']} must be between {$def['min']} and {$def['max']}.");
            }
        } elseif ($def['type'] === 'choice') {
            $value = (string) $value;
            if (! array_key_exists($value, $def['options'])) {
                throw new DomainException("{$def['label']}: choose one of the options.");
            }
        } elseif ($def['type'] === 'bool') {
            $value = (bool) $value;
        } elseif ($def['type'] === 'string') {
            $value = trim((string) $value);
            if (mb_strlen($value) > $def['max']) {
                throw new DomainException("{$def['label']} can be at most {$def['max']} characters.");
            }
        }

        return $value;
    }

    public function set(Actor $actor, string $key, mixed $value, ?string $reason = null): void
    {
        $value = self::valid($key, $value);

        $setting = Setting::withoutGlobalScopes()->firstOrNew(['tenant_id' => $actor->tenantId, 'key' => $key]);
        $setting->tenant_id = $actor->tenantId;
        $setting->value = $value;
        $this->recorder->save($actor, $setting, 'setting.changed', $reason);
        unset($this->cache[$actor->tenantId]);
        if ($key === 'finance.separate_books') {
            app(IndustryService::class)->applyBooksChoice($actor, (bool) $value); // give each industry its company, or bring them back to one set of books
        }
    }
}
