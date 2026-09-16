/* An illustrative walkthrough only. No account, content, or publishing requests. */
document.querySelectorAll('[data-dl-walkthrough]').forEach(walkthrough => {
  const steps = [...walkthrough.querySelectorAll('[data-step]')];
  const panels = [...walkthrough.querySelectorAll('.dl-flow-panel')];
  function show(name, moveFocus = false) {
    const selected = steps.find(button => button.dataset.step === name);
    if (!selected) return;
    steps.forEach(button => button.setAttribute('aria-pressed', String(button === selected)));
    panels.forEach(panel => { panel.hidden = panel.id !== selected.getAttribute('aria-controls'); });
    if (moveFocus) selected.focus({ preventScroll: true });
    if (matchMedia('(max-width: 781px)').matches) {
      walkthrough.querySelector('.dl-flow-stage').scrollIntoView({
        block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'
      });
    }
  }
  steps.forEach(button => button.addEventListener('click', () => show(button.dataset.step)));
  walkthrough.querySelectorAll('[data-next]').forEach(button => {
    button.addEventListener('click', () => show(button.dataset.next, true));
  });
});
