<?php
/**
 * WP-CLI helper for E2E: import a package ZIP like the admin "Import package" screen.
 *   AISG_PACKAGE=/path/to/aisg-package.zip wp eval-file .../tests/import-package.php
 */

use Aisg\Connector\Admin\PackageImport;

wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));

$report = (new PackageImport)->run((string) getenv('AISG_PACKAGE'), (bool) getenv('AISG_FORCE'));

echo wp_json_encode($report, JSON_PRETTY_PRINT)."\n";
