#!/bin/sh
set -e

# Wait for MySQL when the compose profile selects it. With the default SQLite
# group there is nothing to wait for.
# Compose uses dotted env keys (database.defaultGroup), which are not valid
# POSIX shell identifiers, so read them with printenv instead of ${VAR}.
db_group="$(printenv database.defaultGroup 2>/dev/null || true)"
if [ "$db_group" = "mysql" ]; then
    host="$(printenv database.mysql.hostname 2>/dev/null || printf 'db')"
    port="$(printenv database.mysql.port 2>/dev/null || printf '3306')"
    echo "Waiting for MySQL at ${host}:${port} ..."
    attempts=0
    until nc -z "$host" "$port" 2>/dev/null || [ "$attempts" -ge 60 ]; do
        attempts=$((attempts + 1))
        sleep 1
    done
    if [ "$attempts" -ge 60 ]; then
        echo "MySQL did not become available in time." >&2
        exit 1
    fi
fi

exec "$@"
