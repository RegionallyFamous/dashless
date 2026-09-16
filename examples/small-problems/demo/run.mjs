import './prepare.mjs';
import { spawn } from 'node:child_process';
const child=spawn(process.execPath,['node_modules/astro/bin/astro.mjs','dev','--host','127.0.0.1','--port','4327'],{stdio:'inherit'});
child.on('exit',code=>process.exitCode=code||0);
