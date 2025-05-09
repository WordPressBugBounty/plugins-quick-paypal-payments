<?php
/**
 * @copyright (c) 2020.
 * @author            Alan Fuller (support@fullworks)
 * @licence           GPL V3 https://www.gnu.org/licenses/gpl-3.0.en.html
 * @link                  https://fullworks.net
 *
 * This file is part of  a Fullworks plugin.
 *
 *   This plugin is free software: you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation, either version 3 of the License, or
 *     (at your option) any later version.
 *
 *     This plugin is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *     GNU General Public License for more details.
 *
 *     You should have received a copy of the GNU General Public License
 *     along with  this plugin.  https://www.gnu.org/licenses/gpl-3.0.en.html
 */

namespace Quick_Paypal_Payments\Control;

use Fullworks_Template_Loader_Lib\BaseLoader;


require_once QUICK_PAYPAL_PAYMENTS_PLUGIN_DIR . '/vendor/alanef/fullworks-template-loader-lib/src/BaseLoader.php';;

class User_Template_Loader extends BaseLoader {

	protected $filter_prefix = 'fullworks-quick-paypal-payments';

	protected $theme_template_directory = 'fullworks-quick-paypal-payments';

	protected $plugin_directory = QUICK_PAYPAL_PAYMENTS_PLUGIN_DIR;

	protected $plugin_template_directory = 'templates';
}
