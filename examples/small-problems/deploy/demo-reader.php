<?php
/** Plugin Name: Small Problems reader entrypoint
 * Serve the static demo homepage and branded 404 on WP Cloud.
 * Story and asset files are served directly by the hosting edge.
 */
if (!defined('ABSPATH')) exit;
add_action('template_redirect',function(){
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) return;
    if ($path!=='/' && !is_404()) return;
    $file=dirname(__DIR__,2).($path==='/'?'/index.html':'/404.html');
    if(!is_file($file))return;
    status_header($path==='/'?200:404);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=60');
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($file);
    exit;
},0);
