=== Magefan Blog Export ===
Contributors: magefan
Tags: export, shopify, blog
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress blog posts to the [Shopify Blog App](https://apps.shopify.com/magefan-blog) easily with the Magefan plugin.

<a href="https://savelife.in.ua/en/donate-en/#donate-army-card-monthly"><img width="830" height="208" src="https://cm.magefan.com/blog/support-ukraine.png"></a>

<img width="150" height="100" src="https://magefan.com/media/wysiwyg/made_in_ukraine.jpg">

== Description ==
WP Blog Export to Shopify Blog App by Magefan allows you to easily migrate your WordPress blog posts to Shopify using the Magefan Blog App.

Choose where to export:

* **Shopify** — to the [Magefan Blog App](https://apps.shopify.com/magefan-blog) for Shopify.
* **Magento** — to the Magefan Blog extension for Magento 2.
* **Shopify default blog** — to the native Shopify blog with the Blog Import app by Magefan. Posts (including drafts, scheduled and private posts, imported as hidden), categories as separate Shopify blogs, tags, author names, featured and inline images, and SEO title and description from Yoast SEO, Rank Math, SEOPress or All in One SEO. Images are uploaded straight to Shopify, so it works even when your site is not publicly reachable.

== Installation ==
1. Download the latest version of the plugin [here](https://github.com/magefan/magefan-blog-export/releases).
2. Unzip the archive.
3. Ensure the unzipped folder name is `magefan-blog-export`. Rename it if necessary.
4. Upload the `magefan-blog-export` folder to the `/wp-content/plugins` directory in your WordPress installation.
5. Log in to your WordPress Admin Panel, navigate to Plugins, and activate the plugin.

== Usage ==
To use the plugin:
1. Log in to your WordPress Admin Panel.
2. Navigate to the "Export to Shopify" section.
3. Follow the instructions to export your blog posts.

Example screenshot:

<img width="1012" src="https://magefan.com/media/wysiwyg/magefan-blog-export.png">

To export to the default Shopify blog, select **Shopify default blog**, paste the connection key from the Blog Import app in your Shopify admin into the **Import Key** field and click **Start Export**. Once all posts are sent you can close the page; the import continues in Shopify.

== External services ==

This plugin sends your blog content to the destination you select. Nothing is sent until you click Start Export.

= Magefan Blog App for Shopify =

Used when the destination is Shopify. Your categories, tags, authors, posts, comments and images, together with the Import Key, are sent to https://blog.sfapp.magefan.top/ while the export runs.

* Privacy policy: https://magefan.com/privacy-policy

= Your Magento store =

Used when the destination is Magento. The same data is sent to the store domain you enter.

= Blog Import app by Magefan =

Used when the destination is Shopify default blog. Receives the export and creates the posts in your Shopify store. The address of the service is part of the connection key you copy from the app in your Shopify admin.

* When: when you start, cancel or check an export, and while posts are being sent.
* What: your categories (name, slug, description) and posts (title, slug, content, excerpt, author name, tags, categories, status, publish date, SEO title and description, image URLs and alt text), your site URL and the plugin version. The connection key is sent with every request.
* Privacy policy: https://magefan.com/privacy-policy

= Shopify =

Used when the destination is Shopify default blog. Image files stored in your uploads folder are uploaded directly to Shopify's file storage, using an upload address that Shopify issues for each file through the Blog Import app.

* When: while posts are being sent, for posts that have images.
* What: the image files.
* Terms of service: https://www.shopify.com/legal/terms
* Privacy policy: https://www.shopify.com/legal/privacy

== Frequently Asked Questions ==
= How do I install this plugin? =
Follow the installation instructions provided above to set up the plugin.

= Can I use this with any Shopify store? =
Yes, as long as you have the Magefan Blog App installed on your Shopify store.

== Screenshots ==
1. **Export Section** - Easily export your WordPress blog posts to Shopify.
   <img width="1012" src="https://magefan.com/media/wysiwyg/magefan-blog-export.png">

== Changelog ==

= 1.0.4 =
* The export page no longer shows the result of a finished export after reload.
* Clearer destination names, with a note explaining the selected destination.

= 1.0.3 =
* New destination: Shopify default blog, through the Blog Import app by Magefan.
