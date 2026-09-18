(() => {
  const config = window.dashlessHub;
  if (!config) return;
  async function request(route, body = {}, method = 'POST') {
    const response = await fetch(config.api + route, {method, credentials: 'same-origin', headers: {'Content-Type':'application/json','X-WP-Nonce':config.nonce}, ...(method === 'GET' ? {} : {body:JSON.stringify(body)})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Please try again later.');
    return data;
  }
  async function watchExport(id, output) {
    for(let count=0;count<90;count++) {
      await new Promise(resolve=>setTimeout(resolve,4000));
      const job=await request('jobs/'+encodeURIComponent(id),{},'GET');
      if(['failed','canceled'].includes(job.status))throw new Error('Export could not complete. Please retry or contact support.');
      if(job.status==='succeeded') {
        output.textContent='Your export is ready. ';
        const link=document.createElement('a');link.href=config.api+'exports/'+encodeURIComponent(id);link.textContent='Download export';
        // Use an authenticated fetch: REST nonces are never placed in shareable download URLs.
        link.addEventListener('click',async event=>{event.preventDefault();try {
          const response=await fetch(link.href,{credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce}});
          if(!response.ok)throw new Error('Download unavailable.');
          const data=await response.json();location.assign(data.url);
        }catch(error){output.textContent=error.message;}});output.append(link);return;
      }
      await request('progress');
    }
    output.textContent='Your export is still preparing. You can retry from this account page.';
  }
  async function run(route, body, button, output) {
    button.disabled = true; output.textContent = 'One moment…';
    try {
      const result = await request(route, body);
      if (result.url) {
        const target = new URL(result.url);
        const allowed = ['checkout.stripe.com','billing.stripe.com'];
        if (target.protocol !== 'https:' || !allowed.includes(target.hostname)) throw new Error('Unexpected billing destination. Contact support.');
        location.assign(target.href);return;
      }
      output.textContent = result.job_id ? (route==='export'?'Your export is being prepared. Job: ':'Publication queued. Check its progress in ChatGPT. Job: ')+result.job_id : route === 'disconnect' ? 'ChatGPT has been disconnected.' : 'Progress check requested.';
      if(result.job_id && route==='export')await watchExport(result.job_id,output);
    } catch(error) { output.textContent = error.message; }
    finally { button.disabled = false; }
  }
  document.querySelectorAll('[data-dl-form]').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault();
    if (form.dataset.dlForm === 'domain') return connectDomain(form);
    run(form.dataset.dlForm, Object.fromEntries(new FormData(form)), form.querySelector('button'), form.querySelector('[role=status]'));
  }));
  document.querySelectorAll('[data-dl-action]').forEach(button => button.addEventListener('click', () => {
    if (button.dataset.dlConfirm && !window.confirm(button.dataset.dlConfirm)) return;
    run(button.dataset.dlAction, {}, button, document.querySelector('[data-dl-global-status]'));
  }));
  const account = document.querySelector('[data-dl-account]');
  async function verifyDomain(output, verify) {
    verify.disabled=true; output.textContent='Verifying DNS and HTTPS…';
    try {
      const done=await request('domain/verify');
      output.textContent=done.status==='ready'?'Your custom domain is live.':(done.status==='pending_https'?'DNS is verified. HTTPS is still provisioning; try again shortly.':'Keep the TXT record in place and try again.');
      if(done.status==='ready')location.reload();
    } catch(error) { output.textContent=error.message; }
    finally { verify.disabled=false; }
  }
  document.querySelectorAll('[data-dl-domain-verify]').forEach(button => button.addEventListener('click', () => verifyDomain(button.parentElement.querySelector('[data-dl-domain-status]'), button)));
  async function connectDomain(form) {
    const button=form.querySelector('button'), output=form.querySelector('[role=status]') || form.parentElement?.querySelector('[data-dl-domain-status]');
    if (!button || !output) return;
    button.disabled=true; output.textContent='Checking your domain…';
    try {
      const result=await request('domain',Object.fromEntries(new FormData(form)));
      const routing=(result.routing?.values||[]).join(', ');
      output.textContent='Add the TXT record '+result.verification.name+' with value '+result.verification.value+'. Point the domain to '+(routing||'the WP Cloud routing address shown in your setup')+'. Then click verify.';
      const verify=document.createElement('button'); verify.type='button'; verify.className='dl-button dl-secondary'; verify.textContent='Verify DNS and connect';
      verify.addEventListener('click',()=>verifyDomain(output,verify)); output.append(' ',verify);
    } catch(error) { output.textContent=error.message; }
    finally { button.disabled=false; }
  }
  if (account && ['provisioning','provision_error','checkout'].includes(account.dataset.state)) {
    let polls = 0;
    const timer = setInterval(async () => {
      if (++polls > 60) {clearInterval(timer); return;}
      try {
        if(polls%2===1)await request('progress');
        const state = await request('account', {}, 'GET');
        if (state.state !== account.dataset.state) {clearInterval(timer); location.reload();}
      } catch { clearInterval(timer); }
    }, 10000);
  }
})();

// Enhance the inline fallback into native, keyboard-accessible help dialogs.
(() => {
  if (typeof HTMLDialogElement === 'undefined') return;
  const dialogs = new Map();
  const openers = new WeakMap();
  const isHelp = hash => ['#support', '#privacy', '#terms', '#account'].includes(hash);
  document.querySelectorAll('.dl-home-policies details, .dl-home-account').forEach(panel => {
    const id = panel.id;
    const dialog = document.createElement('dialog');
    dialog.className = 'dl-help-dialog';
    dialog.id = 'dl-dialog-' + id;
    dialog.setAttribute('aria-labelledby', 'dl-dialog-title-' + id);
    const header = document.createElement('div');
    header.className = 'dl-dialog-header';
    const title = document.createElement('h2');
    title.id = 'dl-dialog-title-' + id;
    title.textContent = id === 'account' ? 'Your account' : panel.querySelector('summary').textContent;
    const close = document.createElement('button');
    close.type = 'button'; close.className = 'dl-dialog-close';
    close.setAttribute('aria-label', 'Close ' + title.textContent);
    close.textContent = '×';
    close.addEventListener('click', () => dialog.close());
    header.append(title, close);
    const body = document.createElement('div'); body.className = 'dl-dialog-body';
    [...panel.childNodes].filter(node => node.nodeName !== 'SUMMARY').forEach(node => body.append(node));
    dialog.append(header, body); document.body.append(dialog); panel.remove();
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
      if (dialog.open) return;
      if (![...dialogs.values()].some(other => other.open)) {
        document.documentElement.classList.remove('dl-modal-open');
        if (isHelp(location.hash)) history.replaceState(null, '', location.pathname + location.search);
        const opener = openers.get(dialog);
        if (opener?.isConnected) opener.focus({preventScroll:true});
      }
      openers.delete(dialog);
    });
    dialogs.set('#' + id, dialog);
  });
  if (!dialogs.size) return;
  document.querySelector('.dl-home-policies')?.remove();
  function open(hash, trigger) {
    const dialog = dialogs.get(hash);
    if (!dialog || dialog.open) return;
    dialogs.forEach(other => { if (other.open) other.close(); });
    openers.set(dialog, trigger || document.querySelector('a[href="/' + hash + '"]'));
    if (hash === '#account') history.replaceState(null, '', location.pathname + location.search + hash);
    dialog.showModal();
    document.documentElement.classList.add('dl-modal-open');
    dialog.querySelector('button').focus({preventScroll:true});
  }
  document.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname || !dialogs.has(url.hash)) return;
    event.preventDefault(); open(url.hash, link);
  });
  window.addEventListener('hashchange', () => open(location.hash));
  open(location.hash);
})();
