import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';

// Only pinned source executes. Customer configuration and content remain data.
const runtime = path.dirname(fileURLToPath(import.meta.url));
const work = await fs.realpath(path.resolve(process.argv[2]));
const snapshotFile = path.join(work, 'snapshot.json');
const snapshot = JSON.parse(await fs.readFile(snapshotFile, 'utf8'));
// Packaged runtimes contain a copy made from templates/astro, never a second theme.
const template = await fs.access(path.join(runtime, 'template/package.json')).then(() => path.join(runtime, 'template')).catch(() => path.resolve(runtime, '../../templates/astro'));
const { validateDesign } = await import(pathToFileURL(path.join(template, 'src/lib/design.mjs')).href);
const { loadSnapshot } = await import(pathToFileURL(path.join(template, 'src/lib/snapshot.mjs')).href);
const adapter = await loadSnapshot(snapshotFile);
const design = validateDesign(snapshot.design);
const source = path.join(work, 'source');
await fs.rm(source, { recursive:true, force:true });
await fs.cp(template, source, { recursive:true, filter: file => !['node_modules', 'dist', '.astro', '.dashless-cache'].includes(path.basename(file))&&!path.basename(file).startsWith('.env') });
await fs.symlink(path.join(runtime, 'node_modules'), path.join(source, 'node_modules'), 'dir');
let logo = null;
if (design.logo_media_id) {
  const { data } = adapter.request(`wp/v2/media/${design.logo_media_id}`);
  const file = await adapter.localAsset(data.source_url);
  logo = `/${adapter.asset(data.source_url).relative}`;
  await fs.mkdir(path.dirname(path.join(source, 'public', logo)), {recursive:true});
  await fs.copyFile(file, path.join(source, 'public', logo));
}
const config = { siteName:design.site_title, siteDescription:design.description||design.site_title, wordpressUrl:snapshot.site_url, publicUrl:snapshot.site_url,
  postsPath:'stories', topicsPath:'topics', tagsPath:'tags', postsPerPage:12, mirrorMedia:true,
  homePageId:snapshot.settings?.homePageId || 0, postsPageId:snapshot.settings?.postsPageId || 0,
  language:snapshot.settings?.language || 'en', navigation:design.navigation, logo, design };
await fs.writeFile(path.join(source, 'dashless.config.mjs'), `export default ${JSON.stringify(config)};\n`);
Object.assign(process.env,{DASHLESS_SNAPSHOT:snapshotFile,DASHLESS_RELEASE_PREFIX:'',DASHLESS_ASSETS_PREFIX:'',DASHLESS_PREVIEW_PAYLOAD:'',WORDPRESS_USERNAME:'',WORDPRESS_APP_PASSWORD:''});
process.chdir(source);
console.log('Dashless: snapshot prepared; loading pinned Astro');
const { build } = await import('astro');
console.log('Dashless: building full shared frontend');
await build({root:source});
const { checkPublication } = await import(pathToFileURL(path.join(source,'scripts/check-publication.mjs')).href);
const quality = await checkPublication(source,{production:!['localhost','127.0.0.1','[::1]'].includes(new URL(snapshot.site_url).hostname)});
const dist=path.join(source,'dist');
const files=[];
async function walk(dir,rel='') {
  for(const entry of await fs.readdir(dir,{withFileTypes:true})) {
    const file=path.join(dir,entry.name), relative=rel?`${rel}/${entry.name}`:entry.name;
    if(entry.isDirectory()){await walk(file,relative);continue;}
    if(!entry.isFile())throw new Error('Nonregular output');
    let bytes=await fs.readFile(file);
    // Bind reader links, CSS/JS assets and the inline search index to private preview
    // or public release routes. Absolute canonical/OG/feed URLs remain public URLs.
    if(/\.(html|css|json)$/.test(relative)) {
      const text=bytes.toString().replace(/((?:href|src|poster|action)=["']|"url":")\/(?!\/|__DASHLESS_BASE__)/g,'$1/__DASHLESS_BASE__/')
        .replace(/url\((["']?)\/(?!\/|__DASHLESS_BASE__)/g,'url($1/__DASHLESS_BASE__/');
      bytes=Buffer.from(text);await fs.writeFile(file,bytes);
    }
    files.push({path:relative,bytes:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});
  }
}
await walk(dist);files.sort((a,b)=>a.path.localeCompare(b.path));
await fs.writeFile(path.join(work,'build-result.json'),JSON.stringify({files,quality,frontend_contract:1,html_pages:quality.htmlPages,post_count:quality.articles,page_count:JSON.parse(await fs.readFile(path.join(dist,'dashless-publication.json'),'utf8')).pageCount,media_count:snapshot.media.length,full_snapshot:true}));
