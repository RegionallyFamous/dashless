// Keep home-section links useful with keyboard navigation as well as a pointer.
(() => {
  document.querySelectorAll('a[href="#start"]').forEach(link => {
    link.addEventListener('click', event => {
      const control = document.querySelector('.dl-intro .dl-wpcom-login, .dl-intro .dl-button');
      if (control) {
        event.preventDefault();
        history.replaceState(null, '', '#start');
        document.getElementById('start').scrollIntoView();
        control.focus({preventScroll:true});
      }
    });
  });
})();
