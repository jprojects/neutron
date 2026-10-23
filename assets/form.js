/*
 * Formularios con data-neutron-form: envío por fetch a su action (contact.php) y reCAPTCHA v2 invisible.
 * Lo inyecta el núcleo junto a <script id="neutron-form-config"> cuando la plantilla tiene uno de estos formularios.
 *
 * - El script de Google solo se carga cuando el visitante empieza a usar el formulario (no en cada visita).
 * - El distintivo de reCAPTCHA va dentro de [data-recaptcha] si existe; si no, al final del formulario.
 * - Mensajes en [data-form-status] (o .form-status) con la clase is-error si algo falla.
 * - En el modo edición (?admin) el formulario no envía nada.
 */
(function () {
  var cfgEl = document.getElementById('neutron-form-config');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent || '{}');
  var msg = cfg.messages || {};
  var rc = cfg.recaptcha;
  var isAdmin = !!document.getElementById('neutron-config');

  var apiState = 'idle'; // idle | loading | ready | error
  var apiWaiters = [];

  function loadApi(done) {
    if (apiState === 'ready') { done(true); return; }
    if (apiState === 'error') { done(false); return; }
    apiWaiters.push(done);
    if (apiState === 'loading') return;
    apiState = 'loading';
    window.neutronRecaptchaReady = function () { finish(true); };
    var s = document.createElement('script');
    var lang = (document.documentElement.lang || '').slice(0, 2);
    s.src = 'https://www.google.com/recaptcha/api.js?render=explicit&onload=neutronRecaptchaReady' + (lang ? '&hl=' + lang : '');
    s.async = true;
    s.onerror = function () { finish(false); };
    document.head.appendChild(s);
    // Si un bloqueador corta la petición sin disparar onerror
    setTimeout(function () { if (apiState === 'loading') finish(false); }, 15000);
  }

  function finish(ok) {
    apiState = ok ? 'ready' : 'error';
    var w = apiWaiters; apiWaiters = [];
    w.forEach(function (fn) { fn(ok); });
  }

  function setup(form) {
    var status = form.querySelector('[data-form-status]') || form.querySelector('.form-status');
    var button = form.querySelector('[type="submit"]');
    var widget = null;
    var pending = null;

    function show(text, error) {
      if (!status) { if (error) alert(text); return; }
      status.textContent = text || '';
      status.classList.toggle('is-error', !!error);
    }

    function ensureWidget(done) {
      if (widget !== null) { done(true); return; }
      loadApi(function (ok) {
        if (!ok) { done(false); return; }
        // La precarga y el envío pueden esperar a la vez a que cargue la API: un solo widget
        if (widget !== null) { done(true); return; }
        // Donde diga la plantilla ([data-recaptcha]) o al final del formulario
        var box = document.createElement('div');
        box.className = 'neutron-recaptcha';
        box.style.gridColumn = '1 / -1';
        (form.querySelector('[data-recaptcha]') || form).appendChild(box);
        widget = window.grecaptcha.render(box, {
          sitekey: rc.siteKey,
          size: 'invisible',
          badge: rc.badge || 'inline',
          callback: function (token) { if (pending) { var p = pending; pending = null; p(token); } },
          'expired-callback': function () { window.grecaptcha.reset(widget); },
          'error-callback': function () { if (pending) { pending = null; fail(msg.captcha_load); } }
        });
        done(true);
      });
    }

    function fail(text) {
      show(text, true);
      if (button) button.disabled = false;
    }

    function send(token) {
      if (button) button.disabled = true;
      show(msg.sending, false);
      var data = new FormData(form);
      if (token) data.set('g-recaptcha-response', token);
      fetch(form.getAttribute('action') || 'contact.php', { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          show(res.ok ? msg.success : (res.error || msg.network), !res.ok);
          if (res.ok) form.reset();
        })
        .catch(function () { show(msg.network, true); })
        .then(function () {
          if (button) button.disabled = false;
          if (widget !== null) window.grecaptcha.reset(widget);
        });
    }

    if (rc && !isAdmin) {
      // Precarga al empezar a escribir, para que el envío sea inmediato
      var warm = function () {
        form.removeEventListener('focusin', warm);
        form.removeEventListener('pointerdown', warm);
        ensureWidget(function () {});
      };
      form.addEventListener('focusin', warm);
      form.addEventListener('pointerdown', warm);
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (isAdmin) { show(msg.admin, false); return; }
      if (!form.checkValidity()) { show(msg.invalid, true); return; }
      if (!rc) { send(''); return; }
      // El botón no se bloquea hasta tener el token: si el visitante cierra el desafío de
      // imágenes, Google no avisa y tiene que poder volver a pulsar.
      ensureWidget(function (ok) {
        if (!ok) { fail(msg.captcha_load); return; }
        pending = send;
        window.grecaptcha.execute(widget);
      });
    });
  }

  document.querySelectorAll('form[data-neutron-form]').forEach(setup);
})();
