<?php
/**
 * Plugin Name:       Scrumptious – Recipe Cards
 * Plugin URI:        https://scrumptiouswp.com
 * Description:       Paste a recipe and get a finished recipe card: ingredients, steps, times and Google recipe data, in blocks that match your theme.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            ScrumptiousWP (465 Media)
 * Author URI:        https://465-media.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scrumptious
 *
 * @package Scrumptious
 */

defined( 'ABSPATH' ) || exit;

define( 'SCRUMPTIOUS_VERSION', '1.1.0' );
define( 'SCRUMPTIOUS_FILE', __FILE__ );
define( 'SCRUMPTIOUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCRUMPTIOUS_URL', plugin_dir_url( __FILE__ ) );

require_once SCRUMPTIOUS_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Scrumptious\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Scrumptious\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Scrumptious\\Plugin', 'instance' ) );
