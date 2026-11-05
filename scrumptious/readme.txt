=== Scrumptious – Recipe Cards ===
Contributors: natebald
Tags: recipe, recipe card, food blog, schema, cooking
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paste a recipe and get a finished recipe card: ingredients, steps, times and Google recipe data, in blocks that match your theme.

== Description ==

**Paste any recipe and Scrumptious fills in the card for you:** name, times, servings, ingredients, steps and notes. No forms to fill in, no code, and it matches your theme.

**See it live:** try a real recipe card (scale it, switch to metric, turn on cook mode) on the [Scrumptious demo site](https://demo.scrumptiouswp.com/?utm_source=wporg&utm_medium=readme&utm_campaign=free).

= Easy for you =

* **Paste & Parse:** paste a recipe from anywhere (your notes, an old post, an email) and the card fills itself in. Works out of the box, no account needed.
* **Switch in a few clicks:** move your recipes from WP Recipe Maker, Tasty Recipes or Create by Mediavine. Recipes, photos, ratings and categories come across, your posts are updated, and you can undo it.
* **Type ingredients naturally:** "2 cups flour, sifted" is split into amount, unit and notes automatically.
* **Optional AI, your own key:** connect OpenRouter in one click (or Google Gemini, OpenAI, Anthropic, or WordPress's built-in AI) for messy text and for suggesting course, cuisine and diet. You pay your provider directly; nothing goes through us.

= Great for your readers =

* Servings scaling (−/+ and 1×, 2×, 3×) and US / Metric conversion.
* Tap to tick off ingredients and steps, and Cook mode to keep the screen on.
* Star ratings, a clean print page, a Jump to Recipe button, a photo gallery and share buttons (plain links, no tracking scripts).

= Ready for Google =

* Complete schema.org Recipe data: times, servings, ingredients, step-by-step instructions, nutrition, ratings, video, diets.
* Fast: styles and scripts load only on pages with a recipe. No jQuery.

= Flexible =

* Recipe Card, Jump to Recipe, Recipe Grid and Recipe Part blocks (put ingredients in one column and steps in another).
* Optional recipe pages and a recipe index. Courses, cuisines and diets.
* For developers: theme-overridable templates, PHP filters and actions, JavaScript events and a REST API. Full reference in the [developer docs](https://scrumptiouswp.com/help/developers/).

= Scrumptious Pro =

Everything above is free, with no feature limits or nag screens. When you want more, the optional paid plans add:

* **Import from a link:** bring in a recipe from another site that publishes recipe data for Google.
* **Bulk AI:** tidy up and tag all your existing recipes at once.
* **Meal planner and shopping lists:** readers save recipes to a weekly plan and get a combined shopping list.
* **Submit a Recipe (Studio and Agency):** readers send in recipes, and you approve them from a review queue.
* **Priority support** from the person who built it.

Plans start at $59 a year, with a 14-day refund. If a plan lapses, everything keeps working; only updates and support stop. [See plans](https://scrumptiouswp.com/?utm_source=wporg&utm_medium=readme&utm_campaign=free#pricing)

Full documentation: [scrumptiouswp.com/help](https://scrumptiouswp.com/help/)

== Installation ==

1. In WordPress go to **Plugins → Add New Plugin**, search for **Scrumptious**, then click **Install Now** and **Activate**.
2. A one-minute setup opens (you can skip it).
3. In a post, add the **Recipe Card** block, click **Create new recipe**, and paste your recipe.

== Frequently Asked Questions ==

= Do I need an AI account? =

No. Paste & Parse works without AI. AI is an optional upgrade that uses your own key.

= Can I switch from WP Recipe Maker, Tasty Recipes or Create? =

Yes. Go to **Recipes → Import**. Your recipes are copied (the originals stay untouched), and your posts are updated to show the new card. You can undo the post changes from the same screen.

= Where are recipes stored? =

Each recipe is its own item under **Recipes** in the admin, so you can reuse it in more than one post and it survives theme changes.

= Will it work with my theme? =

Yes. Cards use your theme's fonts, and you pick the accent colour and one of three card styles. Block themes can also design full recipe pages in the Site Editor.

= Does it slow my site down? =

No. Nothing loads on pages without a recipe, and cards use a few KB of CSS and JavaScript.

= Where do I get support? =

For the free plugin, post in the [support forum here on WordPress.org](https://wordpress.org/support/plugin/scrumptious/). I read and answer it myself. Scrumptious Pro customers get priority email support from Recipes → Support in the plugin, or Get help in [Your Account](https://scrumptiouswp.com/customer-dashboard/#help). Guides for every feature are in the [help docs](https://scrumptiouswp.com/help/).

= Can I try it before installing? =

Yes. The [demo site](https://demo.scrumptiouswp.com/?utm_source=wporg&utm_medium=readme&utm_campaign=free) shows real recipe cards, every card style and the blocks, so you can see what your readers will get.

= Is there a Pro version? =

Yes. The free plugin is complete on its own. Pro adds import from a link, bulk AI tools, a meal planner, shopping lists and priority support. Studio and Agency add reader recipe submissions and more sites. [See plans and pricing](https://scrumptiouswp.com/?utm_source=wporg&utm_medium=readme&utm_campaign=free#pricing).

== External services ==

Scrumptious works without any outside service. These optional features connect to third parties only when you turn them on or use them:

* **AI providers (optional, only after you connect one under Recipes → Settings → AI).** When you use Paste & Parse with AI or Suggest with AI, the recipe text (title, summary, ingredients, method and notes) and your site's course, cuisine and diet names are sent to the provider you chose, with your own API key. Nothing is sent to ScrumptiousWP.
  * OpenRouter (openrouter.ai): also used for the one-click "Connect OpenRouter" sign-in. [Terms](https://openrouter.ai/terms), [Privacy](https://openrouter.ai/privacy)
  * Google Gemini (generativelanguage.googleapis.com). [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy)
  * OpenAI (api.openai.com). [Terms](https://openai.com/policies/terms-of-use), [Privacy](https://openai.com/policies/privacy-policy)
  * Anthropic (api.anthropic.com). [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy)
  * WordPress AI (WordPress 7.0+): uses whatever provider your site has connected under Settings → Connectors.
* **Share buttons:** plain links to Pinterest, Facebook, X, WhatsApp and email. Nothing is loaded from those services until a reader clicks a button, which opens that service with the recipe's link.
* **Help links:** the "?" icons link to the documentation at scrumptiouswp.com. Nothing is sent unless you click one.
* **Newsletter (optional):** This plugin can send the email address you enter to scrumptiouswp.com, only if you tick "Email me recipe card tips" in the setup wizard or settings. It's used to add you to our newsletter (sent with Acumbamail). Only the email address, where you signed up (setup or settings) and the plugin version are sent; nothing about your site. [Terms](https://scrumptiouswp.com/terms/), [Privacy](https://scrumptiouswp.com/privacy-policy/)

== Privacy ==

AI keys are stored encrypted in your database and removed when the plugin is deleted.

Star ratings store the rating, the recipe, and a one-way hash of the visitor (their user ID when logged in, otherwise a salted hash of their IP address). Raw IP addresses are never stored. Ratings can be turned off under Recipes → Settings.

== Source code ==

The block editor scripts in `build/` are compiled from the human-readable source in `src/`, which is included in this plugin, using the official WordPress build tool (@wordpress/scripts). To rebuild them: run `npm install`, then `npm run build`. The front-end scripts and styles in `assets/` are not compiled or minified.

== Credits ==

Share button brand icons (Pinterest, Facebook, X, WhatsApp) are from [Simple Icons](https://simpleicons.org), CC0 1.0. The logos are trademarks of their owners and link only to their own share pages.

== Screenshots ==

1. The recipe card: photo, times, adjustable servings (1×, 2×, 3×) and a US/metric switch.
2. Compact style: ingredients beside the instructions.
3. Minimal style: no frame, for themes that already style your content.
4. The Recipe Grid block: show recipes by course, cuisine or diet.
5. On phones: big tap targets, and cook mode keeps the screen on while you cook.

== Changelog ==

= 1.1.0 =
* New: optional newsletter signup in the setup wizard and on the Settings screen. It's off unless you tick the box, and only your email is sent (see External services).
* New: after importing recipes from another plugin, a one-time "Imported N recipes" note with a link to leave a review.
* Changed: the review request now shows after your first published recipe card (once Scrumptious has been installed for 3 days), and the Pro mention after 5. Both stay dismissible, one at a time, on Scrumptious screens only.
* New: a short Black Friday notice for Scrumptious Pro, Nov 24 to Dec 2 only. Not shown if Pro is installed; one click dismisses it.

= 1.0.4 =
* Fix: star ratings update on cached pages. After a rating, Scrumptious asks LiteSpeed Cache, WP Rocket, W3 Total Cache and WP Super Cache to refresh the recipe's pages (new action `scrumptious_rating_changed` for others).
* Fix: ingredient notes in double brackets, like "((~250g each) (Note 1))" from some imported recipes, now become clean notes ("~250g each, Note 1").
* Fix: the recipe index on classic themes no longer adds a second main landmark inside the theme's own.
* Fix: the recipe editor's accent color follows your Accent color setting.
* Security: rate limits (ratings, AI requests) count reliably even when requests arrive at the same moment.

= 1.0.3 =
* Improved: metric amounts are rounded the way a cook would measure them (240 ml, not 236.6 ml; 60 ml, not 59.1 ml). Teaspoons and tablespoons stay as spoons when readers switch to metric.
* New: after you've published 5 posts with recipe cards, a one-time request to leave a review (with "Maybe later" and "Don't ask again"), and at 15 a one-time mention of Pro. Admins only, on Scrumptious screens, never both at once.
* New: small, dismissible hints in the editor where a Pro feature would help (import from a link, bulk AI). One click hides them all. Hidden once Scrumptious Pro is installed.

= 1.0.2 =
* New: a short Scrumptious Pro card on the settings screen and a "Get Pro" link on the Plugins screen, for admins only. Both are hidden once Scrumptious Pro is installed.

= 1.0.1 =
* Fix: the recipe card's Preview in the block editor now shows the card styles (it looked unstyled).

= 1.0.0 =
* New: on classic themes, the recipe index can use a Scrumptious grid (photos, ratings, times) with course and cuisine filters. Recipes → Settings → Recipe index layout. Existing sites keep their theme's layout.
* Fix: recipe pages repair themselves if another plugin or a hosting tool rebuilt permalinks without them (they returned "page not found").

= 0.11.0 =
* All stored names now use the full "scrumptious_" prefix (recipes, courses, cuisines, diets, ratings and settings data). Existing sites are moved over automatically on update; nothing to do.
* Admin and print-view styles and scripts are now proper enqueued files.
* The block editor source code is included (src/), with build instructions in this readme.

= 0.10.8 =
* Security: star ratings only trust a visitor-IP header from Cloudflare's own servers (or proxies you add with the `scrumptious_trusted_proxies` filter), so votes can't be multiplied with a faked header.
* Security: password-protected recipes stay hidden (card, print view and REST API) until the password is entered.
* Recipes created inside a post start as drafts and are published together with the post, so nothing shows before the post goes live.
* AI requests from the editor are capped per user per hour (60; administrators aren't limited; filter `scrumptious_ai_hourly_limit`).
* Fix: "WordPress AI" (Settings → Connectors) can be chosen as the AI provider again.
* The Recipes list only shows "Used in" posts you're allowed to see.

= 0.10.7 =
* Fix: on the Compact card, share buttons and other extras now use the full card width instead of squeezing into the ingredients column.

= 0.10.6 =
* New default look: Raspberry accent (#e11d74) on a white card. Buttons, step numbers and text all meet WCAG AA contrast. Sites that already chose an accent colour keep it.
* The Recipes menu in wp-admin now uses the Scrumptious cookie icon.

= 0.10.5 =
* Removed: Import from URL. The plugin no longer downloads pages from other websites. Paste & Parse works with text you paste in.
* Developers: `scrumptious.pasteParse.sources`, `scrumptious.pasteParse.intro` and `scrumptious.pasteParse.placeholder` JavaScript filters for adding input types to Paste & Parse.

= 0.10.4 =
* Developers: `scrumptious_card_actions` action for extra buttons in the card's button row (used by Scrumptious Pro's meal planner).

= 0.10.3 =
* Share buttons now sit at the bottom of the card by default, and are a little smaller. Move them back to the top (extra small) under Recipes → Settings → Sharing → Position on the card.
* Developers: `scrumptious_share_position` filter.

= 0.10.2 =
* Cards show "Recipe by …" when a recipe has an author name.
* Developers: `scrumptious_card_credit` filter and `scrumptious_card_end` action.

= 0.10.1 =
* Share buttons now use each network's logo and colour: round icon buttons by default, or icons with names (Recipes → Settings → Sharing → Button style).
* Developers: `scrumptious_share_icons` filter; each button sets `--swp-brand`.

= 0.10.0 =
* New: Suggest with AI fills in course, cuisine and diet. It reuses your existing terms (Main, Mains and main-course count as one) and only suggests a diet when every ingredient fits.
* New: Paste & Parse with AI now sets course, cuisine and diet too.
* Developers: `scrumptious_tag_prompt`, and `scrumptious_suggest_terms` filters; `POST /scrumptious/v1/suggest-terms`; `Tagger::ids()` for loose term matching.

= 0.9.0 =
* Share buttons on every card: Pin it, Facebook, X, WhatsApp, email, copy link, plus the phone's own share menu. Plain links, no tracking scripts. New Recipe Share Buttons part block.
* Setup wizard: a one-minute setup after first activation (look, reader features, optional AI). Run it again from Recipes → Setup.
* Help links: "?" icons and "Learn more" links throughout the plugin open the matching help article.
* The gallery lightbox and share buttons use a separate small script, loaded only when needed.
* Fix: Recipe Gallery part block could show a PHP notice.

= 0.8.0 =
* Recipe gallery: extra photos per recipe, shown as a grid with a tap-to-enlarge lightbox. New Recipe Gallery part block; first five photos added to Google data.
* "Adapted from" link field; sent to Google as isBasedOn.
* Diets are now sent to Google (suitableForDiet) for the diets Google recognises.
* Fix: step photos stay with their step when other steps are added or removed.

= 0.7.0 =
* Importers for WP Recipe Maker, Tasty Recipes and Create by Mediavine (Recipes → Import): ingredients and step groups, times, servings, notes, nutrition, images, step images, video, keywords, course/cuisine/diet and star ratings.
* Posts are updated to show the Scrumptious card in place of the old one (blocks, shortcodes and WP Recipe Maker's classic fallback markup), including Jump to Recipe buttons. Undo puts the original content back.
* Works when the old plugin is already deactivated. Original recipes are never changed. Running it again skips recipes already imported.
* Imported ratings are kept and new votes are added on top.
* New [scrumptious-jump] shortcode for classic content.

= 0.6.0 =
* Paste & Parse: paste a recipe from anywhere and the card fills itself in — title, summary, times, servings, ingredient and step groups, notes and nutrition. Works without AI.
* Bring your own AI: connect OpenRouter in one click, or paste a Google Gemini, OpenAI or Anthropic key. A fast, low-cost model is picked automatically (or choose your own). Test button included.
* Keys are encrypted at rest, never sent to the browser, and deleted when the plugin is deleted.

= 0.5.1 =
* SEO: with public recipe pages on, the recipe page is now the primary page — it is self-canonical and the only page that outputs recipe data. Blog posts can still show the card. Recipe Grid links and the print view point to the recipe page.
* Developers: new filters (scrumptious_recipe_data, scrumptious_card_sections, scrumptious_card_classes, scrumptious_card_html, scrumptious_reader_features, scrumptious_recipe_url, scrumptious_grid_query_args), actions before/after the card and each section, and JavaScript events (scrumptious:ready, change, cook, rated).

= 0.5.0 =
* Recipe Part blocks: Ingredients, Instructions, Times & Servings, Rating, Nutrition, Notes, Summary and Buttons — build your own recipe layouts anywhere.
* Recipe Grid block: filter by course, cuisine or diet; order by newest, highest rated, A–Z or random.
* Recipe page and Recipe Index templates are now built from these blocks, so you can rearrange them in the Site Editor.
* SEO: recipe data is output only once per recipe.

= 0.4.0 =
* Star ratings: readers rate right on the card (one vote per visitor, rate-limited); the average feeds Google recipe data.
* Servings scaling (− / + and 1×, 2×, 3×) with kitchen-friendly fractions.
* US / Metric conversion.
* Cook mode keeps the screen awake; tap ingredients and steps to check them off.
* All reader features can be switched on/off in Settings. One ~4.6 KB script, loaded only where a recipe appears.

= 0.3.0 =
* Per-block "Card display" options: style (full, compact, minimal), show/hide each section, print button — overriding the site defaults.
* New Jump to Recipe block (jump + print buttons, custom label), plus an optional setting to add it automatically.
* Clean print view (no theme or ads, noindex) with image/notes/nutrition toggles.
* Settings: default card style, print button, auto Jump to Recipe.

= 0.2.2 =
* Fix: ingredient and instruction groups no longer disappear or lose text when editing a new recipe inside a post.
* Course, cuisine and diet pickers in the Recipe Card block sidebar.

= 0.2.1 =
* Shortcode column removed (the [scrumptious] shortcode still works as a fallback).
* "View" links for recipes: row actions and a "Where this recipe appears" panel link to the posts that show the recipe (and its own page when public pages are on).

= 0.2.0 =
* Full recipe editor on the Recipes screen (same editor as the block).
* Settings page: accent color, card sections, optional public recipe pages with index, comments on recipe pages, schema on/off, delete data on uninstall.
* Recipes list: image, total time, "used in" posts and shortcode columns.

= 0.1.0 =
* First development build: recipe post type, Recipe Card block, ingredient parser, Recipe JSON-LD.
