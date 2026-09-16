import fs from 'node:fs/promises';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
const root=process.argv[2];if(!root?.startsWith('/tmp/dashless-site-test-bootstrap-'))throw new Error('Dedicated bootstrap fixture required');
execFileSync(process.execPath,['hosted/site-tests/setup.mjs',root],{stdio:'inherit'});
const plugin=path.join(root,'wp-content/plugins/dashless-site');if(!(await fs.lstat(plugin)).isSymbolicLink())throw new Error('Only replace the fixture symlink');await fs.unlink(plugin);
const packages=JSON.parse(await fs.readFile('hosted/dist/site-packages.json','utf8'));
execFileSync('unzip',['-q',path.resolve('hosted/dist',packages.site.filename),'-d',path.join(root,'wp-content/plugins')]);
console.log('Installed the exact pinned site ZIP in a disposable WordPress fixture.');
