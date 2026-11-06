#!/bin/sh
# Runs on the server from the GitHub Actions deploy job, in /opt/archive (same VPS as the museum portal in /opt/mincult).
# Needs DOMAIN; ADMIN_PASSWORD (password of the demo users *@archive.test) seeds the database on the first run.
set -eu
cd /opt/archive

# admin/.env is created once and then kept: it holds APP_KEY and the database password.
if [ ! -f admin/.env ]; then
  cp admin/.env.docker.example admin/.env
  KEY="base64:$(head -c 32 /dev/urandom | base64)"
  DBPW="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
  sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|; s|^DB_PASSWORD=.*|DB_PASSWORD=$DBPW|" admin/.env
  printf 'DB_PASSWORD=%s\n' "$DBPW" > .env
fi
setenv() { grep -q "^$2=" "$1" && sed -i "s|^$2=.*|$2=$3|" "$1" || echo "$2=$3" >> "$1"; }
setenv admin/.env APP_URL "https://$DOMAIN"
[ -n "${GEMINI_API_KEY:-}" ] && setenv admin/.env GEMINI_API_KEY "$GEMINI_API_KEY"

# the museum portal must be up: its Caddy holds 80/443 and its network carries our traffic
docker network inspect mincult_default >/dev/null 2>&1 || { echo "mincult_default network is missing: deploy the museum portal first"; exit 1; }

export ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
C="docker compose -p archive -f docker-compose.yml -f docker-compose.prod.yml"
# The deploy job builds the image on GitHub and loads it here (PREBUILT=1), so this VPS builds nothing.
if [ "${PREBUILT:-0}" != 1 ]; then
  COMPOSE_PARALLEL_LIMIT=1 COMPOSE_BAKE=false $C build
fi
$C up -d --no-build --remove-orphans
# nginx keeps the old address of a recreated app container (502); restart it after every deploy
$C restart archive-web

# site block for the museum portal's Caddy; validate first, a broken file would block the reload of every site
mkdir -p /opt/caddy-sites
# the same host in two caddy files breaks the reload of every site
if grep -l -w -F "$DOMAIN" /opt/caddy-sites/*.caddy 2>/dev/null | grep -v '/archive.caddy$'; then
  echo "::error::$DOMAIN is already in another /opt/caddy-sites file (listed above); not touching Caddy"; exit 1
fi
sed "s|ARCHIVE_DOMAIN|$DOMAIN|" deploy/archive.caddy > /opt/caddy-sites/archive.caddy.new
CADDY="$(docker ps -qf label=com.docker.compose.project=mincult -f label=com.docker.compose.service=caddy | head -1)"
if [ -z "$CADDY" ]; then
  rm -f /opt/caddy-sites/archive.caddy.new
  echo "::warning::Caddy of the museum portal is not running; the site is not reachable from outside"
else
  [ -f /opt/caddy-sites/archive.caddy ] && cp /opt/caddy-sites/archive.caddy /opt/caddy-sites/archive.caddy.bak
  mv /opt/caddy-sites/archive.caddy.new /opt/caddy-sites/archive.caddy
  if docker exec "$CADDY" caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/tmp/archive-caddy.log 2>&1; then
    docker exec "$CADDY" caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    rm -f /opt/caddy-sites/archive.caddy.bak
  else
    tail -5 /tmp/archive-caddy.log
    if [ -f /opt/caddy-sites/archive.caddy.bak ]; then mv /opt/caddy-sites/archive.caddy.bak /opt/caddy-sites/archive.caddy; else rm -f /opt/caddy-sites/archive.caddy; fi
    echo "::error::caddy validate failed, archive.caddy rolled back, Caddy not reloaded"; exit 1
  fi
fi

docker image prune -f >/dev/null
$C ps

# smoke test: the app itself (nginx inside the project), then the public HTTPS through Caddy
inner() { $C exec -T archive-web wget -qO /dev/null -S "http://127.0.0.1$1" 2>&1 | grep -o 'HTTP/1\.[01] [0-9]*' | tail -1 | awk '{print $2+0}'; }
for i in $(seq 1 30); do
  login="$(inner /admin/login)"
  echo "try $i: /admin/login -> $login"
  [ "$login" = 200 ] && break
  sleep 5
done
echo "DNS of $DOMAIN: $(getent hosts "$DOMAIN" | awk '{print $1}' | tr '\n' ' ')(server: $(curl -s --max-time 5 https://api.ipify.org || echo '?'))"
public="$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' "https://$DOMAIN/admin/login" || true)"
echo "https://$DOMAIN/admin/login -> $public"
[ "$public" = 200 ] || echo "::warning::https://$DOMAIN/ is not reachable yet: check the DNS A record ($DOMAIN -> this server)"
$C logs archive-app 2>&1 | grep '@archive.test' | tail -4 || true
$C exec -T archive-app php artisan tinker --execute='echo App\Models\User::count()." users, ".App\Models\Record::count()." records\n";' || true
[ "$login" = 200 ] || { $C logs --tail=40 archive-app; exit 1; }
