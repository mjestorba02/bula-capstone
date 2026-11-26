<?php
// Health check for hosting platforms: answers 200 without touching the database.
header('Content-Type: text/plain');
echo 'ok';
