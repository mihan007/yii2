#!/usr/bin/env bash
mkdir -p /backup/mysql/latest
USER=autoscan_lk_user
PASSWORD=RHHkY0c5TBcv
HOST=localhost
DATABASE=autoscan_lk_db
DB_FILE=/backup/mysql/latest/autoscan_lk_db.sql
EXCLUDED_TABLES=(
queue_exec
queue_push
queue_worker
)

IGNORED_TABLES_STRING=''
for TABLE in "${EXCLUDED_TABLES[@]}"
do :
   IGNORED_TABLES_STRING+=" --ignore-table=${DATABASE}.${TABLE}"
done

echo "Dump structure"
mysqldump --host=${HOST} --user=${USER} --password=${PASSWORD} --single-transaction --no-data --routines ${DATABASE} | pv --progress --size 1m > ${DB_FILE}

echo "Dump content"
mysqldump --host=${HOST} --user=${USER} --password=${PASSWORD} ${DATABASE} --no-create-info --skip-triggers ${IGNORED_TABLES_STRING} | pv --progress --size 1m >> ${DB_FILE}

echo "Compress"
cd /backup/mysql/latest/ && tar -czf autoscan_lk_db.sql.gz autoscan_lk_db.sql