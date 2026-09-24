#!/bin/sh
# Idempotent staging WordPress bootstrap: core install + pinned Elementor/WooCommerce/Hello + our plugin.
set -e
cd /var/www/html

# www-data has no writable HOME; keep the download cache in the WP volume so re-runs are fast.
export WP_CLI_CACHE_DIR=/var/www/html/wp-content/.wp-cli-cache

# Retry flaky downloads (slow links, parallel model pulls).
retry() {
    n=1
    until "$@"; do
        [ "$n" -ge 3 ] && return 1
        echo "Retrying ($n/3): $*"
        n=$((n + 1))
        sleep 5
    done
}

echo "Waiting for WordPress files..."
until [ -f wp-config.php ]; do sleep 2; done
# (No `wp db check`: the CLI image's MariaDB client rejects MySQL 8.4's self-signed TLS cert;
#  compose already waits for wp-db to be healthy.)

if ! wp core is-installed 2>/dev/null; then
    wp core install \
        --url="$WP_URL" \
        --title="AISG Staging Store" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.test \
        --skip-email
fi

wp rewrite structure '/%postname%/' --hard

install_plugin() {
    slug="$1"; version="$2"
    current=$(wp plugin get "$slug" --field=version 2>/dev/null || true)
    if [ "$current" != "$version" ]; then
        retry wp plugin install "$slug" --version="$version" --force
    fi
    wp plugin activate "$slug"
}

install_plugin elementor "$ELEMENTOR_VERSION"
install_plugin woocommerce "$WOOCOMMERCE_VERSION"

current_theme=$(wp theme get hello-elementor --field=version 2>/dev/null || true)
if [ "$current_theme" != "$HELLO_ELEMENTOR_VERSION" ]; then
    retry wp theme install hello-elementor --version="$HELLO_ELEMENTOR_VERSION" --force
fi
wp theme activate hello-elementor

# Elementor: Flexbox containers on, no onboarding redirect.
wp option update elementor_experiment-container active
wp option update elementor_onboarded 1
wp option delete _elementor_activation_redirect 2>/dev/null || true

# WooCommerce: skip the setup wizard.
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
wp option update woocommerce_coming_soon no

wp plugin activate aisg-connector

echo "Staging WordPress ready at $WP_URL (admin / admin)"
wp plugin list --fields=name,status,version
