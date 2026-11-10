=== NeedToMe AI Chat ===
Contributors: needtome
Donate link: https://www.donationalerts.com/r/needtome
Tags: ai chatbot, live chat, lead generation, customer support, ai assistant
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
Tested up to: 7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI consultant that answers visitors from your knowledge base, speaks their language and collects leads. Requires a kra.name account.

== Description ==

NeedToMe AI Chat puts an AI consultant on your WordPress site. It answers visitors around the clock from what you teach it: the pages of your site, documents, price lists and your own answers. It speaks the visitor's language, asks for a name, phone or e-mail when a visitor wants to order or be called back, and sends the lead to you by e-mail, Telegram, Slack, Microsoft Teams, Discord, Google Sheets, Notion, a CRM or a webhook. When a question needs a person, the dialog is handed over to you.

**An account at [kra.name](https://kra.name/) is required.** The chat, its knowledge base, its answers and your leads live there; this plugin connects your site to that chat with the chat's public key. Without an account and a chat set up at kra.name the plugin shows nothing to visitors.

Connect several chats to the same site, each with its own appearance, page rules, knowledge selection, catalog sync, setup wizard and statistics. For example, an AI nutritionist can use articles while a recipe finder uses recipe posts.

What you set in WordPress:

* Chats: add public keys manually or choose chats from your AI Worker account, one click per chat. A connection error is shown next to that chat.
* Look: theme, colour scheme (light, dim, dark or the visitor's system setting), every colour of the window, position of the button.
* Loading: at once, after a delay, on the first scroll or on interaction — the chat does not slow your pages down.
* Language: fixed or automatic (URL prefix, language subdomain, HTML lang, browser).
* Pages: on every page, only on the pages you list, or everywhere except them (paths like /contacts/ or /shop/*).
* Inline chat: the [aiworker_chat] shortcode puts the chat into a block of a page.
* The consultant and the chat texts in every language: greeting, quick questions, name and caption, with one-click translation into the chat's other languages.
* The AI notice under the input and the AI's greeting next to the chat button when a page opens.
* Leads: where they go (e-mail, Telegram, Slack, Microsoft Teams, Discord, Google Sheets, Notion, a CRM or a webhook), with a delivery test.
* Knowledge base: the site's posts, pages, WooCommerce products, ACF fields and text widgets are sent to the chat and kept up to date; sources (sites, pages, Google Docs, texts) and questions and answers are added and edited right in the plugin.
* Connect with one button through an AI Worker account; a setup wizard (with voice input), usage limits and statistics.
* Voice input, dialog rating and a link to your privacy policy inside the chat.
* A live preview of the chat on the settings page. Before a key is saved, click “Load live preview” to load the sample widget from kra.name; with a saved key the preview loads automatically. The connection check says exactly what to fix (key, allowed domain, finished setup, Content-Security-Policy).

When the chat is shown, the plugin merges its required sources into Content-Security-Policy headers sent by PHP. Server-level headers are outside WordPress and must be updated separately. Add the `aiworker_chat_merge_csp` filter and return `false` to turn off this automatic merge.

Paid add-ons (documents and photos from visitors, voice and others) are enabled for each chat at kra.name.

== External services ==

This plugin connects to [kra.name](https://kra.name/), a hosted AI chat service. An account and a configured chat are required.

When the chat is enabled on a page, the visitor's browser loads the chat widget from kra.name. The live preview on the Settings screen also loads `widget.js` from kra.name in the administrator's browser: it loads automatically when a chat key is saved, or after the administrator clicks “Load live preview” when there is no saved key. Messages and any contact details the visitor chooses to provide are sent to kra.name so the service can generate replies, keep the conversation and deliver leads to the site owner. If enabled for the chat, visitor voice recordings, documents, photos and other files are also sent to kra.name. The visitor's browser sends the site's address and the public chat key needed to select the configured chat.

If the site owner enables knowledge-base sync and chooses post types and fields, the plugin sends the selected published WordPress content to kra.name. This happens after the owner enables sync, when selected posts or fields change, during the scheduled refresh, or when the owner requests a full sync. If the owner also enables the product catalog (WooCommerce only), the plugin sends the published products that are visible in the shop: name, SKU, link, prices, currency, stock, categories, tags, attributes, the chosen descriptions and extra fields, the image link and the variations. Requests use the connected AI Worker account's OAuth access token; a sync token saved by an older version can remain in use until the owner removes it. Opening the plugin settings with a saved chat key, or choosing “Check again”, also contacts kra.name to check the chat and the site's allowed domain.

If the site owner connects an AI Worker account with the «Connect» button, the owner signs in at kra.name and allows access (OAuth 2.1 with PKCE; no password or API key is stored in WordPress). The plugin then stores the access and refresh tokens on the site, encrypted with the site's salts, and from the server sends to kra.name: the site's address and name when it registers the connection; the requests the owner types or dictates into the setup wizard (a voice request is sent as a short audio recording to be transcribed) and the owner's «Apply» or «Cancel» choices; the chat settings the owner changes (consultant, chat texts, the AI notice and greeting, the chat button icon, lead collection and delivery with its channel settings, knowledge mode), an image the owner uploads as the chat button icon, texts to translate, delivery tests, knowledge sources and questions and answers the owner adds, refreshes, edits or deletes; and requests for the account, the chat list, the connected channels, usage limits and statistics. When a chat is enabled, a front-end page view can schedule a background `GET /plugin/me` request at most hourly to check for a new widget version. Background requests can also refresh an expired OAuth access token and send selected knowledge-base or catalog changes to kra.name. «Disconnect» revokes the access at kra.name and deletes the tokens; the access covers only the account's online chats and personas.

For a displayed chat, the plugin automatically merges the required sources into Content-Security-Policy headers set by PHP. This behavior is on by default; the `aiworker_chat_merge_csp` filter can return `false` to disable it. A CSP sent by the web server cannot be changed by the plugin.

The service's terms are at [kra.name/offer](https://kra.name/offer/) and its privacy policy is available at [kra.name/privacy](https://kra.name/privacy/). Data handled by the service is subject to that policy.

== Installation ==

1. Register at https://kra.name/ and create a site chat in the panel (https://kra.name/app/panel). Fill its knowledge base.
2. Add this site's domain to the chat's allowed sites. www is automatic; use *.example.com to allow all subdomains.
3. In WordPress open Plugins > Add New, search for “NeedToMe AI Chat”, then install and activate it. You can also upload the plugin ZIP.
4. Open NeedToMe > AI Chat, connect your kra.name account with one button (or the chat's public `wk_` key), set the look and the loading mode, enable the chat and save. The connection check at the top shows whether everything is ready.

The public key only selects the chat; it is not a secret. The widget stays hidden on domains that are not in the chat's allowed sites.

== Frequently Asked Questions ==

= Do I need an account? =
Yes. The AI, the knowledge base and the leads work on kra.name; register there first. The plugin itself is free.

= Can I set up the chat from ChatGPT, Claude or Codex? =
Yes. Connect your kra.name account to ChatGPT (chatgpt.com/plugins, custom MCP server), claude.ai (Settings > Connectors > Add custom connector) or Codex and Claude Code (plugin, skill and MCP server) with the address https://kra.name/app/api/mcp. The assistant can then create the chat, the consultant's persona and its role, fill the knowledge base (the site, pages, documents, FAQ), write the greeting and quick questions and show the leads; each change is applied only after you agree. The steps are on the plugin's settings page and at https://kra.name/integrations/ .

= How do I put two chats above each other? =
Add both chats in step 1. Use the chat tabs below it to configure each one, enable both, and choose the same theme and side. Set “Order on the page” to 0 for the bottom chat and 1 for the one above it. The kra.name widget measures the tabs and adds a 10 px gap. Turn off “Stack chats” to use explicit offsets instead. Only one greeting teaser is selected on a page.

= Does each chat have its own knowledge base? =
Each chat has its own selection of post types, fields, translations, widgets, ACF options and WooCommerce catalog, with separate queues and sync state. The same post may be selected for several chats. Deleting, protecting or unpublishing a post queues its removal for every configured chat, including chats whose sync was disabled. Remote sources, FAQ, wizard and statistics use the selected chat. JSON catalog feeds are configured in the selected chat's kra.name panel; the WordPress catalog exporter supports WooCommerce.

= What happens to my existing settings? =
They become the first chat automatically. The migration retains the original options and pending sync work and is safe to repeat. Existing cached pages with the old single-chat markup continue to work. Removing a local chat stops its widget and future sync jobs; it does not delete the remote chat or its knowledge at kra.name. Changing a key clears that local destination's sync token, queue and state and disables sync until you enable it for the new destination.

= How do I choose an inline chat? =
Use [aiworker_chat key="wk_..."] with the full public key, or [aiworker_chat id="local-id"]. Give each inline chat its own block ID. The original [aiworker_chat] shortcode still uses the first chat.

= Will it slow my site down? =
No. The chat script loads after the page (at once, after a delay, on scroll or on interaction — you choose) and talks to kra.name directly from the visitor's browser.

= Does the plugin change Content-Security-Policy headers? =
When a chat is displayed, the plugin merges the chat's required sources into CSP headers sent by PHP. This is on by default and can be disabled with the `aiworker_chat_merge_csp` filter. Headers sent by the web server must be changed there.

== Screenshots ==

1. Connect with one button: the chat linked through AI Worker, the setup wizard (with MCP and Telegram) and the usage limits.
2. The chat's look: design, colours, colour scheme, position and offset of the button.
3. Where the chat appears, when it loads, its language, and connecting it to messengers.
4. Features: voice input, dialog rating and the privacy policy link.
5. The consultant, greeting and quick questions in every language, synced with kra.name.
6. Knowledge base: what the consultant answers from and the content sent from this site.
7. The chat's plan and paid add-ons at kra.name: visitor files, voice input, daily knowledge refresh, setup and support.
8. The chat on the site: the consultant's window with the greeting.
9. Statistics: dialogs, visitor messages, answers, leads and ratings by day, with the plan's usage.

== Changelog ==

= 1.0.0 =
* First public release.
* Several chats per site, with independent settings, content sync, statistics and stacked launchers.
* Connect the site to an AI Worker account at kra.name with one button, then choose its chat or create a new one; nothing is linked without your click.
* Set up the chat in WordPress: look, chat button icon (a ready one or your own image), position, loading, language and pages; the consultant and chat texts in every language; lead collection and delivery (e-mail, Telegram, Slack, Microsoft Teams, Discord, Google Sheets, Notion, CRM, webhook).
* Live preview of the real chat button and window, following unsaved edits.
* Knowledge base from the site: posts, pages, ACF fields, widgets and the WooCommerce catalog, kept up to date; sources and questions and answers edited in the plugin.
* Setup wizard with voice input, usage limits and statistics.
* Works under a strict Content-Security-Policy and Trusted Types; the settings page checks the policy and suggests what to allow.
