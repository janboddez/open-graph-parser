<?php
/**
 * Plugin Name: Open Graph Parser
 * Description: Parse and save link metadata.
 * Author:      Jan Boddez
 * Author URI:  https://jan.boddez.net/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Version:     0.1.0
 *
 * @package Open_Graph_Parser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-open-graph-parser.php';

$ogp = Open_Graph_Parser::get_instance();
$ogp->register();
