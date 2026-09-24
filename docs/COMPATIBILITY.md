# Compatibility

The connector plugin is developed and tested against exactly these versions, installed by `docker/wordpress/wp-init.sh` in the staging WordPress.

| Component | Pinned version | Source |
|---|---|---|
| WordPress | 7.1.2 | `wordpress:7.1.2-php8.3-apache` image tag (docker-compose.yml) |
| PHP (WordPress) | 8.3 | same image |
| Elementor (free) | 4.3.1 | `docker/wordpress/versions.env` |
| WooCommerce | 11.1.2 | `docker/wordpress/versions.env` |
| Hello Elementor theme | 3.5.1 | `docker/wordpress/versions.env` |
| Plugin minimum PHP | 8.1 | `apps/wp-connector/aisg-connector.php` header |

Platform runtime: PHP 8.4, Laravel 13, PostgreSQL 16, Redis 7, Node 22.

## Updating a pin

1. Change the version in `docker/wordpress/versions.env` (or the image tag for WordPress core).
2. `docker compose up wp-init`. The script reinstalls any plugin/theme whose version differs.
3. Run the connector's integration tests against staging (available from M4).
4. Update this table in the same commit.

## Notes

- Pinned on 2026-09-24 to the latest stable releases from wordpress.org.
- Elementor 4.x ships the new atomic editor alongside Flexbox containers. The connector targets **containers** (spec §2); `wp-init.sh` forces the `container` experiment on. Revisit this during M4 (risk R2 in the architecture doc).

## Verified in M4 (2026-09-24)

Against the pinned stack above:
- publish and re-publish
- products (simple + variable with variations, sale prices, images)
- nested categories
- menu and homepage
- header/footer on shop pages
- conflict detection
- ZIP import
- the published page opening in the **Elementor 4.3.1 editor** with AISG widgets inside Flexbox Containers and schema-generated controls
