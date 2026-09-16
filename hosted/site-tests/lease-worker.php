<?php
$root=$argv[1]??'';if(!str_starts_with($root,'/tmp/dashless-site-test-'))exit(2);
ob_start();require $root.'/wp-load.php';ob_end_clean();$store=\Dashless\Site\App::boot()->store;$lease=$store->lock('build',30);if(!$lease)exit(3);
echo "locked\n";fflush(STDOUT);fgets(STDIN);$store->unlock('build',$lease);
