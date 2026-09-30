<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */
?>
<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly
?>
<h1>Export to Magefan Blog</h1>
<div class="notice notice-info inline" id="mageshbl-destination-note-shopify" style="display: none">
    <p>Note: this plugin migrates WordPress blog to <a href="https://apps.shopify.com/magefan-blog" target="_blank" rel="noopener noreferrer">Magefan's Shopify Blog App</a>. If you want to migrate to the default Shopify Blog, choose "default Shopify blog" from the dropdown.</p>
</div>
<div class="notice notice-info inline" id="mageshbl-destination-note-magento" style="display: none">
    <p>Note: this plugin migrates WordPress blog to <a href="https://magefan.com/magento2-blog-extension" target="_blank" rel="noopener noreferrer">Magefan's Magento Blog Extension</a> only.</p>
</div>
<form id="mageshbl-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mf-push-page' ) ); ?>">
    <?php
    wp_nonce_field( 'magefan_export_action', 'mageshbl_nonce' );
    ?>
    <!-- Your HTML form fields go here -->
    <input type="hidden" name="action" value="mf_handle_form_submission">

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="destination">Select Destination</label></th>
                <td>
                    <select name="destination" id="destination" required>
                        <option value="" disabled selected>-- Select an option --</option>
                        <option value="shopify">Magefan blog for Shopify</option>
                        <option value="magento">Magefan blog for Magento</option>
                        <option value="shopify_blog">default Shopify blog</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="export_shopify_import_key">Import Key</label>
                </th>
                <td>
                    <input id="export_shopify_import_key" name="shopify_import_key" type="text" required />
                    <p class="description" id="tagline-description" style="display: none">Please copy the <strong>Import Key</strong> from your Shopify Admin Panel > Apps > Magefan Blog > Configuration > Import Key.</p>
                </td>
            </tr>
            <tr id="entities-limit">
                <th scope="row">
                    <label for="export_shopify_entities_limit">Entities Per Export Request (100 is default, try less if data is not exported)</label>
                </th>
                <td>
                    <input id="export_shopify_entities_limit" name="entities_limit" type="text" value="100" required />
                </td>
            </tr>
            <tr id="domain" style="display: none">
                <th scope="row">
                    <label for="export_domain">Store Domain</label>
                </th>
                <td>
                    <input id="export_domain" name="magento_domain" type="text"/>
                    <p class="description" id="export-domain-description">Please enter your store domain, for example, https://my.store.com/.</p>
                </td>
            </tr>
            <tr>
                <td></td>
                <td><input type="submit" id="mageshbl-export-submit" name="submit_form" value="Start Export" class="button button-primary"></td>
            </tr>
        </tbody>
    </table>
</form>

<?php include plugin_dir_path( __FILE__ ) . 'blog-import/progress.php'; ?>


<?php
wp_register_script('mageshbl-inline-js', false, ['jquery'], false, true);
wp_enqueue_script('mageshbl-inline-js');
$mageshbl_inline_js = "
        document.addEventListener('DOMContentLoaded', function() {
            const destination = document.getElementById('destination');
            if (!destination) return;
            const toggleNotes = function() {
                ['shopify', 'magento'].forEach(function(value) {
                    const note = document.getElementById('mageshbl-destination-note-' + value);
                    if (note) note.style.display = destination.value === value ? '' : 'none';
                });
            };
            toggleNotes();
            destination.addEventListener('change', toggleNotes);
            destination.addEventListener('change', function() {
                const description = document.getElementById('tagline-description');
                const domain = document.getElementById('domain');
                const exportDomain = document.getElementById('export_domain');
                if (!description || !domain || !exportDomain) return;
                if (this.value === 'shopify') {
                    description.style.display = 'block';
                    domain.style.display = 'none';
                    exportDomain.required = false;
                    description.innerHTML = 'Please copy the <strong>Import Key</strong> from your Shopify Admin Panel > Apps > Magefan Blog > Configuration > Import Key.';
                } else if (this.value === 'magento') {
                    description.style.display = 'block';
                    domain.style.display = '';
                    exportDomain.required = true;
                    description.innerHTML = 'Please copy the <strong>Import Key</strong> from your Magento Admin Panel > Stores > Configuration > Magefan Blog > Import Key.';
                }
            });
        });
    ";
wp_add_inline_script('mageshbl-inline-js', $mageshbl_inline_js);
?>