// Portada con partículas (variante particles): puntos blancos y del color principal que flotan y se unen con líneas
// cerca unos de otros y del puntero. Con «reducir movimiento», se dibujan una vez y se quedan quietos. Sin dependencias.
(function () {
  document.querySelectorAll('.b-hero__particles').forEach(function (canvas) {
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dpr = Math.min(window.devicePixelRatio || 1, 2), w = 0, h = 0, dots = [], mouse = { x: -9999, y: -9999 }, raf = 0, visible = true;
    var tint = getComputedStyle(canvas).getPropertyValue('--c-primary').trim() || '#990b57';
    function rgb(hex) {
      var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
      return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [153, 11, 87];
    }
    var c = rgb(tint), light = [Math.round(c[0] + (255 - c[0]) * .6), Math.round(c[1] + (255 - c[1]) * .6), Math.round(c[2] + (255 - c[2]) * .6)];
    function size() {
      var r = canvas.getBoundingClientRect();
      w = r.width; h = r.height;
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.round(Math.min(130, Math.max(36, w * h / 9000)));
      dots = [];
      for (var i = 0; i < n; i++) {
        dots.push({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - .5) * .35, vy: (Math.random() - .5) * .35,
          r: Math.random() * 2.2 + .8, white: Math.random() < .7 });
      }
    }
    function frame() {
      ctx.clearRect(0, 0, w, h);
      var max = Math.min(140, w / 7);
      for (var i = 0; i < dots.length; i++) {
        var d = dots[i];
        if (!still) {
          d.x += d.vx; d.y += d.vy;
          if (d.x < -10) d.x = w + 10; if (d.x > w + 10) d.x = -10;
          if (d.y < -10) d.y = h + 10; if (d.y > h + 10) d.y = -10;
          var mx = d.x - mouse.x, my = d.y - mouse.y, md = Math.sqrt(mx * mx + my * my);
          if (md < 120 && md > 0) { d.x += mx / md * .6; d.y += my / md * .6; }   // se apartan un poco del puntero
        }
        for (var j = i + 1; j < dots.length; j++) {
          var e = dots[j], dx = d.x - e.x, dy = d.y - e.y, dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < max) {
            ctx.strokeStyle = 'rgba(255,255,255,' + (0.28 * (1 - dist / max)).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(d.x, d.y); ctx.lineTo(e.x, e.y); ctx.stroke();
          }
        }
        ctx.fillStyle = d.white ? 'rgba(255,255,255,.9)' : 'rgba(' + light.join(',') + ',.95)';
        ctx.beginPath(); ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2); ctx.fill();
      }
      if (!still && visible) raf = requestAnimationFrame(frame);
    }
    size(); frame();
    var t;
    window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(function () { size(); if (still) frame(); }, 150); });
    canvas.parentNode.addEventListener('pointermove', function (e) { var r = canvas.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
    canvas.parentNode.addEventListener('pointerleave', function () { mouse.x = mouse.y = -9999; });
    // Fuera de la pantalla, parada (no gasta batería)
    if ('IntersectionObserver' in window && !still) {
      new IntersectionObserver(function (en) {
        var was = visible; visible = en[0].isIntersecting;
        if (visible && !was) { cancelAnimationFrame(raf); raf = requestAnimationFrame(frame); }
      }).observe(canvas);
    }
  });
})();
