<?php

/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

class MAGESHBL_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

        $this->addAdminPages();
        $this->addAjaxHandlers();
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in MAGESHBL_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The MAGESHBL_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/plugin-name-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in MAGESHBL_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The MAGESHBL_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/plugin-name-admin.js', array( 'jquery' ), $this->version, false );

	}

    protected function addAjaxHandlers()
    {
        function mageshbl_magefan_shopifyblogexport_data_extractor() {

            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/export.php';

            $mageshbl_nonce = isset($_GET['mageshbl_nonce']) ? sanitize_text_field(wp_unslash($_GET['mageshbl_nonce'])) : '';
            if (!wp_verify_nonce($mageshbl_nonce, 'magefan_export_action')) {
                wp_send_json_error('Invalid nonce.');
            }
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['error' => 'No permission']);
            }
            $export = new MAGESHBL_Export();
            $entity = isset($_GET['entity']) ? sanitize_text_field(wp_unslash($_GET['entity'])) : '';
            $entitiesLimit = isset($_GET['entitiesLimit']) ? (int) absint(wp_unslash($_GET['entitiesLimit'])) : 0;
            $export->setEntitiesLimit($entitiesLimit);
            $offSet = isset($_GET['offset']) ? (int) absint(wp_unslash($_GET['offset'])) : 1;
            $allIds = isset($_GET['allIds']);


            switch($entity) {
                case 'category':
                    if ($allIds) {
                        $preparedData = $export->getCategoryIds();
                    }
                    else {
                        $preparedData = $export->getCategories($offSet);
                    }
                    break;
                case 'tag':
                    if ($allIds) {
                        $preparedData = $export->getTagIds();
                    }
                    else {
                        $preparedData = $export->getTags($offSet);
                    }
                    break;
                case 'post':
                    if ($allIds) {
                        $preparedData = $export->getPostIds();
                    }
                    else {
                        $preparedData = $export->getPosts($offSet);
                    }
                    break;
                case 'comment':
                    if ($allIds) {
                        $preparedData = $export->getCommentIds();
                    }
                    else {
                        $preparedData = $export->getComments($offSet);
                    }
                    break;
                case 'author':
                    if ($allIds) {
                        $preparedData = $export->getAuthorIds();
                    }
                    else {
                        $preparedData = $export->getAuthors($offSet);
                    }
                    break;
                case 'media_post':
                    if ($allIds) {
                        $preparedData = $export->getPostMediaPathsNumber();
                    }
                    else {
                        $preparedData = $export->getPostMediaPaths($offSet);
                    }
                    break;
                case 'media_author':
                    if ($allIds) {
                        $preparedData = $export->getAuthorMediaPathsNumber();
                    }
                    else {
                        $preparedData = $export->getAuthorMediaPaths($offSet);
                    }
                    break;
            }


            // Send JSON response
            wp_send_json_success($preparedData);

        }

        add_action('wp_ajax_magefan_shopifyblogexport_data_extractor', 'mageshbl_magefan_shopifyblogexport_data_extractor');

        function mageshbl_magefan_shopifyblogexport_push_data_to_shopify()
        {
            $mageshbl_nonce = isset($_POST['mageshbl_nonce']) ? sanitize_text_field(wp_unslash($_POST['mageshbl_nonce'])) : '';
            if (!wp_verify_nonce($mageshbl_nonce, 'magefan_export_action')) {
                wp_send_json_error('Invalid nonce.');
            }
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['error' => 'No permission']);
            }
            $entity = isset($_POST['entity']) ? sanitize_text_field(wp_unslash($_POST['entity'])) : '';
            $shopifyUrl = isset($_POST['shopifyUrl']) ? esc_url_raw(wp_unslash($_POST['shopifyUrl'])) : '';
            $data = isset($_POST['data']) ? sanitize_text_field(wp_unslash($_POST['data'])) : '';

            if (!$entity
                || !$shopifyUrl
                || !$data) {
                wp_send_json_error(['error' => 'Some data is missing']);
            }

            // if data will contain json - it will make it invalid "\.
            //$data = stripslashes($data);

            if (in_array($entity, ['media_post', 'media_author'])) {
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/shopify-media-pusher.php';
                $Mageshbl_ShopifyMediaPusher = new Mageshbl_ShopifyMediaPusher;

                $status = $Mageshbl_ShopifyMediaPusher->execute($shopifyUrl, $data, $entity);
            }
            else {
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/shopify-pusher.php';
                $MAGESHBL_ShopifyPusher = new MAGESHBL_ShopifyPusher;

                $status = $MAGESHBL_ShopifyPusher->execute($shopifyUrl, $data, $entity);
            }

            wp_send_json_success([$status]);
        }

        add_action('wp_ajax_magefan_shopifyblogexport_push_data_to_shopify', 'mageshbl_magefan_shopifyblogexport_push_data_to_shopify');
    }

    protected function addAdminPages()
    {
        if (is_admin()) {
            function mageshbl_mf_add_custom_link_to_admin_menu()
            {
                add_menu_page(
                    'Export to Magefan Blog Form', // Page title
                    'Export to Magefan Blog',      // Menu title
                    'manage_options',   // Capability
                    'magefan-blog-export-form', // Menu slug
                    'mageshbl_mf_custom_link_page', // Callback function to display the page content
                    'dashicons-admin-links', // Icon URL or dashicon name
                    99                  // Position in the menu
                );
            }

            function mageshbl_mf_custom_link_page()
            {
                include_once plugin_dir_path(dirname(__FILE__)) . 'includes/form.php';
            }

            add_action('admin_menu', 'mageshbl_mf_add_custom_link_to_admin_menu');

            function mageshbl_mf_add_push_page()
            {
                add_submenu_page(
                    null, // No parent menu
                    'My Hidden Page', // Page title
                    'My Hidden Page', // Menu title (not displayed)
                    'manage_options', // Capability
                    'mf-push-page', // Menu slug
                    'mageshbl_mf_add_push_page_content' // Function to display content
                );
            }

            function mageshbl_mf_add_push_page_content()
            {
                include_once plugin_dir_path(dirname(__FILE__)) . 'includes/pusher-page.php';
            }

            add_action('admin_menu', 'mageshbl_mf_add_push_page');
        }
    }
}
