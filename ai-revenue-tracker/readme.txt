=== AI Revenue Tracker for WooCommerce: ChatGPT & AI Sales Attribution ===
Contributors: yourwporgusername
Tags: chatgpt, ai, attribution, analytics, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.5
WC tested up to: 11.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See how much ChatGPT, Perplexity, Gemini, Claude, Copilot and AI shopping agents sell for your WooCommerce store.

== Description ==

AI Revenue Tracker shows WooCommerce store owners exactly how much revenue comes from AI assistants and AI shopping agents.

Most of this revenue is invisible today. ChatGPT often sends visitors with no referrer, so Google Analytics files them under "Direct". AI agents that buy through your store API never load a page, so no tracking script ever fires. This plugin tracks AI sales on your own server, where nothing gets lost.

**What it tracks**

* **AI click-throughs.** Shoppers who arrive from ChatGPT, Perplexity, Gemini, Claude, Copilot, Meta AI, Grok, DeepSeek, Mistral and You.com, detected by referrer and by the tracking tags AI apps add to links. Works with page caching.
* **AI agent orders.** Orders placed by AI agents through the WooCommerce API or agentic checkout, tagged automatically.
* **AI crawlers.** Which AI bots read your product pages (OAI-SearchBot, GPTBot, ChatGPT-User, PerplexityBot, ClaudeBot, Applebot, Amazonbot and more), verified against the IP ranges AI companies publish, so fake bots are filtered out.
* **"How did you find us?"** An optional one-click question on the order confirmation page that catches AI-influenced sales with no digital trace.
* **Past orders.** On activation it scans your last 180 days of orders using WooCommerce's built-in order attribution, so you see AI revenue from day one.

**What you see**

* "AI sold $X for you this month", with share of store revenue
* Revenue, orders and visits by AI assistant
* Which products AI sells and which products AI crawlers read most
* AI average order value compared with your store average
* An "AI source" column on the orders screen and an AI attribution box on each order

**Honest numbers**

Confirmed AI sales (agent orders, AI click-throughs and customer answers) are kept separate from estimates. The optional "likely AI" estimate is always labelled and never added to your confirmed total.

**Private by design**

Everything runs on your server. No account is needed and no store data is sent anywhere. Visit and crawler counts are stored as daily totals without IP addresses. The plugin respects the WP Consent API.

== Installation ==

1. Install and activate WooCommerce (8.5 or newer).
2. Install and activate AI Revenue Tracker.
3. Go to WooCommerce → AI Revenue. Past orders are scanned in the background within a few minutes.

== Frequently Asked Questions ==

= How do I see how much ChatGPT sells for my WooCommerce store? =

Install this plugin and open WooCommerce → AI Revenue. It shows revenue from ChatGPT and other AI assistants, including sales Google Analytics reports as "Direct".

= Why does Google Analytics not show my ChatGPT traffic? =

ChatGPT and some other AI apps often send visitors without a referrer, and AI agents that buy through your API never run browser scripts. GA4 can't see either. This plugin records them on your server.

= Does it track orders placed by AI agents like ChatGPT checkout? =

Yes. Orders created by known AI agents, by API users you mark as AI agent integrations, or through agentic checkout integrations are tagged as "AI agent placed the order". Developers can tag orders from any integration with the `airt_agent_order_source` filter.

= Does it work with page caching and CDNs? =

Yes for shopper tracking: a small script records AI visits on cached pages. The crawler log only sees requests that reach WordPress, so bot visits served entirely from a cache are not counted. If you use Cloudflare or another proxy, choose it under Settings so bot verification sees the real IP.

= Does it slow down my store? =

No. Detection is a few string checks. The tracking script is under 2 KB and loads deferred.

= Do I need an account? =

No. Everything in this plugin works without an account or external service.

= Is it GDPR friendly? =

It stores no personal data beyond what is on the order already. It uses one first-party cookie to remember which AI app a shopper came from, and respects the WP Consent API when a consent plugin is installed.

= Does it work with HPOS and the block checkout? =

Yes. It supports High-Performance Order Storage and both classic and block checkout.

== Screenshots ==

1. AI revenue dashboard.
2. Revenue by AI assistant and how each sale was attributed.
3. AI crawlers reading your products.
4. AI source column on the orders screen.

== Changelog ==

= 0.1.0 =
* First release.
