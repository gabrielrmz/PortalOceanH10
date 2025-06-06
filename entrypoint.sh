#!/bin/bash
set -e

# Espera a que MySQL esté listo
until mysqladmin ping -h"$MYSQL_HOST" --silent; do
  echo "Waiting for MySQL..."
  sleep 2
done

# Llama al script original
sh /cbs/init.sh
