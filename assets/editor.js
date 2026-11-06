(function () {
  var cfgEl = document.getElementById('neutron-config');
  if (!cfgEl) return;

  var cfg = JSON.parse(cfgEl.textContent || '{}');

  if (!cfg.loggedIn) {
    showLoginModal(cfg.csrf);
    return;
  }

  initEditor(cfg);

  function showLoginModal(csrf) {
    var overlay = document.createElement('div');
    overlay.className = 'neutron-overlay';
    overlay.innerHTML =
      '<form class="neutron-login-box">' +
        '<h2>Acceso administrador</h2>' +
        '<input type="password" name="password" placeholder="Contraseña" autofocus required>' +
        '<button type="submit">Entrar</button>' +
        '<p class="neutron-error" hidden></p>' +
      '</form>';
    document.body.appendChild(overlay);

    var form = overlay.querySelector('form');
    var errorEl = overlay.querySelector('.neutron-error');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var password = form.password.value;
      fetch('admin/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ password: password })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            location.reload();
          } else {
            errorEl.textContent = data.error || 'Error de acceso';
            errorEl.hidden = false;
          }
        })
        .catch(function () {
          errorEl.textContent = 'Error de red';
          errorEl.hidden = false;
        });
    });
  }

  function initEditor(cfg) {
    document.body.classList.add('neutron-editing');

    var bar = document.createElement('div');
    bar.className = 'neutron-bar';
    bar.innerHTML =
      '<span>Modo edición</span>' +
      '<label class="neutron-pages" hidden title="También: Ctrl/Cmd + clic en un enlace de la página">Página <select></select></label>' +
      '<label class="neutron-langs" hidden>Idioma <select></select></label>' +
      '<button type="button" data-action="save">Guardar cambios</button>' +
      '<button type="button" data-action="exit">Salir</button>' +
      '<button type="button" data-action="logout">Cerrar sesión</button>' +
      '<span class="neutron-status"></span>';
    document.body.appendChild(bar);

    var statusEl = bar.querySelector('.neutron-status');
    var dirty = false;

    // Selector de páginas: ir a otra página sin salir del modo edición
    var pages = cfg.pages || [];
    if (pages.length > 1) {
      var select = bar.querySelector('.neutron-pages select');
      pages.forEach(function (p) {
        var opt = document.createElement('option');
        opt.value = p.href;
        opt.textContent = p.title;
        opt.selected = p.href === cfg.current;
        select.appendChild(opt);
      });
      bar.querySelector('.neutron-pages').hidden = false;
      select.addEventListener('change', function () {
        if (!goEditing(new URL(select.value, document.baseURI))) select.value = cfg.current;
      });
    }

    // Selector de idioma (webs de varios idiomas): la misma página en otro idioma, en modo edición
    var languages = cfg.languages || [];
    if (languages.length > 1) {
      var langSelect = bar.querySelector('.neutron-langs select');
      languages.forEach(function (l) {
        var opt = document.createElement('option');
        opt.value = l.href;
        opt.textContent = l.name;
        opt.selected = l.code === cfg.lang;
        langSelect.appendChild(opt);
      });
      bar.querySelector('.neutron-langs').hidden = false;
      langSelect.addEventListener('change', function () {
        var current = languages.filter(function (l) { return l.code === cfg.lang; })[0];
        if (!goEditing(new URL(langSelect.value, document.baseURI)) && current) langSelect.value = current.href;
      });
    }

    // Cambios sin guardar: se avisa antes de salir de la página
    document.addEventListener('input', function (e) {
      if (e.target.closest && e.target.closest('[contenteditable]')) markDirty();
    });
    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    // Enlaces en modo edición: un clic en un texto editable es para editarlo; Ctrl/Cmd + clic, o un clic
    // en un enlace interno sin texto editable, lleva a esa página sin salir del editor.
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || bar.contains(a)) return;
      var url = new URL(a.href, location.href);
      var internal = url.origin === location.origin && !/^(mailto|tel):/i.test(a.getAttribute('href'));
      var samePage = url.pathname === location.pathname && url.hash !== '';
      var onEditable = e.target.closest('[contenteditable="true"], .neutron-editable-img');
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        if (internal && !samePage) goEditing(url);
        return;
      }
      if (onEditable) { e.preventDefault(); return; }
      if (a.hasAttribute('data-edit-href')) return; // un clic cambia su dirección (setupHref)
      if (internal && !samePage) { e.preventDefault(); goEditing(url); }
    }, true);

    document.querySelectorAll('[data-edit]').forEach(function (el) {
      el.setAttribute('contenteditable', 'true');
      el.classList.add('neutron-editable');
      // La misma clave puede salir varias veces (cabecera y pie): se mantienen iguales
      el.addEventListener('input', function () {
        var key = el.getAttribute('data-edit');
        document.querySelectorAll('[data-edit]').forEach(function (other) {
          if (other !== el && other.getAttribute('data-edit') === key) other.textContent = el.textContent;
        });
      });
    });

    document.querySelectorAll('[data-edit-img]').forEach(makeImageSwappable);
    document.querySelectorAll('[data-edit-href]').forEach(setupHref);

    // Listas: cada campo o sublista pertenece al data-edit-item más cercano, así puede haber
    // listas dentro de listas (secciones de una carta con sus platos).
    topCollections().forEach(setupCollection);

    // Blog: la entrada que se edita (cfg.post) y «Nueva entrada» en las páginas con listado (cfg.collections)
    var post = cfg.post || null;
    var rich = null;
    if (post) setupPost();
    (cfg.collections || []).forEach(function (collection) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'neutron-new-post';
      b.textContent = '+ Nueva entrada';
      b.addEventListener('click', function () { createPost(collection); });
      bar.insertBefore(b, statusEl);
    });

    bar.addEventListener('click', function (e) {
      var action = e.target.getAttribute('data-action');
      if (action === 'save') doSave();
      if (action === 'exit') exitAdminMode();
      if (action === 'logout') doLogout();
    });

    function markDirty() {
      dirty = true;
      statusEl.textContent = 'Cambios sin guardar';
    }

    // Abre url en modo edición (con ?admin); pregunta antes si hay cambios sin guardar.
    function goEditing(url) {
      if (dirty && !confirm('Hay cambios sin guardar en esta página. ¿Salir sin guardarlos?')) return false;
      dirty = false;
      url.searchParams.delete('admin');
      var search = url.searchParams.toString();
      location.href = url.pathname + '?' + (search ? search + '&' : '') + 'admin' + url.hash;
      return true;
    }

    function ownerItem(el) {
      return el.parentElement ? el.parentElement.closest('[data-edit-item]') : null;
    }
    function topCollections() {
      return Array.prototype.filter.call(document.querySelectorAll('[data-edit-collection]'), function (c) { return !ownerItem(c); });
    }
    function ownItems(container) {
      return Array.prototype.filter.call(container.children, function (c) { return c.hasAttribute('data-edit-item'); });
    }
    function own(item, selector) {
      return Array.prototype.filter.call(item.querySelectorAll(selector), function (el) { return ownerItem(el) === item; });
    }

    function setupCollection(container) {
      container.classList.add('neutron-collection');
      ownItems(container).forEach(function (item) { bindItem(item, container); });
      addCollectionControls(container);
    }

    function bindItem(item, container) {
      own(item, '[data-edit-field]').forEach(function (field) {
        if (field.tagName.toLowerCase() === 'img') {
          makeImageSwappable(field);
        } else {
          field.setAttribute('contenteditable', 'true');
          field.classList.add('neutron-editable');
        }
      });
      own(item, '[data-edit-collection]').forEach(setupCollection);
      addItemControls(item, container);
    }

    function addItemControls(item, container) {
      var box = document.createElement('span');
      box.className = 'neutron-item-controls';
      box.innerHTML =
        '<button type="button" class="neutron-move-item" title="Subir">↑</button>' +
        '<button type="button" class="neutron-remove-item" title="Quitar">✕</button>';
      box.querySelector('.neutron-move-item').addEventListener('click', function () {
        var items = ownItems(container);
        var i = items.indexOf(item);
        if (i > 0) { container.insertBefore(item, items[i - 1]); markDirty(); }
      });
      box.querySelector('.neutron-remove-item').addEventListener('click', function () {
        if (ownItems(container).length <= 1) {
          alert('Debe quedar al menos un elemento.');
          return;
        }
        item.remove();
        markDirty();
      });
      item.appendChild(box);
    }

    function addCollectionControls(container) {
      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'neutron-add-item';
      addBtn.textContent = '+ ' + (container.getAttribute('data-edit-add') || 'Agregar elemento');
      addBtn.addEventListener('click', function () {
        var items = ownItems(container);
        var last = items[items.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        // Sin los controles del editor; las sublistas se quedan con un solo elemento vacío
        clone.querySelectorAll('.neutron-item-controls, .neutron-add-item').forEach(function (el) { el.remove(); });
        clone.querySelectorAll('[data-edit-collection]').forEach(function (sub) {
          ownItems(sub).slice(1).forEach(function (extra) { extra.remove(); });
        });
        clone.querySelectorAll('[data-edit-field]').forEach(function (field) {
          if (field.tagName.toLowerCase() !== 'img') field.textContent = '';
        });
        container.insertBefore(clone, last.nextSibling);
        bindItem(clone, container);
        markDirty();
        var first = clone.querySelector('[contenteditable]');
        if (first) first.focus();
      });
      // En listas (ul/ol/tbody) el botón va después, para no meter un <button> entre los <li>
      if (/^(UL|OL|TBODY|TABLE|DL|SELECT)$/.test(container.tagName)) {
        container.parentNode.insertBefore(addBtn, container.nextSibling);
      } else {
        container.appendChild(addBtn);
      }
    }

    // --- blog ---

    // Elementos de la entrada (no los de los listados de la misma página)
    function postEls(selector) {
      return Array.prototype.filter.call(document.body.querySelectorAll(selector), function (el) { return !el.closest('[data-posts]'); });
    }

    function setupPost() {
      postEls('[data-post]').forEach(function (el) {
        if (el.getAttribute('data-post') === 'date') return; // la fecha va en la barra
        el.setAttribute('contenteditable', 'true');
        el.classList.add('neutron-editable');
      });
      postEls('[data-post-img]').forEach(function (img) {
        makeImageSwappable(img);
      });
      rich = postEls('[data-post-html]')[0] || null;
      if (rich) setupRich(rich);

      var box = document.createElement('span');
      box.className = 'neutron-post-tools';
      box.innerHTML =
        '<label>Fecha <input type="date"></label>' +
        '<button type="button" data-post-action="status"></button>' +
        '<button type="button" class="neutron-danger" data-post-action="delete">Borrar entrada</button>';
      bar.insertBefore(box, statusEl);
      var date = box.querySelector('input');
      date.value = post.date || '';
      date.addEventListener('change', markDirty);
      showStatus();
      if (post.translated === false) {
        statusEl.textContent = 'Sin traducir: se ve el texto del idioma principal. Lo que guardes aquí será la traducción.';
      }
      box.addEventListener('click', function (e) {
        var action = e.target.getAttribute('data-post-action');
        if (action === 'status') setStatus(post.status === 'published' ? 'draft' : 'published');
        if (action === 'delete') deletePost();
      });
    }

    function showStatus() {
      var b = bar.querySelector('[data-post-action="status"]');
      b.textContent = post.status === 'published' ? 'Pasar a borrador' : 'Publicar';
      b.title = post.status === 'published' ? 'Ahora está publicada' : 'Ahora es un borrador: solo la ves tú';
      document.body.classList.toggle('neutron-post-draft', post.status !== 'published');
    }

    function postRequest(payload) {
      payload.collection = payload.collection || post.collection;
      if (cfg.lang) payload.lang = cfg.lang;
      if (post && !payload.slug && payload.action !== 'create') payload.slug = post.slug;
      return fetch('admin/post.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json(); });
    }

    function collectPost() {
      var fields = {};
      postEls('[data-post]').forEach(function (el) {
        var f = el.getAttribute('data-post');
        if (f !== 'date' && !(f in fields)) fields[f] = el.textContent.trim();
      });
      var img = postEls('[data-post-img]')[0];
      if (img) fields[img.getAttribute('data-post-img')] = img.hasAttribute('data-post-empty') ? '' : (img.getAttribute('src') || '');
      if (rich) fields[rich.getAttribute('data-post-html')] = rich.innerHTML;
      fields.date = bar.querySelector('.neutron-post-tools input').value;
      return fields;
    }

    function setStatus(status) {
      var go = function () {
        statusEl.textContent = 'Guardando...';
        postRequest({ action: 'status', status: status }).then(function (data) {
          if (!data.ok) { statusEl.textContent = 'Error: ' + (data.error || ''); return; }
          post.status = data.status;
          showStatus();
          statusEl.textContent = data.status === 'published' ? 'Publicada ✓' : 'Ahora es un borrador';
        }).catch(function () { statusEl.textContent = 'Error de red'; });
      };
      // Publicar guarda antes los cambios pendientes
      if (dirty) doSave(go); else go();
    }

    function deletePost() {
      if (!confirm('¿Borrar esta entrada? Dejará de verse en la web.')) return;
      postRequest({ action: 'delete' }).then(function (data) {
        if (!data.ok) { statusEl.textContent = 'Error: ' + (data.error || ''); return; }
        dirty = false;
        goEditing(new URL(data.url, document.baseURI));
      }).catch(function () { statusEl.textContent = 'Error de red'; });
    }

    // Ventana del editor con un campo de texto. onSubmit(valor, fallo): llamar a fallo('mensaje') la deja abierta con el
    // error; si no, se cierra sola. Esc, Cancelar o un clic fuera la cierran sin hacer nada.
    function openDialog(opts, onSubmit) {
      var overlay = document.createElement('div');
      overlay.className = 'neutron-overlay';
      overlay.innerHTML =
        '<form class="neutron-login-box neutron-dialog" novalidate>' +
          '<h2></h2>' +
          '<label><span></span><input type="text" name="value" maxlength="' + (opts.maxLength || 500) + '"></label>' +
          '<p class="neutron-hint"></p>' +
          '<p class="neutron-error" hidden></p>' +
          '<div class="neutron-dialog-buttons">' +
            '<button type="button" class="neutron-secondary" data-dialog="cancel">Cancelar</button>' +
            '<button type="submit"></button>' +
          '</div>' +
        '</form>';
      var form = overlay.querySelector('form');
      var input = form.querySelector('input');
      var errorEl = form.querySelector('.neutron-error');
      var submit = form.querySelector('[type="submit"]');
      form.querySelector('h2').textContent = opts.title;
      form.querySelector('label span').textContent = opts.label;
      form.querySelector('.neutron-hint').textContent = opts.hint || '';
      submit.textContent = opts.button || 'Aceptar';
      input.value = opts.value || '';
      if (opts.placeholder) input.placeholder = opts.placeholder;
      document.body.appendChild(overlay);
      input.focus();
      input.select();

      function close() {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
      }
      function onKey(e) { if (e.key === 'Escape') close(); }
      document.addEventListener('keydown', onKey);
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
      form.querySelector('[data-dialog="cancel"]').addEventListener('click', close);

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var value = input.value.trim();
        if (opts.required && !value) { input.focus(); return; }
        submit.disabled = true;
        errorEl.hidden = true;
        var finished = false;
        onSubmit(value, function fail(message) {
          finished = true;
          errorEl.textContent = message;
          errorEl.hidden = false;
          submit.disabled = false;
          input.focus();
        }, function ok() { finished = true; close(); });
        // Si onSubmit no es asíncrono y no ha fallado, se cierra ya
        if (!opts.async && !finished) close();
      });
    }

    // «Nueva entrada»: ventana con el título (el error, si lo hay, sale en la misma ventana)
    function createPost(collection) {
      openDialog({ title: 'Nueva entrada', label: 'Título', maxLength: 200, required: true, async: true, button: 'Crear',
        hint: 'Se crea como borrador: después podrás escribirla y publicarla.' }, function (title, fail, ok) {
        postRequest({ action: 'create', collection: collection, title: title }).then(function (data) {
          if (!data.ok) throw new Error(data.error || 'No se pudo crear');
          ok();
          goEditing(new URL(data.url, document.baseURI));
        }).catch(function (err) {
          fail(err && err.message && err.message !== 'Failed to fetch' ? err.message : 'Error de red');
        });
      });
    }

    // Enlaces editables (data-edit-href): la dirección se cambia en una ventana; vacía, el enlace no sale en la web.
    // Un enlace sin texto editable (un icono) se edita con un clic; uno con texto editable, con el botón 🔗 de al lado.
    function setupHref(a) {
      a.classList.add('neutron-href');
      markHref(a);
      var hasText = !!a.querySelector('[contenteditable="true"]') || a.getAttribute('contenteditable') === 'true';
      if (hasText) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'neutron-href-btn';
        b.textContent = '🔗';
        b.title = 'Cambiar la dirección del enlace';
        b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); editHref(a); });
        a.insertAdjacentElement('afterend', b);
      } else {
        a.addEventListener('click', function (e) {
          if (e.ctrlKey || e.metaKey) return;
          e.preventDefault();
          e.stopImmediatePropagation();
          editHref(a);
        });
      }
    }

    function markHref(a) {
      var empty = (a.getAttribute('data-edit-href-value') || '') === '';
      a.classList.toggle('neutron-href-empty', empty);
      a.title = empty ? 'Sin enlace: no se ve en la web. Haz clic para ponerle una dirección.'
        : 'Enlace: ' + a.getAttribute('data-edit-href-value') + ' (clic para cambiarlo)';
    }

    function editHref(a) {
      var name = a.getAttribute('aria-label') || a.textContent.trim() || 'Enlace';
      openDialog({ title: 'Enlace: ' + name, label: 'Dirección', value: a.getAttribute('data-edit-href-value') || '',
        placeholder: 'https://…', button: 'Aceptar',
        hint: 'Una dirección completa (https://…), un correo (mailto:…), un teléfono (tel:…) o una página de la web. Déjala vacía para que el enlace no se vea en la web.'
      }, function (value, fail) {
        if (value !== '' && !hrefAllowed(value)) {
          fail('Dirección no válida: empieza por https://, mailto: o tel:, o es una página de la web.');
          return;
        }
        if (/^www\./i.test(value)) value = 'https://' + value;
        var key = a.getAttribute('data-edit-href');
        document.querySelectorAll('[data-edit-href]').forEach(function (other) {
          if (other.getAttribute('data-edit-href') !== key) return;
          other.setAttribute('data-edit-href-value', value);
          if (value) other.setAttribute('href', value); else other.setAttribute('href', '#');
          markHref(other);
        });
        markDirty();
      });
    }

    // Igual que neutron_sanitize_href() en lib/sanitize.php
    function hrefAllowed(href) {
      var probe = href.replace(/[\x00-\x20]+/g, '');
      if (probe.indexOf('//') === 0) return false;
      var m = /^([a-z][a-z0-9+.-]*):/i.exec(probe);
      return !m || ['http', 'https', 'mailto', 'tel'].indexOf(m[1].toLowerCase()) !== -1;
    }

    // Texto con formato: barra con los formatos que admite el servidor (lib/sanitize.php)
    function setupRich(el) {
      el.setAttribute('contenteditable', 'true');
      el.classList.add('neutron-editable', 'neutron-rich');
      try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
      var tools = document.createElement('div');
      tools.className = 'neutron-rich-tools';
      [['bold', '<b>N</b>', 'Negrita'], ['italic', '<i>C</i>', 'Cursiva'], ['h2', 'Título', 'Subtítulo'], ['h3', 'Subtít.', 'Subtítulo pequeño'],
       ['p', '¶', 'Párrafo normal'], ['ul', '• Lista', 'Lista'], ['ol', '1. Lista', 'Lista numerada'], ['quote', '❝', 'Cita'],
       ['link', 'Enlace', 'Enlace'], ['unlink', 'Quitar enlace', 'Quitar enlace'], ['image', 'Imagen', 'Insertar imagen']
      ].forEach(function (t) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('data-rich', t[0]);
        b.innerHTML = t[1];
        b.title = t[2];
        tools.appendChild(b);
      });
      el.parentNode.insertBefore(tools, el);
      // mousedown: sin perder la selección del texto
      tools.addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); });
      tools.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        el.focus();
        var cmd = b.getAttribute('data-rich');
        if (cmd === 'bold' || cmd === 'italic') document.execCommand(cmd);
        else if (cmd === 'h2' || cmd === 'h3' || cmd === 'p') document.execCommand('formatBlock', false, cmd);
        else if (cmd === 'quote') document.execCommand('formatBlock', false, 'blockquote');
        else if (cmd === 'ul') document.execCommand('insertUnorderedList');
        else if (cmd === 'ol') document.execCommand('insertOrderedList');
        else if (cmd === 'unlink') document.execCommand('unlink');
        else if (cmd === 'link') {
          var url = prompt('Dirección del enlace (https://…, mailto:… o una página de la web)');
          if (url) document.execCommand('createLink', false, url.trim());
        } else if (cmd === 'image') insertImage(el);
        markDirty();
      });
      // Al pegar: solo el formato permitido (el servidor vuelve a limpiarlo al guardar)
      el.addEventListener('paste', function (e) {
        e.preventDefault();
        var html = e.clipboardData.getData('text/html');
        var text = e.clipboardData.getData('text/plain');
        document.execCommand('insertHTML', false, html ? cleanHtml(html) : textToHtml(text));
        markDirty();
      });
    }

    function insertImage(el) {
      var range = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null;
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/png,image/jpeg,image/gif,image/webp';
      input.addEventListener('change', function () {
        if (!input.files || !input.files[0]) return;
        var alt = prompt('Describe la imagen en pocas palabras (para quien no la ve)') || '';
        upload(input.files[0], function (url) {
          el.focus();
          if (range) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
          document.execCommand('insertHTML', false, '<img src="' + escapeAttr(url) + '" alt="' + escapeAttr(alt) + '">');
          markDirty();
        });
      });
      input.click();
    }

    // Misma lista blanca que lib/sanitize.php (versión simple para que lo pegado se vea ya limpio)
    function cleanHtml(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var allowed = { P: 'p', H1: 'h2', H2: 'h2', H3: 'h3', H4: 'h3', STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', A: 'a', UL: 'ul', OL: 'ol', LI: 'li', BLOCKQUOTE: 'blockquote', BR: 'br', DIV: 'p' };
      var drop = /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|SVG|MATH|TEMPLATE|NOSCRIPT|META|LINK|TITLE|HEAD|FORM|INPUT|BUTTON|SELECT|TEXTAREA|IMG|VIDEO|AUDIO)$/;
      function walk(node) {
        var out = '';
        node.childNodes.forEach(function (n) {
          if (n.nodeType === 3) { out += escapeText(n.textContent); return; }
          if (n.nodeType !== 1 || drop.test(n.tagName)) return;
          var inner = walk(n);
          var tag = allowed[n.tagName];
          if (!tag) { out += inner; return; }
          if (tag === 'br') { out += '<br>'; return; }
          if (tag === 'a') {
            var href = (n.getAttribute('href') || '').trim();
            out += /^(https?:|mailto:|tel:)/i.test(href) ? '<a href="' + escapeAttr(href) + '">' + inner + '</a>' : inner;
            return;
          }
          out += '<' + tag + '>' + inner + '</' + tag + '>';
        });
        return out;
      }
      return walk(doc.body);
    }

    function textToHtml(text) {
      return text.split(/\r?\n\s*\n/).map(function (p) { return '<p>' + escapeText(p).replace(/\r?\n/g, '<br>') + '</p>'; }).join('');
    }
    function escapeText(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function escapeAttr(s) { return escapeText(s).replace(/"/g, '&quot;'); }

    function makeImageSwappable(img) {
      img.classList.add('neutron-editable-img');
      img.addEventListener('click', function () {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/png,image/jpeg,image/gif,image/webp';
        input.addEventListener('change', function () {
          if (!input.files || !input.files[0]) return;
          uploadImage(input.files[0], img);
        });
        input.click();
      });
    }

    function uploadImage(file, imgEl) {
      upload(file, function (url) {
        imgEl.setAttribute('src', url);
        imgEl.removeAttribute('data-post-empty');
        markDirty();
      });
    }

    function upload(file, done) {
      var fd = new FormData();
      fd.append('image', file);
      statusEl.textContent = 'Subiendo imagen...';
      fetch('admin/upload.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrf },
        body: fd
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            done(data.url);
            statusEl.textContent = 'Imagen lista (recuerda guardar)';
          } else {
            statusEl.textContent = 'Error: ' + (data.error || 'no se pudo subir');
          }
        })
        .catch(function () {
          statusEl.textContent = 'Error de red al subir';
        });
    }

    function collectPayload() {
      var fields = {};
      document.querySelectorAll('[data-edit]').forEach(function (el) {
        fields[el.getAttribute('data-edit')] = el.textContent.trim();
      });

      // Direcciones de los enlaces editables (vacía = el enlace no sale)
      document.querySelectorAll('[data-edit-href]').forEach(function (el) {
        fields[el.getAttribute('data-edit-href')] = el.getAttribute('data-edit-href-value') || '';
      });

      // Las imágenes van aparte: en una web de varios idiomas son comunes a todos
      var images = {};
      document.querySelectorAll('[data-edit-img]').forEach(function (el) {
        images[el.getAttribute('data-edit-img')] = el.getAttribute('src');
      });

      var collections = {};
      topCollections().forEach(function (container) {
        collections[container.getAttribute('data-edit-collection')] = collectItems(container);
      });

      return { fields: fields, images: images, collections: collections, lang: cfg.lang || null };
    }

    function collectItems(container) {
      return ownItems(container).map(function (item) {
        var data = {};
        own(item, '[data-edit-field]').forEach(function (field) {
          var fkey = field.getAttribute('data-edit-field');
          data[fkey] = field.tagName.toLowerCase() === 'img' ? (field.getAttribute('src') || '') : field.textContent.trim();
        });
        own(item, '[data-edit-collection]').forEach(function (sub) {
          data[sub.getAttribute('data-edit-collection')] = collectItems(sub);
        });
        return data;
      });
    }

    // Guarda los textos de la página y, en la página de una entrada, la entrada. then: al acabar bien.
    function doSave(then) {
      statusEl.textContent = 'Guardando...';
      var requests = [fetch('admin/save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body: JSON.stringify(collectPayload())
      }).then(function (r) { return r.json(); })];
      if (post) requests.push(postRequest({ action: 'save', fields: collectPost() }));
      Promise.all(requests)
        .then(function (results) {
          var failed = results.filter(function (d) { return !d.ok; })[0];
          if (!failed) dirty = false;
          statusEl.textContent = failed ? 'Error: ' + (failed.error || '') : 'Guardado ✓';
          if (!failed && typeof then === 'function') then();
        })
        .catch(function () {
          statusEl.textContent = 'Error de red al guardar';
        });
    }

    function exitAdminMode() {
      if (dirty && !confirm('Hay cambios sin guardar. ¿Salir sin guardarlos?')) return;
      dirty = false;
      var url = new URL(location.href);
      url.searchParams.delete('admin');
      location.href = url.toString();
    }

    function doLogout() {
      if (dirty && !confirm('Hay cambios sin guardar. ¿Cerrar sesión sin guardarlos?')) return;
      dirty = false;
      fetch('admin/logout.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrf }
      }).then(function () {
        location.reload();
      });
    }
  }
})();
