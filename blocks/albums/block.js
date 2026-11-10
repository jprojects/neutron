// Visor de fotos de los álbumes (b-albums): clic en una foto, flechas, teclado y deslizar en el móvil
(function () {
  if (document.body.classList.contains('neutron-editing')) return;
  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('.b-albums__grid a');
    if (!link) return;
    e.preventDefault();
    var links = Array.prototype.slice.call(link.parentNode.querySelectorAll('a'));
    var i = links.indexOf(link);
    var box = document.createElement('div');
    box.className = 'b-albums__viewer';
    box.setAttribute('role', 'dialog');
    box.innerHTML = '<img alt=""><button type="button" data-v="prev" aria-label="Anterior">‹</button><button type="button" data-v="next" aria-label="Següent">›</button>' +
      '<button type="button" data-v="close" aria-label="Tancar">✕</button><p></p>';
    var img = box.querySelector('img'), count = box.querySelector('p');
    function show(n) {
      i = (n + links.length) % links.length;
      img.src = links[i].getAttribute('href');
      img.alt = (links[i].querySelector('img') || {}).alt || '';
      count.textContent = (i + 1) + ' / ' + links.length;
    }
    function close() { box.remove(); document.removeEventListener('keydown', key); }
    function key(ev) { if (ev.key === 'Escape') close(); if (ev.key === 'ArrowLeft') show(i - 1); if (ev.key === 'ArrowRight') show(i + 1); }
    box.addEventListener('click', function (ev) {
      var v = ev.target.getAttribute('data-v');
      if (v === 'prev') show(i - 1); else if (v === 'next') show(i + 1); else if (v === 'close' || ev.target === box) close();
    });
    var x0 = null;
    box.addEventListener('touchstart', function (ev) { x0 = ev.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (ev) { if (x0 === null) return; var dx = ev.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1)); x0 = null; });
    document.addEventListener('keydown', key);
    document.body.appendChild(box);
    show(i);
  });
})();
