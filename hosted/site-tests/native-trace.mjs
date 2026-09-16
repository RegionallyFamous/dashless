// Temporary operator preload. No network, environment dumps or customer text.
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {createRequire} from 'node:module';
const work=process.env.TMPDIR;
if(!/^\/tmp\/dashless-site-152056190-[a-f0-9]{24}$/.test(work||''))throw new Error('Unexpected diagnostic workspace');
const file=path.join(work,`usage-${process.pid}.jsonl`);
const record=stage=>fs.appendFileSync(file,JSON.stringify({time:new Date().toISOString(),stage,pid:process.pid,ppid:process.ppid,parallelism:os.availableParallelism(),entry:path.basename(process.argv[1]||''),usage:process.resourceUsage()})+'\n');
record('start');setInterval(()=>record('sample'),100).unref();process.on('exit',()=>record('exit'));
if(process.env.DASHLESS_DIAGNOSTIC_SERIAL_IMAGES==='1'){
 const sharp=createRequire(process.argv[1])('sharp');sharp.concurrency(1);sharp.cache(false);record('serial-images');
}
