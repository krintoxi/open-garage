#!/bin/sh
# Local dev server for the Garage app. Open http://localhost:8300
cd "$(dirname "$0")"
exec php -S 0.0.0.0:8300 -d upload_max_filesize=40M -d post_max_size=100M -d memory_limit=512M router.php
