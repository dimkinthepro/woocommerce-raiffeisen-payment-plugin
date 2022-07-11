## Simple Raiffeisen payment plugin for WooCommerce
### 1. Installation
1.1. Copy the unzipped plugin to "wp-content/plugins/" folder.

1.2. Go to wordpress plugins page "wp-admin/plugins.php" and enable "WooCommerce Raiffeisen payment plugin".

1.3. Go to woocommerce settings payments "wp-admin/admin.php?page=wc-settings&tab=checkout" and set up the plugin.
Fill a "Merchant ID" and "Terminal ID".

### 2. Settings for Raiffeisen
2.1. Set up callback url like "https://youre_site/wc-api/raiffeisen_callback".
You can use API for setting: "https://pay.raif.ru/doc/ecom.html#operation/post-settings-v1-callback"


