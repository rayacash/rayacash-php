#!/usr/bin/env bash
# Corre un comando sin red, solo con loopback: lo mismo que el --network none de scripts/dev.sh,
# para el CI de GitHub. Crea un namespace de red nuevo (como el CI de sigstore-python), donde la
# única interfaz es lo: los tests nunca salen a la red, y acá ni pueden.
#
#   bash scripts/offline.sh composer test
set -euo pipefail

if [ "$(uname -s)" != Linux ]; then
  echo 'scripts/offline.sh es para el CI (Linux). En tu máquina: scripts/dev.sh.' >&2
  exit 2
fi
if [ $# -eq 0 ]; then
  echo 'Uso: bash scripts/offline.sh <comando> [args…]' >&2
  exit 2
fi

# Ubuntu 24.04 no deja crear user namespaces sin privilegios (AppArmor); el runner tiene sudo.
sudo sysctl -qw kernel.apparmor_restrict_unprivileged_userns=0 2>/dev/null || true
sudo sysctl -qw kernel.unprivileged_userns_clone=1 2>/dev/null || true

# Adentro somos root (mapeado a tu usuario): Composer lo acepta con COMPOSER_ALLOW_SUPERUSER.
exec unshare --map-root-user --net -- env COMPOSER_ALLOW_SUPERUSER=1 \
  sh -c 'ip link set lo up && exec "$@"' offline "$@"
