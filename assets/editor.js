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
        if (!goEditing(new URL(select.value, location.href))) select.value = cfg.current;
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

    function createPost(collection) {
      var title = prompt('Título de la nueva entrada');
      if (!title || !title.trim()) return;
      postRequest({ action: 'create', collection: collection, title: title.trim() }).then(function (data) {
        if (!data.ok) { statusEl.textContent = 'Error: ' + (data.error || ''); return; }
        goEditing(new URL(data.url, document.baseURI));
      }).catch(function () { statusEl.textContent = 'Error de red'; });
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

      document.querySelectorAll('[data-edit-img]').forEach(function (el) {
        fields[el.getAttribute('data-edit-img')] = el.getAttribute('src');
      });

      var collections = {};
      topCollections().forEach(function (container) {
        collections[container.getAttribute('data-edit-collection')] = collectItems(container);
      });

      return { fields: fields, collections: collections };
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
