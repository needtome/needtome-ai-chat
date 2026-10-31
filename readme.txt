=== NeedToMe AI Chat for Your Site ===
Contributors: needtome
Donate link: https://www.donationalerts.com/r/needtome
Tags: ai chat, chatbot, live chat, lead generation, customer support
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.10.2
Tested up to: 7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI consultant that answers visitors from your knowledge base, speaks their language and collects leads. Requires a kra.name account.

== Description ==

NeedToMe AI Chat puts an AI consultant on your WordPress site. It answers visitors around the clock from what you teach it: the pages of your site, documents, price lists and your own answers. It speaks the visitor's language, asks for a name, phone or e-mail when a visitor wants to order or be called back, and sends the lead to you by e-mail, Telegram, Slack, Microsoft Teams, Discord, Google Sheets, Notion, a CRM or a webhook. When a question needs a person, the dialog is handed over to you.

**An account at [kra.name](https://kra.name/) is required.** The chat, its knowledge base, its answers and your leads live there; this plugin connects your site to that chat with the chat's public key. Without an account and a chat set up at kra.name the plugin shows nothing to visitors.

What you set in WordPress:

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
* A live preview of the chat on the settings page (it works before you have a key) and a connection check that says exactly what to fix (key, allowed domain, finished setup, Content-Security-Policy).

Paid add-ons (documents and photos from visitors, voice and others) are enabled for each chat at kra.name.

== External services ==

This plugin connects to [kra.name](https://kra.name/), a hosted AI chat service. An account and a configured chat are required.

When the chat is enabled on a page, the visitor's browser loads the chat widget from kra.name. Messages and any contact details the visitor chooses to provide are sent to kra.name so the service can generate replies, keep the conversation and deliver leads to the site owner. The visitor's browser also sends the site's address and the public chat key needed to select the configured chat.

If the site owner enables knowledge-base sync and chooses post types and fields, the plugin sends the selected published WordPress content to kra.name. This happens after the owner enables sync, when selected posts change, during the scheduled refresh, or when the owner requests a full sync. If the owner also enables the product catalog (WooCommerce only), the plugin sends the published products that are visible in the shop: name, SKU, link, prices, currency, stock, categories, tags, attributes, the chosen descriptions and extra fields, the image link and the variations. A private sync token authorizes these requests. Opening the plugin settings with a saved chat key, or choosing “Check again”, also contacts kra.name to check the chat and the site's allowed domain.

If the site owner connects an AI Worker account with the «Connect» button, the owner signs in at kra.name and allows access (OAuth 2.1 with PKCE; no password or API key is stored in WordPress). The plugin then stores the access and refresh tokens on the site, encrypted with the site's salts, and from the server sends to kra.name: the site's address and name when it registers the connection; the requests the owner types or dictates into the setup wizard (a voice request is sent as a short audio recording to be transcribed) and the owner's «Apply» or «Cancel» choices; the chat settings the owner changes (consultant, chat texts, the AI notice and greeting, lead collection and delivery with its channel settings, knowledge mode), texts to translate, delivery tests, knowledge sources and questions and answers the owner adds, refreshes, edits or deletes; and requests for the account, the chat list, the connected channels, usage limits and statistics. This happens only when an administrator opens the plugin's settings screens or uses these controls. «Disconnect» revokes the access at kra.name and deletes the tokens; the access covers only the account's online chats and personas.

The service's terms are at [kra.name/offer](https://kra.name/offer/) and its privacy policy is available at [kra.name/privacy](https://kra.name/privacy/). Data handled by the service is subject to that policy.

== Installation ==

1. Register at https://kra.name/ and create a site chat in the panel (https://kra.name/app/panel). Fill its knowledge base.
2. Add this site's domain to the chat's allowed sites. www is automatic; use *.example.com to allow all subdomains.
3. In WordPress open Plugins > Add New, search for “NeedToMe AI Chat for Your Site”, then install and activate it. You can also upload the plugin ZIP.
4. Open NeedToMe > AI Chat, connect your kra.name account with one button (or the chat's public `wk_` key), set the look and the loading mode, enable the chat and save. The connection check at the top shows whether everything is ready.

The public key only selects the chat; it is not a secret. The widget stays hidden on domains that are not in the chat's allowed sites.

== Frequently Asked Questions ==

= Do I need an account? =
Yes. The AI, the knowledge base and the leads work on kra.name; register there first. The plugin itself is free.

= Can I set up the chat from ChatGPT, Claude or Codex? =
Yes. Connect your kra.name account to ChatGPT (chatgpt.com/plugins, custom MCP server), claude.ai (Settings > Connectors > Add custom connector) or Codex and Claude Code (plugin, skill and MCP server) with the address https://kra.name/app/api/mcp. The assistant can then create the chat, the consultant's persona and its role, fill the knowledge base (the site, pages, documents, FAQ), write the greeting and quick questions and show the leads; each change is applied only after you agree. The steps are on the plugin's settings page and at https://kra.name/integrations/ .

= Will it slow my site down? =
No. The chat script loads after the page (at once, after a delay, on scroll or on interaction — you choose) and talks to kra.name directly from the visitor's browser.

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

= 0.10.2 =
* Connect with one button through an AI Worker account; with one chat it is linked at once. The account, the plan's usage limits and the way to payment are shown on the settings screen.
* The consultant, the chat texts in every language (greeting, quick questions, name, caption, side tab label), the AI notice, the greeting on page open, lead collection and lead delivery (e-mail, Telegram, Slack, Microsoft Teams, Discord, Google Sheets, Notion, CRM, webhook) are set right in the plugin and saved to kra.name with the one «Save settings» button. Empty texts can be translated from another language in one click.
* Knowledge base tab: what the consultant answers from, its sources (sites, pages, Google Docs, texts) and its questions and answers can be added, refreshed, edited and deleted in the plugin.
* The setup wizard with voice input, the MCP and Telegram connection steps, and statistics; the wizard card folds once everything is connected.
* The live preview shows the chat's own greeting and quick questions and follows unsaved edits of them.
* The blocks read from kra.name load after the page in one request, so the settings screen opens at once.
* A Content-Security-Policy that the theme or another plugin sends from PHP now gets the chat's sources by itself (and, under 'strict-dynamic', the loader tag gets the policy's nonce): nothing to add by hand. Only a policy set by the web server still needs them added there; the settings page checks it only while the chat is shown.
* Product catalog for WooCommerce shops: products with prices, stock, attributes and variations go to the chat's catalog at kra.name, so the consultant can pick products by exact characteristics. Stock and price changes from orders are sent too.
* Add translated “Other plugins” and “Website Development” tabs to the settings screen, including links to the author's other plugins, portfolio and resume.
* Page rules work on PHP installations without the optional mbstring extension.
* Page rules keep matching addresses in any alphabet: the visited path is read without stripping its percent-encoding (for example /%D0%BA%D0%BE%D0%BD%D1%82%D0%B0%D0%BA%D1%82%D1%8B/ for /контакты/), and an unencoded UTF-8 path is no longer garbled.
* Improve escaping of settings controls and the live preview configuration.
* Use the WordPress language-pack directory for admin translations.
* The chat starts under a strict Content-Security-Policy: its options ride on the loader's own script tag instead of an inline script, so no 'unsafe-inline' is needed (a policy with 'strict-dynamic' still needs its nonce on the loader tag, and the check says so). The CSP check follows the browser's fallbacks (script-src-elem, script-src, default-src), checks the loader's own origin, path-limited sources, every CSP header and 'strict-dynamic', and suggests each directive in full, ready to replace the existing one.
* Saving the settings while kra.name cannot be reached keeps the chat and voice input as they were instead of turning them off.
* The wizard keeps its connection when kra.name is temporarily unreachable during a token refresh, and a disconnect during a refresh no longer leaves the site connected, including one that runs while a new connection finishes.
* Knowledge sync reports posts longer than the knowledge base takes instead of silently dropping their tail.

= 0.10.1 =
* Page-path matching ignores letter case.

= 0.10.0 =
* The live preview is the real chat button in the corner of the settings page, with the greeting next to it, as visitors see it; the window opens on a click. Every change appears at once.
* Button offset in pixels for this site (sideways, up / down); empty keeps the chat's own offset at kra.name.

= 0.9.0 =
* The settings page is in English and translated into Russian and Ukrainian.
* A clearer settings page: the connection with the public key in one highlighted block, numbered sections, a sticky save bar.
* The live preview stands next to the form with the chat window open, and works without a key (a sample consultant).
* «Show the chat on this site» can be switched on only with a working key.
* Voice input is shown as a paid add-on and stays off when the chat's plan does not include it.
* New: show the chat on every page, only on listed pages, or everywhere except them.

= 0.8.0 =
* New name: NeedToMe AI Chat for Your Site. Author and plugin links on the Plugins screen; a description of what the chat does and that an account at kra.name is required.

= 0.7.0 =
* Inline chat: the «Встроенный в страницу» design puts the chat into a block of the page — the [aiworker_chat] shortcode (or any element with the configured id), with its own height; pages without the block keep the floating button.

= 0.6.0 =
* Live preview on the settings page: the chat button in the corner shows the current look with the chat's consultant, and every change is applied at once, before saving. Messages from the preview are not sent anywhere.

= 0.5.0 =
* Colour scheme of the chat window: light, dim, dark or following the visitor's system setting.
* Advanced colours: header, background, window, text, borders, visitor and assistant bubbles, input — each can be changed, empty means the scheme's colour.

= 0.4.0 =
* Dialog rating (1–5 stars) and the privacy policy link.
* Connection check on the settings page, including the site's Content-Security-Policy: it lists what to allow (script-src, connect-src, img-src and the Trusted Types policies aiworker-chat, aiworker-chat-loader). Themes and security plugins can merge aiworker_chat_csp_sources().
* Works on sites that enforce Trusted Types.
* Widget URL carries a daily version so visitors get updates; Settings link on the Plugins screen; settings are removed on uninstall.
