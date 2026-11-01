<?php
/**
 * Shared NeedToMe blocks of the plugin admin screens: the «NeedToMe» admin menu with the overview of our plugins, the
 * support box and the «Other plugins» and «Website Development» tabs, with the contacts column. The same in every
 * NeedToMe plugin.
 *
 * GENERATED from demo.loc/shared/needtome-profile/profile.php.tpl by sync.py. Edit the template and run sync.py;
 * a change made here is overwritten. Only the class name and the text domain differ between the plugins.
 */

if (! defined('ABSPATH')) exit;

if (! class_exists('Aiworker_Chat_Profile')) {
    final class Aiworker_Chat_Profile {

        /**
         * Our released plugins, in the order shown. A plugin not yet released is not listed.
         *
         * @return array<string, array{name: string, text: string, demo: string, wporg: string, zip: string, page: string}>
         */
        private static function plugins(): array {
            return [
                'nohackme-defender' => ['name' => 'NoHackMe Defender', 'text' => __('Protects WordPress from suspicious requests, brute-force logins, scraping and request floods.', 'needtome-ai-chat'),
                    'demo' => 'https://needtome.com/nohackme/', 'wporg' => 'https://wordpress.org/plugins/nohackme-defender/', 'zip' => '', 'page' => 'nohackme_defender_parent'],
                'needtome-phone-number-switcher' => ['name' => 'NeedToMe Phone Number Switcher', 'text' => __('Shows a separate phone number for each advertising campaign and updates click-to-call links.', 'needtome-ai-chat'),
                    'demo' => 'https://needtome.com/needtome-phone-number-switcher/', 'wporg' => 'https://wordpress.org/plugins/needtome-phone-number-switcher/', 'zip' => '', 'page' => 'needtome-phone-number-switcher'],
                'needtome-ai-chat' => ['name' => 'NeedToMe AI Chat', 'text' => __('AI chat for your website, connected to AI Worker (kra.name).', 'needtome-ai-chat'),
                    'demo' => 'https://needtome.com/needtome-ai-chat/', 'wporg' => '', 'zip' => 'https://kra.name/app/needtome-ai-chat.zip', 'page' => 'aiworker-chat'],
            ];
        }

        /** Selected projects: name, site, address and the sentence about it. */
        private static function projects(): array {
            return [
                ['Gloap', 'gloap.net', 'https://gloap.net/en/', __('A crewing platform with 200,000+ resumes, vacancies, response tools, instant messaging, bulk mailings, Telegram bots, Asterisk calling, and PDF/Word document generation. PageSpeed Mobile reached 100.', 'needtome-ai-chat')],
                ['AI Worker', 'kra.name', 'https://kra.name/', __('AI employees for business: website chats, customer messaging and automation.', 'needtome-ai-chat')],
                ['Obuvnov', 'obuvnov.ru', 'https://obuvnov.ru/', __('A product catalog that processes more than 50 million products from supplier feeds, with city-specific pages and PageSpeed Mobile 100.', 'needtome-ai-chat')],
                ['O-Keto', 'o-keto.com', 'https://o-keto.com/en/', __('A WordPress and WooCommerce platform for recipes, products, nutrients, quizzes, digital books, multilingual publishing, and ecommerce.', 'needtome-ai-chat')],
                ['ProTradeJournal', 'protradejournal.net', 'https://protradejournal.net/', __('Browser and desktop trading tools with real-time charts, demo trading, portfolio tracking, and a historical-data testing platform.', 'needtome-ai-chat')],
                ['TenderClip', 'tenderclip.com', 'https://tenderclip.com/', __('A multilingual WordPress media platform for animated cards and short videos, with subscriptions, a browser editor, and automated publishing. PageSpeed reached 100 on mobile and desktop.', 'needtome-ai-chat')],
            ];
        }

        /** Slug of the shared «NeedToMe» admin menu; its pages are at admin.php?page=<slug>. */
        const MENU = 'needtome';

        /**
         * Adds a plugin's page to the shared «NeedToMe» menu near the top of the admin menu. The first NeedToMe plugin
         * that runs creates the menu with the overview of our plugins; the others only add their pages. Call it from
         * admin_menu. Returns the page's hook suffix (needtome_page_<slug>).
         */
        public static function menu(string $title, string $slug, callable $callback, string $capability = 'manage_options', string $menuTitle = ''): string {
            global $admin_page_hooks;
            if (empty($admin_page_hooks[self::MENU])) {
                $icon = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 2h4l6 9V2h4v16h-4L7 9v9H3z"/></svg>');
                add_menu_page('NeedToMe', 'NeedToMe', 'manage_options', self::MENU, [self::class, 'menu_page'], $icon, '25.1');
                add_submenu_page(self::MENU, 'NeedToMe', __('All plugins', 'needtome-ai-chat'), 'manage_options', self::MENU, [self::class, 'menu_page']);
            }
            if (! has_action('admin_page_access_denied', [self::class, 'old_url'])) add_action('admin_page_access_denied', [self::class, 'old_url']);
            return (string) add_submenu_page(self::MENU, $title, $menuTitle !== '' ? $menuTitle : $title, $capability, $slug, $callback);
        }

        /** The address of a page in the NeedToMe menu, with extra query arguments. */
        public static function url(string $slug, array $args = []): string {
            return add_query_arg(['page' => $slug] + $args, admin_url('admin.php'));
        }

        /** A bookmark from before the move (options-general.php?page=<slug>) opens the page in the NeedToMe menu. */
        public static function old_url(): void {
            global $pagenow, $_registered_pages;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect of an old address.
            $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            if ($pagenow !== 'options-general.php' || $page === '' || empty($_registered_pages[get_plugin_page_hookname($page, self::MENU)])) return;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The same read-only query is carried over.
            wp_safe_redirect(add_query_arg(array_map('rawurlencode', wp_unslash($_GET)), admin_url('admin.php')));
            exit;
        }

        /** The «NeedToMe» page: our plugins, whether each is active here, and where its settings are. */
        public static function menu_page(): void {
            global $_registered_pages;
            self::styles();
            $installed = function_exists('get_plugins') ? get_plugins() : [];
            echo '<div class="wrap"><h1>NeedToMe</h1><div class="ntm-profile"><aside class="ntm-profile__contacts">';
            self::contacts();
            echo '</aside><div class="ntm-profile__content">';
            ?>
            <h2><?php esc_html_e('Our WordPress plugins', 'needtome-ai-chat'); ?></h2>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Plugin', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('What it does', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('On this site', 'needtome-ai-chat'); ?></th></tr></thead>
              <tbody>
                <?php foreach (self::plugins() as $slug => $p) : $file = $slug.'/'.$slug.'.php'; ?>
                  <tr>
                    <td><strong><?php echo esc_html($p['name']); ?></strong></td>
                    <td><?php echo esc_html($p['text']); ?></td>
                    <td><?php if (is_plugin_active($file) && ! empty($_registered_pages[get_plugin_page_hookname($p['page'], self::MENU)])) : ?>
                        <a class="button" href="<?php echo esc_url(self::url($p['page'])); ?>"><?php esc_html_e('Settings', 'needtome-ai-chat'); ?></a>
                      <?php elseif (isset($installed[$file])) : ?>
                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php esc_html_e('Installed, not active', 'needtome-ai-chat'); ?></a>
                      <?php else : ?>
                        <a href="<?php echo esc_url($p['demo']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Demo', 'needtome-ai-chat'); ?></a> ·
                        <?php if ($p['wporg'] !== '') : ?><a href="<?php echo esc_url($p['wporg']); ?>" target="_blank" rel="noopener noreferrer">WordPress.org</a>
                        <?php else : ?><a href="<?php echo esc_url($p['zip']); ?>"><?php esc_html_e('Download ZIP', 'needtome-ai-chat'); ?></a><?php endif; ?>
                      <?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php
            echo '</div></div></div>';
        }

        /** The labels of the two profile tabs, for the plugin's tab bar: 'plugins' and 'development'. */
        public static function tab_labels(): array {
            return ['plugins' => __('Other plugins', 'needtome-ai-chat'), 'development' => __('Website Development', 'needtome-ai-chat')];
        }

        /**
         * «Support the development of the plugin», under the settings.
         *
         * @param string $name The plugin's name as shown to the owner.
         */
        public static function support_box(string $name): void {
            self::styles();
            $links = [
                ['DonationAlerts', 'https://www.donationalerts.com/r/needtome', __('My current donation page.', 'needtome-ai-chat')],
                ['Patreon', 'https://patreon.com/needtome', __('Best known for recurring support and membership tiers.', 'needtome-ai-chat')],
            ];
            ?>
            <div class="ntm-support">
              <div class="ntm-support__hero">
                <div class="ntm-support__visual"><svg class="ntm-support__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="#74c0fc" d="M320 48c-13 0-24 11-24 24v12h-2a66 66 0 0 0-8 132l61 8q8 1 9 10-1 9-10 10h-74a28 28 0 1 0 0 56h24v12a24 24 0 1 0 48 0v-12h2a66 66 0 0 0 8-132l-61-7q-8-2-9-11 1-9 10-10h66a28 28 0 1 0 0-56h-16V72c0-13-11-24-24-24M141 406l-42 42H64c-18 0-32 14-32 32v64c0 18 14 32 32 32h321q45 0 80-26l127-94a40 40 0 1 0-48-64l-119 88H312a24 24 0 1 1 0-48h72a32 32 0 1 0 0-64H232c-34 0-67 14-91 38"/></svg></div>
                <div>
                  <p class="ntm-support__title"><?php esc_html_e('Support the development of the plugin', 'needtome-ai-chat'); ?></p>
                  <p class="ntm-support__text"><?php
                    /* translators: %s: plugin name */
                    echo esc_html(sprintf(__('If %s helps your site, you can support its further development and maintenance via the services below.', 'needtome-ai-chat'), $name)); ?></p>
                </div>
              </div>
              <ul>
                <?php foreach ($links as $l) : ?>
                  <li><a href="<?php echo esc_url($l[1]); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($l[0]); ?></a> — <?php echo esc_html($l[2]); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php
        }

        /**
         * A profile tab: 'plugins' (our other plugins) or 'development' (custom development), with the contacts column.
         *
         * @param string $tab     'plugins' or 'development'.
         * @param string $current The slug of the plugin showing the tab: it is left out of «Other plugins».
         */
        public static function tab(string $tab, string $current): void {
            self::styles();
            echo '<div class="ntm-profile"><aside class="ntm-profile__contacts">';
            self::contacts();
            echo '</aside><div class="ntm-profile__content">';
            if ($tab === 'plugins') self::plugins_tab($current); else self::development_tab();
            echo '</div></div>';
        }

        private static function plugins_tab(string $current): void {
            ?>
            <h2><?php esc_html_e('Other WordPress plugins', 'needtome-ai-chat'); ?></h2>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Plugin', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('What it does', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('Links', 'needtome-ai-chat'); ?></th></tr></thead>
              <tbody>
                <?php foreach (self::plugins() as $slug => $p) : if ($slug === $current) continue; ?>
                  <tr>
                    <td><strong><?php echo esc_html($p['name']); ?></strong></td>
                    <td><?php echo esc_html($p['text']); ?></td>
                    <td><a href="<?php echo esc_url($p['demo']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Demo', 'needtome-ai-chat'); ?></a> ·
                      <?php if ($p['wporg'] !== '') : ?><a href="<?php echo esc_url($p['wporg']); ?>" target="_blank" rel="noopener noreferrer">WordPress.org</a>
                      <?php else : ?><a href="<?php echo esc_url($p['zip']); ?>"><?php esc_html_e('Download ZIP', 'needtome-ai-chat'); ?></a><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php
        }

        private static function development_tab(): void {
            ?>
            <h2><?php esc_html_e('Website Development', 'needtome-ai-chat'); ?></h2>
            <p class="ntm-profile__lead"><?php esc_html_e('Senior web developer with 15+ years in commercial development and 400+ delivered or optimized websites and platforms. I build high-performance WordPress products, custom business systems, large catalogs, marketplaces, trading tools, and automation workflows. I have published 13 technical books and work in production with full-stack JavaScript, Python/FastAPI, Node.js automation, Asterisk, parsers, bots, and performance engineering.', 'needtome-ai-chat'); ?></p>
            <p class="description"><?php esc_html_e('Originally from Ukraine; based in Chișinău, Moldova.', 'needtome-ai-chat'); ?></p>
            <p class="ntm-profile__rate"><strong><?php esc_html_e('Custom website and plugin development', 'needtome-ai-chat'); ?>:</strong> <span><?php esc_html_e('$30/hour', 'needtome-ai-chat'); ?></span></p>

            <h3><?php esc_html_e('What I can build', 'needtome-ai-chat'); ?></h3>
            <ul class="ntm-profile__services">
              <li><?php esc_html_e('WordPress and WooCommerce websites, plugins, custom themes, and integrations.', 'needtome-ai-chat'); ?></li>
              <li><?php esc_html_e('Custom business systems, marketplaces, catalogs, aggregators, and data import pipelines.', 'needtome-ai-chat'); ?></li>
              <li><?php esc_html_e('Performance optimization, PageSpeed, technical SEO, and responsive interfaces.', 'needtome-ai-chat'); ?></li>
              <li><?php esc_html_e('Automation and integrations with PHP, JavaScript, Node.js, Python/FastAPI, APIs, CRM systems, Telegram, and Asterisk.', 'needtome-ai-chat'); ?></li>
              <li><?php esc_html_e('AI-assisted workflows, parsers, bots, PDF and Word generation, and internal tools.', 'needtome-ai-chat'); ?></li>
            </ul>

            <h3><?php esc_html_e('Selected projects', 'needtome-ai-chat'); ?></h3>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Project', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('What it is', 'needtome-ai-chat'); ?></th><th><?php esc_html_e('Site', 'needtome-ai-chat'); ?></th></tr></thead>
              <tbody>
                <?php foreach (self::projects() as $p) : ?>
                  <tr><td><strong><?php echo esc_html($p[0]); ?></strong></td><td><?php echo esc_html($p[3]); ?></td><td><a href="<?php echo esc_url($p[2]); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($p[1]); ?></a></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <p><?php esc_html_e('The resume includes additional projects, technical background, and work history.', 'needtome-ai-chat'); ?></p>
            <ul>
              <li><a href="https://docs.google.com/document/d/11w_tmtQbJ7ebcQHQi7jE9Y5WClfVHyMtxbR3-GW3b-s/edit?usp=sharing" target="_blank" rel="noopener noreferrer"><?php esc_html_e('WordPress and full-stack development resume', 'needtome-ai-chat'); ?></a></li>
              <li><a href="https://docs.google.com/document/d/1tbHaxGPeN9je1qozgkQcX3djzaTrpR_MLvghjqMAuFM/edit?usp=sharing" target="_blank" rel="noopener noreferrer"><?php esc_html_e('AI and automation resume', 'needtome-ai-chat'); ?></a></li>
              <li><a href="https://needtome.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Portfolio and contact page', 'needtome-ai-chat'); ?></a></li>
            </ul>
            <?php
        }

        private static function contacts(): void {
            $contacts = [
                [__('Email', 'needtome-ai-chat'), 'paraz0n3@gmail.com', 'mailto:paraz0n3@gmail.com'],
                [__('LinkedIn', 'needtome-ai-chat'), 'Roman Klymenko', 'https://www.linkedin.com/in/roman-klymenko-parad0x/'],
                [__('Viber', 'needtome-ai-chat'), '+380935523587', 'viber://chat?number=%2B380935523587'],
                [__('Telegram', 'needtome-ai-chat'), 'klymenko_roman', 'https://t.me/klymenko_roman'],
            ];
            foreach ($contacts as $c) {
                printf('<a href="%1$s" class="ntm-profile__contact"%2$s><strong>%3$s:</strong><br>%4$s</a>',
                    esc_url($c[2], ['http', 'https', 'mailto', 'viber']),
                    strpos($c[2], 'https://') === 0 ? ' target="_blank" rel="noopener noreferrer"' : '',
                    esc_html($c[0]), esc_html($c[1]));
            }
        }

        /** The styles, once per screen (several NeedToMe plugins share the class names). */
        private static function styles(): void {
            static $done = false;
            if ($done || did_action('ntm_profile_styles')) return;
            $done = true;
            do_action('ntm_profile_styles'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- One hook shared by every NeedToMe plugin, so their common styles print once.
            ?>
            <?php ob_start(); ?>
            .ntm-support{position:relative;overflow:hidden;margin-top:24px;padding:13px;background:linear-gradient(135deg,#fff8d9 0%,#fff3b0 46%,#e8f5ff 100%);border:1px solid #f0cc58;border-radius:16px;box-shadow:0 10px 24px rgba(188,155,48,.15)}
            .ntm-support__hero{display:flex;align-items:center;gap:22px;margin-bottom:12px}
            .ntm-support__visual{flex:0 0 124px;width:124px;height:124px;display:flex;align-items:center;justify-content:center;border-radius:28px;background:radial-gradient(circle at 30% 30%,#fff 0%,#eff9ff 48%,#d8eeff 100%);box-shadow:0 16px 30px rgba(80,141,199,.22)}
            .ntm-support__icon{width:88px;height:88px;display:block;filter:drop-shadow(0 10px 14px rgba(60,130,190,.24));animation:ntmSupportFloat 3.6s ease-in-out infinite}
            .ntm-support__title{margin:0 0 8px;font-size:18px;line-height:1.3;font-weight:700;color:#443400}
            .ntm-support__text{margin:0;max-width:780px;font-size:14px;line-height:1.55;color:#5e5126}
            .ntm-support ul{margin:0}
            @keyframes ntmSupportFloat{0%,100%{transform:translateY(0) rotate(0) scale(1)}50%{transform:translateY(-8px) rotate(-4deg) scale(1.04)}}
            @media (prefers-reduced-motion:reduce){.ntm-support__icon{animation:none}}
            .ntm-profile{display:flex;max-width:1200px;margin:21px 0 0;align-items:flex-start;gap:20px}
            .ntm-profile__contacts{flex:0 0 151px;padding:3px 10px;box-shadow:0 0 10px rgba(0,0,0,.1);background:#fff}
            .ntm-profile__contact{display:block;margin:7px 0;color:#333;text-decoration:none}
            .ntm-profile__content{flex:1;min-width:0}
            .ntm-profile__content>h2:first-child{margin-top:0}
            .ntm-profile__lead{max-width:900px;font-size:15px;line-height:1.65}
            .ntm-profile__rate{max-width:900px;margin:16px 0 24px;padding:13px 16px;border-left:4px solid #2271b1;background:#f0f6fc;font-size:15px}
            .ntm-profile__rate span{font-weight:700}
            .ntm-profile__services{max-width:1000px;columns:2;column-gap:36px}
            .ntm-profile__services li{margin:0 0 9px;break-inside:avoid}
            .ntm-profile table.widefat{max-width:1100px}
            @media screen and (max-width:700px){.ntm-support__hero{flex-direction:column;align-items:flex-start;gap:14px}.ntm-support__visual{width:108px;height:108px;flex-basis:108px}.ntm-support__icon{width:76px;height:76px}.ntm-profile__services{columns:1}}
            @media screen and (max-width:500px){.ntm-profile{flex-direction:column}.ntm-profile__contacts{flex:1 0 auto;width:100%;box-sizing:border-box;box-shadow:none}}
            <?php
            // through the WordPress style API (a registered handle with inline CSS), not a hand-written <style> tag
            wp_register_style('ntm-profile', false, [], '1');
            wp_add_inline_style('ntm-profile', trim(wp_strip_all_tags((string) ob_get_clean())));
            wp_print_styles('ntm-profile');
        }
    }
}
