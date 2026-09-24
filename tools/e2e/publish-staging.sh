#!/usr/bin/env bash
# Live end-to-end check against the staging WordPress in Docker:
#   connect (key + handshake) → signed health check → publish → verify the site.
#
#   tools/e2e/publish-staging.sh <project-id>
#
# Requires `docker compose up -d` and a generated project.
set -euo pipefail
cd "$(dirname "$0")/../.."

PROJECT="${1:?usage: publish-staging.sh <project-id>}"
export MSYS_NO_PATHCONV=1   # Git Bash on Windows

artisan() { docker compose exec -T app php artisan tinker --execute "$1" 2>/dev/null | tail -n "${2:-1}"; }

echo "→ Creating a connection key"
KEY=$(artisan "\$p=App\Models\Project::findOrFail('$PROJECT'); \$u=\$p->team->members()->first(); echo 'KEY='.app(App\Domain\Publishing\ConnectionService::class)->create(\$p,\$u)['key'];" | grep -o 'KEY=.*' | cut -d= -f2)

echo "→ Handshake from WordPress"
docker compose run --rm --no-deps -e AISG_PLATFORM=http://nginx -e AISG_KEY="$KEY" \
  --entrypoint wp wp-init --path=/var/www/html eval-file wp-content/plugins/aisg-connector/tests/connect.php 2>&1 | grep -v HTTP_HOST

echo "→ Signed health check"
artisan "\$c=App\Models\WpConnection::where('project_id','$PROJECT')->where('status','connected')->latest()->first(); \$r=app(App\Domain\Publishing\ConnectionService::class)->health(\$c); echo json_encode(['ok'=>\$r['ok'],'warnings'=>\$r['warnings'],'error'=>\$r['error']]);"

echo "→ Publishing"
artisan "\$p=App\Models\Project::findOrFail('$PROJECT'); \$c=App\Models\WpConnection::where('project_id',\$p->id)->where('status','connected')->latest()->first(); \$pub=App\Models\Publish::create(['project_id'=>\$p->id,'wp_connection_id'=>\$c->id,'target'=>'push','status'=>'queued','options'=>['force'=>true]]); app(App\Domain\Publishing\Publisher::class)->run(\$pub->fresh(['project','connection'])); \$pub=\$pub->fresh(); echo \$pub->status.' '.json_encode(\$pub->summary).PHP_EOL; foreach(\$pub->logs as \$l){ if(\$l->status!=='ok'){ echo '  ! '.\$l->item_type.' '.\$l->label.': '.\$l->error.PHP_EOL; } }" 20

echo "→ WordPress state"
docker compose run --rm --no-deps --entrypoint wp wp-init --path=/var/www/html \
  eval 'echo count(Aisg\Connector\Import\Importer::inventory()["pages"])." pages, ".count(Aisg\Connector\Import\Importer::inventory()["products"])." products\n";' 2>&1 | grep -v HTTP_HOST

echo "→ Homepage sections"
curl -s http://localhost:8081/ | grep -oE 'data-aisg-section="[a-z-]+"' | sort | uniq -c
