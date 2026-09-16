import fs from 'node:fs/promises';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
const source='/tmp/dashless-wp-test', target=process.argv[2];
if(!target?.startsWith('/tmp/dashless-site-test-'))throw new Error('Use a dedicated /tmp/dashless-site-test-* directory');
await fs.mkdir(target,{recursive:true});
for(const name of await fs.readdir(source)){if(['wp-content','wp-config.php'].includes(name))continue;await fs.cp(path.join(source,name),path.join(target,name),{recursive:true});}
await fs.mkdir(path.join(target,'wp-content/plugins'),{recursive:true});
await fs.copyFile(path.join(source,'wp-content/db.php'),path.join(target,'wp-content/db.php'));
await fs.cp(path.join(source,'wp-content/plugins/sqlite-database-integration'),path.join(target,'wp-content/plugins/sqlite-database-integration'),{recursive:true});
await fs.symlink(path.resolve('wordpress'),path.join(target,'wp-content/plugins/dashless-site'));
await fs.writeFile(path.join(target,'wp-config.php'),`<?php\ndefine('DB_NAME','dashless_site_test');define('DB_USER','local');define('DB_PASSWORD','local');define('DB_HOST','localhost');define('DB_CHARSET','utf8');$table_prefix='wp_';define('WP_ENVIRONMENT_TYPE','local');define('DISABLE_WP_CRON',true);define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('DASHLESS_LOCAL_NODE',${JSON.stringify(process.execPath)});if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';\n`);
execFileSync('wp',['core','install',`--path=${target}`,'--url=http://localhost:8891','--title=Site Test','--admin_user=operator','--admin_password=fixture-only-password','--admin_email=operator@example.test','--skip-email'],{stdio:'inherit'});
execFileSync('wp',['plugin','activate','dashless-site/dashless-hosted.php',`--path=${target}`],{stdio:'inherit'});
execFileSync('wp',['rewrite','structure','/%postname%/',`--path=${target}`],{stdio:'inherit'});
const runtime=path.resolve('hosted/runtime');const files=[];
for(const rel of ['build.mjs','supervise.mjs','package-lock.json','package.json']){const bytes=await fs.readFile(path.join(runtime,rel));files.push({path:rel,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});}
await fs.writeFile(path.join(runtime,'runtime-manifest.json'),JSON.stringify({version:'0.1.0',files}));
console.log(target);
